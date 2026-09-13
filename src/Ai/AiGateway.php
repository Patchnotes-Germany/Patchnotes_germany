<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Budget\BudgetGuard;
use App\Ai\Cache\ResponseCache;
use App\Ai\Client\LlmClientRegistry;
use App\Ai\Cost\UsageRecorder;
use App\Ai\Enum\AiTask;
use App\Ai\Enum\ExecutionMode;
use App\Ai\Exception\AiException;
use App\Ai\Exception\LlmException;
use App\Ai\Prompt\PromptRenderer;
use App\Ai\Routing\ModelRouter;
use App\Ai\Schema\JsonSchemaValidator;
use App\Ai\Schema\SchemaRegistry;
use App\Ai\Value\AiResult;
use App\Ai\Value\LlmRequest;
use App\Ai\Value\LlmResponse;
use App\Ai\Value\LlmUsage;
use App\Ai\Value\ModelReference;
use App\Ai\Worker\AiJobQueue;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The one way the rest of the application asks a model for something (SPEC.md § 8.2).
 *
 * Everything the specification asks for around a single call lives here, in one place, because each
 * of these steps exists to stop a wrong answer from reaching a reader: the cached answer is reused,
 * the prompt is versioned, the answer is validated against the task schema and repaired twice
 * before the next model in the chain is tried, every attempt is billed and logged, and a task
 * routed to the operator's own computer becomes a job instead of a blocked worker.
 */
