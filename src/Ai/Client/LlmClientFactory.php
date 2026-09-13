<?php

declare(strict_types=1);

namespace App\Ai\Client;

use App\Ai\Enum\AiProviderType;
use App\Ai\Exception\LlmException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Turns one entry of patchnotes.ai.providers into a client (SPEC.md § 8.1).
 *
 * This is the only place that knows which class speaks which dialect; everything else works with
 * provider aliases from the configuration.
 */
final readonly class LlmClientFactory
{
    private const string OPENAI_DEFAULT_URL = 'https://api.openai.com/v1';
    private const string ANTHROPIC_DEFAULT_URL = 'https://api.anthropic.com/v1';
    private const int DEFAULT_TIMEOUT_SECONDS = 300;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $settings the provider entry from the configuration
     */
    public function create(string $alias, array $settings): LlmClientInterface
    {
        $type = $this->type($alias, $settings);
        $apiKey = $this->string($settings['api_key'] ?? null);
        $baseUrl = $this->string($settings['base_url'] ?? null);
        $timeout = isset($settings['timeout']) && is_numeric($settings['timeout'])
            ? (int) $settings['timeout']
            : self::DEFAULT_TIMEOUT_SECONDS;

        return match ($type) {
            AiProviderType::OpenAi => new OpenAiClient(
                $alias,
                $this->httpClient,
                $baseUrl ?? self::OPENAI_DEFAULT_URL,
                $apiKey,
                $timeout,
                $this->logger,
            ),
            AiProviderType::Anthropic => new AnthropicClient(
                $alias,
                $this->httpClient,
                $baseUrl ?? self::ANTHROPIC_DEFAULT_URL,
                $apiKey,
                $timeout,
                $this->logger,
            ),
            AiProviderType::OpenAiCompatible => new OpenAiCompatibleClient(
                $alias,
                $this->httpClient,
                $baseUrl ?? throw LlmException::misconfigured($alias, 'an OpenAI-compatible provider needs a base_url'),
                $apiKey,
                $timeout,
                $this->logger,
                filter_var($settings['supports_json_schema'] ?? false, \FILTER_VALIDATE_BOOL),
            ),
            AiProviderType::Fake => new FakeLlmClient($alias),
        };
    }

    /**
     * Whether the provider has everything it needs to be called at all. A provider without a key is
     * not an error — the operator simply has not signed up for it — but it must be skipped silently
     * instead of failing a task.
     *
     * @param array<string, mixed> $settings
     */
    public function isUsable(string $alias, array $settings): bool
    {
        return match ($this->type($alias, $settings)) {
            AiProviderType::OpenAi, AiProviderType::Anthropic => null !== $this->string($settings['api_key'] ?? null),
            AiProviderType::OpenAiCompatible => null !== $this->string($settings['base_url'] ?? null),
            AiProviderType::Fake => true,
        };
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function type(string $alias, array $settings): AiProviderType
    {
        $type = $this->string($settings['type'] ?? null)
            ?? throw LlmException::misconfigured($alias, 'no provider type is set');

        return AiProviderType::tryFrom($type)
            ?? throw LlmException::misconfigured($alias, \sprintf('unknown provider type "%s"', $type));
    }

    private function string(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
