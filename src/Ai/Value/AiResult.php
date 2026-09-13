<?php

declare(strict_types=1);

namespace App\Ai\Value;

use App\Ai\Enum\AiResultStatus;
use App\Ai\Enum\AiTask;

/**
 * The outcome of an AI task (SPEC.md § 8.2, § 8.3).
 *
 * A result is either an answer or a queued job: tasks routed to the operator's own computer cannot
 * block a pipeline stage, so the caller gets a job id and is woken up later.
 */
final readonly class AiResult
{
    /**
     * @param array<string, mixed>|null $data the answer decoded and validated against the schema
     */
    public function __construct(
        public AiTask $task,
        public AiResultStatus $status,
        public string $content = '',
        public ?array $data = null,
        public ?ModelReference $model = null,
        public LlmUsage $usage = new LlmUsage(),
        public bool $fromCache = false,
        public int $durationMs = 0,
        public ?int $jobId = null,
        public int $promptVersion = 1,
    ) {
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function completed(
        AiTask $task,
        LlmResponse $response,
        ModelReference $model,
        ?array $data,
        int $promptVersion,
    ): self {
        return new self(
            $task,
            AiResultStatus::Completed,
            $response->content,
            $data,
            $model,
            $response->usage,
            $response->fromCache,
            $response->durationMs,
            null,
            $promptVersion,
        );
    }

    public static function queued(AiTask $task, int $jobId, ModelReference $model, int $promptVersion): self
    {
        return new self($task, AiResultStatus::Queued, '', null, $model, new LlmUsage(), false, 0, $jobId, $promptVersion);
    }

    public function isQueued(): bool
    {
        return AiResultStatus::Queued === $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function requireData(): array
    {
        return $this->data ?? throw new \LogicException(\sprintf('The answer for task "%s" carries no structured data.', $this->task->value));
    }
}