final readonly class AiGateway
{
    /** How often a model is asked to fix its own invalid JSON before moving on (SPEC.md § 8.2). */
    private const int REPAIR_ATTEMPTS = 2;

    public function __construct(
        private ModelRouter $router,
        private LlmClientRegistry $clients,
        private PromptRenderer $prompts,
        private SchemaRegistry $schemas,
        private JsonSchemaValidator $validator,
        private ResponseCache $cache,
        private UsageRecorder $usage,
        private BudgetGuard $budget,
        private AiJobQueue $jobs,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $context variables for the prompt templates
     * @param string|null          $subject what the answer belongs to, e.g. "change:2026-bund-…"
     * @param ModelReference|null  $avoid   a model whose provider should not be reused (§ 24.14)
     *
     * @throws AiException when no model could produce a valid answer
     */
    public function run(AiTask $task, array $context = [], ?string $subject = null, ?ModelReference $avoid = null): AiResult
    {
        $route = $this->router->route($task, $avoid);

        if ($route->pausedByBudget) {
            throw AiException::pausedByBudget($task);
        }

        if ($route->isEmpty()) {
            throw AiException::noRoute($task);
        }

        $prompt = $this->prompts->render($task, $context);
        $schema = $this->schemas->for($task);

        $request = new LlmRequest(
            task: $task,
            systemPrompt: $prompt->system,
            userPrompt: $prompt->user,
            temperature: $route->temperature,
            jsonSchema: $schema,
            promptVersion: $prompt->version,
            id: Uuid::v7()->toRfc4122(),
            subject: $subject,
        );

        $lastError = 'no model was tried';

        foreach ($route->chain as $model) {
            $attempt = $request->withModel($model);

            $cached = $this->cache->get($attempt->fingerprint());
            if ($cached instanceof LlmResponse) {
                $this->logger->debug('AI answer served from the cache', [
                    'task' => $task->value,
                    'model' => (string) $model,
                ]);

                return AiResult::completed($task, $cached, $model, $this->decode($cached, $schema), $prompt->version);
            }

            // The model lives on someone's desk: queue the work instead of waiting for it.
            if (ExecutionMode::RemoteWorker === $this->clients->executionMode($model->provider)) {
                $job = $this->jobs->enqueue($attempt, $model->provider);
                $id = $job->id();

                $this->logger->info('AI task handed to a remote worker', [
                    'task' => $task->value,
                    'provider' => $model->provider,
                    'job' => $id,
                ]);

                return AiResult::queued($task, $id ?? 0, $model, $prompt->version);
            }

            try {
                return $this->callWithRepair($attempt, $model, $schema, $prompt->version, $subject);
            } catch (LlmException $exception) {
                $lastError = $exception->getMessage();
                $this->logger->warning('AI model failed, moving on to the next in the chain', [
                    'task' => $task->value,
                    'model' => (string) $model,
                    'error' => $lastError,
                ]);
            }
        }

        throw AiException::exhausted($task, $lastError);
    }

    /**
     * Executes a prepared request on one model — used by the remote worker, which already has the
     * request in hand and only needs it run against its local server.
     */
    public function execute(LlmRequest $request): LlmResponse
    {
        $model = $request->boundModel();

        return $this->clients->get($model->provider)->complete($request);
    }

    /**
     * @param array<string, mixed>|null $schema
     *
     * @throws LlmException when this model cannot produce a valid answer
     */
    private function callWithRepair(
        LlmRequest $request,
        ModelReference $model,
        ?array $schema,
        int $promptVersion,
        ?string $subject,
    ): AiResult {
        $client = $this->clients->get($model->provider);
        $attempt = $request;

        for ($repair = 0; $repair <= self::REPAIR_ATTEMPTS; ++$repair) {
            try {
                $response = $client->complete($attempt);
            } catch (LlmException $exception) {
                // The tokens of a failed call are unknown but the failure itself belongs on the
                // dashboard, so it is recorded with zero usage.
                $this->record($request->task, $model, new LlmUsage(), 0, false, $subject);

                throw $exception;
            }

            $this->record($request->task, $model, $response->usage, $response->durationMs, true, $subject);

            if (null === $schema) {
                $this->cache->put($attempt->fingerprint(), $response);

                return AiResult::completed($request->task, $response, $model, null, $promptVersion);
            }

            $data = $response->json();
            $errors = null === $data ? ['The answer is not a JSON object.'] : $this->validator->validate($schema, $data);

            if (null !== $data && [] === $errors) {
                // Cached under the original fingerprint, so a repaired answer is reused as well.
                $this->cache->put($request->fingerprint($model), $response);

                return AiResult::completed($request->task, $response, $model, $data, $promptVersion);
            }

            $this->logger->info('AI answer did not match the schema; asking the model to fix it', [
                'task' => $request->task->value,
                'model' => (string) $model,
                'attempt' => $repair + 1,
                'errors' => \array_slice($errors, 0, 5),
            ]);

            $attempt = $request->withUserPrompt($this->repairPrompt($request->userPrompt, $response->content, $errors))
                ->withModel($model);
        }

        throw new LlmException(\sprintf('Model "%s" did not return a valid answer for task "%s" after %d repair attempts.', (string) $model, $request->task->value, self::REPAIR_ATTEMPTS));
    }

    /**
     * @param list<string> $errors
     */
    private function repairPrompt(string $original, string $answer, array $errors): string
    {
        return $original."\n\n"
            ."## Correction\n"
            ."Your previous answer did not match the required JSON schema.\n\n"
            ."Previous answer:\n".mb_substr($answer, 0, 4000)."\n\n"
            ."Problems:\n- ".implode("\n- ", $errors)."\n\n"
            .'Answer again with valid JSON only, matching the schema exactly. Do not add any text around the JSON.';
    }

    /**
     * @param array<string, mixed>|null $schema
     *
     * @return array<string, mixed>|null
     */
    private function decode(LlmResponse $response, ?array $schema): ?array
    {
        if (null === $schema) {
            return null;
        }

        $data = $response->json();

        return null !== $data && [] === $this->validator->validate($schema, $data) ? $data : null;
    }

    private function record(AiTask $task, ModelReference $model, LlmUsage $usage, int $durationMs, bool $success, ?string $subject): void
    {
        $this->usage->record($task, $model, $usage, $durationMs, $success, $subject);
        $this->budget->refresh();
        $this->budget->reportIfCrossed();
    }
}
