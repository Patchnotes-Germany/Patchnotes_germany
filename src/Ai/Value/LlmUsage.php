<?php

declare(strict_types=1);

namespace App\Ai\Value;

/**
 * Token usage of one call — the basis of the cost accounting and the monthly budget
 * (SPEC.md § 8.2).
 */
final readonly class LlmUsage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        /** Tokens served from the provider's prompt cache; they are billed differently. */
        public int $cachedInputTokens = 0,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public function total(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
