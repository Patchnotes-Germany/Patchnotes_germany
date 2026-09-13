<?php

declare(strict_types=1);

namespace App\Ai\Client;

use App\Ai\Enum\BatchState;
use App\Ai\Exception\LlmException;
use App\Ai\Value\BatchHandle;
use App\Ai\Value\BatchStatus;
use App\Ai\Value\LlmRequest;
use App\Ai\Value\LlmResponse;
use App\Ai\Value\LlmUsage;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Anthropic Messages API (SPEC.md § 8.1).
 *
 * Two details differ from the OpenAI dialect and both matter here: JSON comes back through a forced
 * tool call rather than a response format, and the long system prompts of this project (style
 * guides, glossaries) are marked for prompt caching, which is what keeps the translation tasks
 * affordable.
 */
final readonly class AnthropicClient implements LlmClientInterface
{
    private const string API_VERSION = '2023-06-01';
    private const string JSON_TOOL = 'respond';
    private const int DEFAULT_MAX_TOKENS = 8192;

    public function __construct(
        private string $providerName,
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        private ?string $apiKey,
        private int $timeoutSeconds,
        private LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $model = $request->boundModel();
        $startedAt = microtime(true);

        $data = $this->request('POST', '/messages', ['json' => $this->payload($request, $model->model)]);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        return new LlmResponse(
            $this->content($data, $request->expectsJson()),
            (string) $model,
            $this->usage(\is_array($data['usage'] ?? null) ? $data['usage'] : []),
            $durationMs,
            false,
            \is_string($data['stop_reason'] ?? null) ? $data['stop_reason'] : null,
        );
    }

    public function supportsJsonSchema(): bool
    {
        return true;
    }

    public function supportsBatch(): bool
    {
        return true;
    }

    public function submitBatch(array $requests): BatchHandle
    {
        if ([] === $requests) {
            throw LlmException::misconfigured($this->providerName, 'a batch needs at least one request');
        }

        $entries = [];
        foreach ($requests as $index => $request) {
            $entries[] = [
                'custom_id' => '' !== $request->id ? $request->id : 'request-'.$index,
                'params' => $this->payload($request, $request->boundModel()->model),
            ];
        }

        $batch = $this->request('POST', '/messages/batches', ['json' => ['requests' => $entries]]);

        return new BatchHandle(
            $this->providerName,
            (string) ($batch['id'] ?? ''),
            new \DateTimeImmutable(),
            \count($requests),
        );
    }

    public function pollBatch(BatchHandle $handle): BatchStatus
    {
        $batch = $this->request('GET', '/messages/batches/'.rawurlencode($handle->id), []);

        /** @var array<string, mixed> $counts */
        $counts = \is_array($batch['request_counts'] ?? null) ? $batch['request_counts'] : [];
        $succeeded = (int) ($counts['succeeded'] ?? 0);
        $errored = (int) ($counts['errored'] ?? 0) + (int) ($counts['expired'] ?? 0) + (int) ($counts['canceled'] ?? 0);

        $state = 'ended' === ($batch['processing_status'] ?? null) ? BatchState::Completed : BatchState::InProgress;

        return new BatchStatus(
            $state,
            $succeeded,
            $errored,
            $succeeded + $errored + (int) ($counts['processing'] ?? 0),
        );
    }

    public function fetchBatchResults(BatchHandle $handle): array
    {
        $batch = $this->request('GET', '/messages/batches/'.rawurlencode($handle->id), []);
        $resultsUrl = $batch['results_url'] ?? null;

        if (!\is_string($resultsUrl) || '' === $resultsUrl) {
            return [];
        }

        $results = [];

        foreach (explode("\n", $this->fetchRaw($resultsUrl)) as $line) {
            if ('' === trim($line)) {
                continue;
            }

            try {
                /** @var array<string, mixed> $entry */
                $entry = (array) json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            $customId = (string) ($entry['custom_id'] ?? '');
            /** @var array<string, mixed> $result */
            $result = \is_array($entry['result'] ?? null) ? $entry['result'] : [];

            if ('' === $customId || 'succeeded' !== ($result['type'] ?? null)) {
                continue;
            }

            /** @var array<string, mixed> $message */
            $message = \is_array($result['message'] ?? null) ? $result['message'] : [];

            $results[$customId] = new LlmResponse(
                // A batch answer may or may not be a tool call; both shapes are handled.
                $this->content($message, true),
                $this->providerName.':'.($message['model'] ?? ''),
                $this->usage(\is_array($message['usage'] ?? null) ? $message['usage'] : []),
            );
        }

        return $results;
    }

    public function ping(string $model): bool
    {
        try {
            $data = $this->request('GET', '/models/'.rawurlencode($model), []);
        } catch (LlmException $exception) {
            $this->logger->warning('AI provider did not answer a ping', [
                'provider' => $this->providerName,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        return ($data['id'] ?? null) === $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(LlmRequest $request, string $model): array
    {
        $payload = [
            'model' => $model,
            'max_tokens' => $request->maxTokens ?? self::DEFAULT_MAX_TOKENS,
            'temperature' => $request->temperature,
            // Prompt caching pays off exactly here: the same rules, style guide and glossary are
            // sent with every card and every translation.
            'system' => [[
                'type' => 'text',
                'text' => $request->systemPrompt,
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'messages' => [[
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => $request->userPrompt]],
            ]],
        ];

        if ([] !== $request->stop) {
            $payload['stop_sequences'] = $request->stop;
        }

        if ($request->expectsJson()) {
            // Forcing a tool call is how this API guarantees a shape; the arguments are the answer.
            $payload['tools'] = [[
                'name' => self::JSON_TOOL,
                'description' => 'Return the answer for '.$request->task->value.'.',
                'input_schema' => $request->jsonSchema,
            ]];
            $payload['tool_choice'] = ['type' => 'tool', 'name' => self::JSON_TOOL];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function content(array $message, bool $expectsJson): string
    {
        /** @var array<int, array<string, mixed>> $blocks */
        $blocks = \is_array($message['content'] ?? null) ? $message['content'] : [];

        foreach ($blocks as $block) {
            if ($expectsJson && 'tool_use' === ($block['type'] ?? null) && \is_array($block['input'] ?? null)) {
                return json_encode($block['input'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '';
            }
        }

        $text = '';
        foreach ($blocks as $block) {
            if ('text' === ($block['type'] ?? null) && \is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $usage
     */
    private function usage(array $usage): LlmUsage
    {
        return new LlmUsage(
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
            (int) ($usage['cache_read_input_tokens'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options): array
    {
        $body = $this->fetchRaw(rtrim($this->baseUrl, '/').$path, $method, $options);

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = (array) json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw LlmException::http($this->providerName, 200, 'the answer was not JSON: '.$exception->getMessage());
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function fetchRaw(string $url, string $method = 'GET', array $options = []): string
    {
        if (null === $this->apiKey || '' === $this->apiKey) {
            throw LlmException::misconfigured($this->providerName, 'no API key is set');
        }

        try {
            $response = $this->httpClient->request($method, $url, $options + [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => $this->timeoutSeconds,
                'max_duration' => $this->timeoutSeconds + 60,
            ]);

            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (HttpExceptionInterface $exception) {
            throw LlmException::transport($this->providerName, $exception);
        }

        if ($status >= 400) {
            throw LlmException::http($this->providerName, $status, $body);
        }

        return $body;
    }
}
