<?php

declare(strict_types=1);

namespace App\Ai\Client;

use App\Ai\Value\BatchHandle;
use App\Ai\Value\BatchStatus;
use App\Ai\Value\LlmRequest;
use App\Ai\Value\LlmResponse;

/**
 * One interface for every model provider (SPEC.md § 8.1).
 *
 * Three implementations cover the field: OpenAI, Anthropic, and any OpenAI-compatible server
 * (Ollama, LM Studio, vLLM, llama.cpp, LocalAI). Switching a task from a cloud model to the
 * computer under someone's desk must be a configuration change, never a code change — which is why
 * nothing above this interface knows a model id or a vendor.
 *
 * Providers differ in what they can do: structured output through a JSON schema, and batch APIs
 * that are cheaper but may take hours. Callers ask instead of assuming, and batches are used only
 * for bulk work, never for the live pipeline (SPEC.md § 24.14).
 */
interface LlmClientInterface
{
    /** Provider alias from patchnotes.ai.providers, e.g. "openai", "anthropic", "local". */
    public function name(): string;

    public function complete(LlmRequest $request): LlmResponse;

    /**
     * Whether the provider can enforce a JSON schema server-side. If it cannot, the router asks for
     * JSON in the prompt and repairs or retries invalid answers (SPEC.md § 8.1).
     */
    public function supportsJsonSchema(): bool;

    public function supportsBatch(): bool;

    /**
     * @param list<LlmRequest> $requests
     */
    public function submitBatch(array $requests): BatchHandle;

    public function pollBatch(BatchHandle $handle): BatchStatus;

    /**
     * @return array<string, LlmResponse> keyed by LlmRequest::$id
     */
    public function fetchBatchResults(BatchHandle $handle): array;

    /**
     * A cheap call that proves the provider answers and the model exists — used by the admin's
     * "ping model" button and by the contract tests.
     */
    public function ping(string $model): bool;
}
