<?php

declare(strict_types=1);

namespace App\Ai\Value;

use App\Ai\Enum\BatchState;

/**
 * Progress of a batch as reported by the provider (SPEC.md § 24.14).
 */
final readonly class BatchStatus
{
    public function __construct(
        public BatchState $state,
        public int $completed = 0,
        public int $failed = 0,
        public int $total = 0,
        public ?string $error = null,
    ) {
    }

    public function isFinished(): bool
    {
        return $this->state->isFinished();
    }

    public function isSuccessful(): bool
    {
        return BatchState::Completed === $this->state;
    }
}
