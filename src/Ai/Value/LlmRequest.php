<?php

declare(strict_types=1);

namespace App\Ai\Value;

use App\Ai\Enum\AiTask;

/**
 * One request to a model (SPEC.md § 8.1).
 *
 * The system prompt and the user prompt are kept apart because the rules of § 8.5 live in the
 * system prompt — above all that the input is *data, never instructions* (prompt injection is a
 * real risk when the input is a law text or a community pull request).
 *
 * The model is not part of the request when it is built: the router attaches it per attempt, so the
 * same request can travel down a fallback chain unchanged.
 */
final readonly class LlmRequest
{
    /**
     * @param array<string, mixed>|null $jsonSchema schema the answer must satisfy (§ 8.4)
     * @param list<string>              $stop
     */
    public function __construct(
        public AiTask $task,
        public string $systemPrompt,
        public string $userPrompt,
        public float $temperature = 0.0,
        public ?int $maxTokens = null,
        public ?array $jsonSchema = null,
        public array $stop = [],
        public int $promptVersion = 1,
        /** Identifies the request inside a batch and in the job table. */
        public string $id = '',
        /** What the answer belongs to, e.g. "change:2026-bund-bgbl-i-221". */
        public ?string $subject = null,
        /** Attached by the router before the call. */
        public ?ModelReference $model = null,
    ) {
    }

    public function withId(string $id): self
    {
        return $this->with(id: $id);
    }

    public function withModel(ModelReference $model): self
    {
        return $this->with(model: $model);
    }

    /**
     * Used by the repair loop: the same call, plus what was wrong with the previous answer.
     */
    public function withUserPrompt(string $userPrompt): self
    {
        return $this->with(userPrompt: $userPrompt);
    }

    public function expectsJson(): bool
    {
        return null !== $this->jsonSchema;
    }

    public function boundModel(): ModelReference
    {
        return $this->model ?? throw new \LogicException('No model was attached to this request; the router must do that.');
    }

    /**
     * Cache key of SPEC.md § 8.2: task, prompt version, schema, model, temperature and input.
     */
    public function fingerprint(?ModelReference $model = null): string
    {
        $model ??= $this->model;

        return hash('sha256', implode("\0", [
            $this->task->value,
            (string) $this->promptVersion,
            (string) $model,
            (string) $this->temperature,
            json_encode($this->jsonSchema) ?: 'null',
            $this->systemPrompt,
            $this->userPrompt,
        ]));
    }

    /**
     * The request as stored in an AiJob row, so a remote worker can execute it (SPEC.md § 8.3).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'task' => $this->task->value,
            'system' => $this->systemPrompt,
            'user' => $this->userPrompt,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'json_schema' => $this->jsonSchema,
            'stop' => $this->stop,
            'prompt_version' => $this->promptVersion,
            'id' => $this->id,
            'subject' => $this->subject,
            'model' => $this->model instanceof ModelReference ? (string) $this->model : null,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        /** @var array<string, mixed>|null $schema */
        $schema = \is_array($payload['json_schema'] ?? null) ? $payload['json_schema'] : null;
        /** @var list<string> $stop */
        $stop = \is_array($payload['stop'] ?? null) ? array_values(array_filter($payload['stop'], is_string(...))) : [];
        $model = \is_string($payload['model'] ?? null) && '' !== $payload['model']
            ? ModelReference::parse($payload['model'])
            : null;

        return new self(
            AiTask::from((string) ($payload['task'] ?? '')),
            (string) ($payload['system'] ?? ''),
            (string) ($payload['user'] ?? ''),
            (float) ($payload['temperature'] ?? 0.0),
            isset($payload['max_tokens']) && is_numeric($payload['max_tokens']) ? (int) $payload['max_tokens'] : null,
            $schema,
            $stop,
            isset($payload['prompt_version']) && is_numeric($payload['prompt_version']) ? (int) $payload['prompt_version'] : 1,
            (string) ($payload['id'] ?? ''),
            \is_string($payload['subject'] ?? null) ? $payload['subject'] : null,
            $model,
        );
    }

    /**
     * @param array<string, mixed>|null $jsonSchema
     * @param list<string>|null         $stop
     */
    private function with(
        ?AiTask $task = null,
        ?string $systemPrompt = null,
        ?string $userPrompt = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?array $jsonSchema = null,
        ?array $stop = null,
        ?int $promptVersion = null,
        ?string $id = null,
        ?string $subject = null,
        ?ModelReference $model = null,
    ): self {
        return new self(
            $task ?? $this->task,
            $systemPrompt ?? $this->systemPrompt,
            $userPrompt ?? $this->userPrompt,
            $temperature ?? $this->temperature,
            $maxTokens ?? $this->maxTokens,
            $jsonSchema ?? $this->jsonSchema,
            $stop ?? $this->stop,
            $promptVersion ?? $this->promptVersion,
            $id ?? $this->id,
            $subject ?? $this->subject,
            $model ?? $this->model,
        );
    }
}
