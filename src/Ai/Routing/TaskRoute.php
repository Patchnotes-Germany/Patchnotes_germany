<?php

declare(strict_types=1);

namespace App\Ai\Routing;

use App\Ai\Enum\AiTask;
use App\Ai\Value\ModelReference;

/**
 * The models a task may use, in the order they are tried (SPEC.md § 8.2).
 */
final readonly class TaskRoute
{
    /**
     * @param list<ModelReference> $chain
     */
    public function __construct(
        public AiTask $task,
        public array $chain,
        public float $temperature,
        /** "bulk_only" when the task may use a batch API (SPEC.md § 24.14). */
        public ?string $batch = null,
        /**
         * True when a task that wanted a second opinion from a different provider could not get
         * one. The pipeline then caps the verification score at
         * ai.max_verify_score_single_provider (SPEC.md § 24.14).
         */
        public bool $sameProviderFallback = false,
        /** Set when the budget stops this task entirely rather than merely degrading it. */
        public bool $pausedByBudget = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->chain;
    }

    public function allowsBatch(): bool
    {
        return 'bulk_only' === $this->batch;
    }

    public function first(): ?ModelReference
    {
        return $this->chain[0] ?? null;
    }
}
