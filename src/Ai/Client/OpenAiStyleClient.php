<?php

declare(strict_types=1);

namespace App\Ai\Client;

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
 * Everything that speaks the OpenAI chat-completions dialect (SPEC.md § 8.1).
 *
 * OpenAI itself, and equally Ollama, LM Studio, vLLM, llama.cpp and LocalAI: the wire format is the
 * same, only the extras differ — structured outputs and the batch API. Those are decided by the
 * subclasses, which is why a local server needs no special case anywhere above this class.
 */
abstract class OpenAiStyleClient implements LlmClientInterface
{
    public function __construct(
        protected readonly string $providerName,
        protected readonly HttpClientInterface $httpClient,
        protected readonly string $baseUrl,
        protected readonly ?string $apiKey,
        /** Generous by default: a local model on a laptop may think for minutes. */
        protected readonly int $timeoutSeconds,
        protected readonly LoggerInterface $logger,
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

        $payload = $this->completionPayload($request, $model->model);
        $data = $this->post('/chat/completions', $payload);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        /** @var array<int, array<string, mixed>> $choices */
        $choices = \is_array($data['choices'] ?? null) ? $data['choices'] : [];
        $choice = $choices[0] ?? throw LlmException::http($this->providerName, 200, 'the answer contained no choices');

        /** @var array<string, mixed> $message */
        $message = \is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $content = \is_string($message['content'] ?? null) ? $message['content'] : '';

        return new LlmResponse(
            $content,
            (string) $model,
            $this->usage(\is_array($data['usage'] ?? null) ? $data['usage'] : []),
            $durationMs,
            false,
            \is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : null,
        );
    }

    public function supportsBatch(): bool
    {
        return false;
    }

    public function submitBatch(array $requests): BatchHandle
    {
        throw LlmException::unsupported($this->providerName, 'batch requests');
    }

    public function pollBatch(BatchHandle $handle): BatchStatus
    {
        throw LlmException::unsupported($this->providerName, 'batch requests');
    }

    public function fetchBatchResults(BatchHandle $handle): array
    {
        throw LlmException::unsupported($this->providerName, 'batch requests');
    }

    /**
     * Asking the server which models it serves is free and proves both reachability and that the
     * model id is spelled the way this server spells it.
     */
    public function ping(string $model): bool
    {
        try {
            $data = $this->get('/models');
        } catch (LlmException $exception) {
            $this->logger->warning('AI provider did not answer a ping', [
                'provider' => $this->providerName,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        /** @var array<int, array<string, mixed>> $models */
        $models = \is_array($data['data'] ?? null) ? $data['data'] : [];

        foreach ($models as $entry) {
            if (($entry['id'] ?? null) === $model) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function completionPayload(LlmRequest $request, string $model): array
    {
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $request->systemPrompt],
                ['role' => 'user', 'content' => $request->userPrompt],
            ],
            'temperature' => $request->temperature,
        ];

        if (null !== $request->maxTokens) {
            $payload[$this->maxTokensField()] = $request->maxTokens;
        }

        if ([] !== $request->stop) {
            $payload['stop'] = $request->stop;
        }

        if ($request->expectsJson()) {
            $payload += $this->responseFormat($request);
        }

        return $payload;
    }

    /**
     * How the provider is asked for JSON. With schema support the server enforces the shape; without
     * it we can at least ask for valid JSON and let the gateway validate and repair.
     *
     * @return array<string, mixed>
     */
    protected function responseFormat(LlmRequest $request): array
    {
        if (!$this->supportsJsonSchema()) {
            return ['response_format' => ['type' => 'json_object']];
        }

        return [
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $request->task->value,
                    'strict' => true,
                    'schema' => $request->jsonSchema,
                ],
            ],
        ];
    }

    /**
     * OpenAI renamed the field for the reasoning models; local servers still use the old one.
     */
    protected function maxTokensField(): string
    {
        return 'max_tokens';
    }

    /**
     * @param array<string, mixed> $usage
     */
    protected function usage(array $usage): LlmUsage
    {
        /** @var array<string, mixed> $details */
        $details = \is_array($usage['prompt_tokens_details'] ?? null) ? $usage['prompt_tokens_details'] : [];

        return new LlmUsage(
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
            (int) ($details['cached_tokens'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    protected function post(string $path, array $payload): array
    {
        return $this->request('POST', $path, ['json' => $payload]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function get(string $path): array
    {
        return $this->request('GET', $path, []);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, array $options): array
    {
        $url = rtrim($this->baseUrl, '/').$path;

        try {
            $response = $this->httpClient->request($method, $url, $options + [
                'headers' => $this->headers(),
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

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = (array) json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw LlmException::http($this->providerName, $status, 'the answer was not JSON: '.$exception->getMessage());
        }

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        $headers = ['Content-Type' => 'application/json'];

        // Local servers usually need no key at all; sending an empty bearer confuses some of them.
        if (null !== $this->apiKey && '' !== $this->apiKey) {
            $headers['Authorization'] = 'Bearer '.$this->apiKey;
        }

        return $headers;
    }
}
