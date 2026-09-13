<?php

declare(strict_types=1);

namespace App\Ai\Cache;

use App\Ai\Value\LlmResponse;
use App\Ai\Value\LlmUsage;
use App\Core\Config\PatchnotesConfig;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Answers already paid for (SPEC.md § 8.2).
 *
 * The key is the request fingerprint — task, prompt version, schema, model, temperature and input —
 * so re-running a pipeline stage after a crash, or asking for the same translation twice, costs
 * nothing. Changing a prompt or a schema changes the key, which is the point of versioning them.
 */
final readonly class ResponseCache
{
    public function __construct(
        #[Autowire(service: 'cache.ai_response')]
        private CacheItemPoolInterface $pool,
        private PatchnotesConfig $config,
    ) {
    }

    public function isEnabled(): bool
    {
        return filter_var($this->config->ai()['cache'] ?? true, \FILTER_VALIDATE_BOOL);
    }

    public function get(string $fingerprint): ?LlmResponse
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $item = $this->pool->getItem($this->key($fingerprint));

        if (!$item->isHit()) {
            return null;
        }

        $stored = $item->get();

        if (!\is_array($stored)) {
            return null;
        }

        /* @var array<string, mixed> $stored */
        return new LlmResponse(
            (string) ($stored['content'] ?? ''),
            (string) ($stored['model'] ?? ''),
            new LlmUsage(
                (int) ($stored['input_tokens'] ?? 0),
                (int) ($stored['output_tokens'] ?? 0),
                (int) ($stored['cached_input_tokens'] ?? 0),
            ),
            (int) ($stored['duration_ms'] ?? 0),
            true,
            \is_string($stored['finish_reason'] ?? null) ? $stored['finish_reason'] : null,
        );
    }

    public function put(string $fingerprint, LlmResponse $response): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $item = $this->pool->getItem($this->key($fingerprint));
        $item->set([
            'content' => $response->content,
            'model' => $response->model,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
            'cached_input_tokens' => $response->usage->cachedInputTokens,
            'duration_ms' => $response->durationMs,
            'finish_reason' => $response->finishReason,
        ]);

        $this->pool->save($item);
    }

    private function key(string $fingerprint): string
    {
        return 'ai.'.$fingerprint;
    }
}
