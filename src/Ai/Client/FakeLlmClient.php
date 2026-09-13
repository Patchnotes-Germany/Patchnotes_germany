<?php

declare(strict_types=1);

namespace App\Ai\Client;

use App\Ai\Enum\AiTask;
use App\Ai\Enum\BatchState;
use App\Ai\Exception\LlmException;
use App\Ai\Value\BatchHandle;
use App\Ai\Value\BatchStatus;
use App\Ai\Value\LlmRequest;
use App\Ai\Value\LlmResponse;
use App\Ai\Value\LlmUsage;

/**
 * A provider that answers from a script instead of a network (SPEC.md § 19, § 18.2).
 *
 * The test suite must never depend on a model: answers have to be identical on every run, and
 * `make demo` has to work without an API key and without a GPU. Failures and malformed JSON can be
 * queued deliberately, because the fallback chain and the repair loop are the parts most worth
 * testing.
 */
final class FakeLlmClient implements LlmClientInterface
{
    /** @var array<string, list<string>> task => queued answers */
    private array $answers = [];

    /** @var array<string, list<\Throwable>> task => queued failures */
    private array $failures = [];

    /** @var list<LlmRequest> */
    private array $calls = [];

    /** @var array<string, array<string, LlmResponse>> */
    private array $batches = [];

    public function __construct(
        private readonly string $providerName = 'fake',
        private readonly bool $jsonSchemaSupported = true,
        private readonly bool $batchSupported = true,
    ) {
    }

    /**
     * @param array<string, mixed>|string $answer
     */
    public function willAnswer(AiTask $task, array|string $answer): void
    {
        $this->answers[$task->value][] = \is_string($answer)
            ? $answer
            : (json_encode($answer, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    public function willFail(AiTask $task, ?\Throwable $error = null): void
    {
        $this->failures[$task->value][] = $error ?? new LlmException('the fake provider was told to fail');
    }

    /**
     * @return list<LlmRequest>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function callCount(): int
    {
        return \count($this->calls);
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->calls[] = $request;

        if ([] !== ($this->failures[$request->task->value] ?? [])) {
            throw array_shift($this->failures[$request->task->value]);
        }

        if ([] === ($this->answers[$request->task->value] ?? [])) {
            throw new LlmException(\sprintf('The fake provider has no answer left for task "%s"; queue one with willAnswer().', $request->task->value));
        }

        $answer = array_shift($this->answers[$request->task->value]);

        return new LlmResponse(
            $answer,
            (string) ($request->model ?? $this->providerName.':fake-model'),
            // Token counts are made up but stable, so cost accounting can be asserted.
            new LlmUsage(
                (int) ceil(mb_strlen($request->systemPrompt.$request->userPrompt) / 4),
                (int) ceil(mb_strlen($answer) / 4),
            ),
            1,
        );
    }

    public function supportsJsonSchema(): bool
    {
        return $this->jsonSchemaSupported;
    }

    public function supportsBatch(): bool
    {
        return $this->batchSupported;
    }

    public function submitBatch(array $requests): BatchHandle
    {
        if (!$this->batchSupported) {
            throw LlmException::unsupported($this->providerName, 'batch requests');
        }

        $id = 'batch-'.\count($this->batches);
        $results = [];

        foreach ($requests as $index => $request) {
            $results['' !== $request->id ? $request->id : 'request-'.$index] = $this->complete($request);
        }

        $this->batches[$id] = $results;

        return new BatchHandle($this->providerName, $id, new \DateTimeImmutable(), \count($requests));
    }

    public function pollBatch(BatchHandle $handle): BatchStatus
    {
        $results = $this->batches[$handle->id] ?? [];

        return new BatchStatus(BatchState::Completed, \count($results), 0, \count($results));
    }

    public function fetchBatchResults(BatchHandle $handle): array
    {
        return $this->batches[$handle->id] ?? [];
    }

    public function ping(string $model): bool
    {
        return true;
    }
}
