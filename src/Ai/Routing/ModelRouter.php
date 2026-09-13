<?php

declare(strict_types=1);

namespace App\Ai\Routing;

use App\Ai\Budget\BudgetGuard;
use App\Ai\Client\LlmClientRegistry;
use App\Ai\Enum\AiTask;
use App\Ai\Value\ModelReference;
use App\Core\Config\PatchnotesConfig;
use Psr\Log\LoggerInterface;

/**
 * Decides which models a task is offered, in which order (SPEC.md § 8.2).
 *
 * The chain in the configuration is an intention, not a guarantee: providers without credentials
 * are skipped, and the budget may take paid models off the table. What comes out is the list that
 * can actually be called right now — which is why an installation with nothing but a local model
 * configured simply works.
 */
final readonly class ModelRouter
{
    public function __construct(
        private PatchnotesConfig $config,
        private LlmClientRegistry $clients,
        private BudgetGuard $budget,
        private LoggerInterface $logger,
    ) {
    }

    public function route(AiTask $task, ?ModelReference $avoid = null): TaskRoute
    {
        $settings = $this->taskSettings($task);

        /** @var list<string> $chain */
        $chain = \is_array($settings['chain'] ?? null) ? array_values(array_filter($settings['chain'], is_string(...))) : [];

        $models = [];
        foreach ($chain as $entry) {
            $model = $this->resolve($entry);

            if (!$model instanceof ModelReference) {
                continue;
            }

            $models[(string) $model] = $model;
        }

        $usable = array_values(array_filter(
            $models,
            fn (ModelReference $model): bool => $this->isCallable($task, $model),
        ));

        if ($this->budget->pauses($task)) {
            $this->logger->warning('AI task paused: the monthly budget is exhausted', ['task' => $task->value]);

            return new TaskRoute($task, [], $this->temperature($settings), $this->batch($settings), false, true);
        }

        [$ordered, $sameProviderFallback] = $this->applyPreference($task, $usable, $avoid, $settings);

        if ([] === $ordered) {
            $this->logger->error('No usable model for an AI task', [
                'task' => $task->value,
                'configured_chain' => $chain,
            ]);
        }

        return new TaskRoute($task, $ordered, $this->temperature($settings), $this->batch($settings), $sameProviderFallback);
    }

    /**
     * Resolves "@large" to patchnotes.ai.models.large, or takes a literal "provider:model-id".
     */
    public function resolve(string $entry): ?ModelReference
    {
        $entry = trim($entry);

        if ('' === $entry) {
            return null;
        }

        if (!str_starts_with($entry, '@')) {
            return $this->parse($entry, null);
        }

        $alias = substr($entry, 1);
        /** @var array<string, mixed> $models */
        $models = $this->config->ai()['models'] ?? [];
        $value = $models[$alias] ?? null;

        // An alias whose environment variable is empty is simply not configured on this
        // installation — that is a normal setup, not an error.
        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }

        return $this->parse($value, $alias);
    }

    /**
     * @param list<ModelReference> $models
     * @param array<string, mixed> $settings
     *
     * @return array{list<ModelReference>, bool}
     */
    private function applyPreference(AiTask $task, array $models, ?ModelReference $avoid, array $settings): array
    {
        $wantsDifferentProvider = \is_string($settings['prefer_different_provider_than'] ?? null);

        if (!$wantsDifferentProvider || !$avoid instanceof ModelReference || [] === $models) {
            return [$models, false];
        }

        $different = array_values(array_filter($models, static fn (ModelReference $m): bool => $m->provider !== $avoid->provider));

        if ([] !== $different) {
            return [$different, false];
        }

        // Only one provider is configured. A different model of the same provider is still a
        // second opinion of sorts; the caller caps the score for it (SPEC.md § 24.14).
        $otherModel = array_values(array_filter($models, static fn (ModelReference $m): bool => $m->model !== $avoid->model));

        $this->logger->warning('Verification runs on the same provider that wrote the text', [
            'task' => $task->value,
            'provider' => $avoid->provider,
            'different_model' => [] !== $otherModel,
        ]);

        return [[] !== $otherModel ? $otherModel : $models, true];
    }

    private function isCallable(AiTask $task, ModelReference $model): bool
    {
        if (!$this->clients->has($model->provider)) {
            $this->logger->error('An AI task refers to a provider that is not configured', [
                'task' => $task->value,
                'provider' => $model->provider,
            ]);

            return false;
        }

        if (!$this->clients->isUsable($model->provider)) {
            $this->logger->info('Skipping an AI provider without credentials', [
                'task' => $task->value,
                'provider' => $model->provider,
            ]);

            return false;
        }

        return $this->budget->allows($task, $model);
    }

    private function parse(string $value, ?string $alias): ?ModelReference
    {
        try {
            return ModelReference::parse($value, $alias);
        } catch (\InvalidArgumentException $exception) {
            $this->logger->error('Invalid model reference in the AI configuration', [
                'value' => $value,
                'alias' => $alias,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function taskSettings(AiTask $task): array
    {
        /** @var array<string, array<string, mixed>> $tasks */
        $tasks = $this->config->ai()['tasks'] ?? [];

        return $tasks[$task->value] ?? [];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function temperature(array $settings): float
    {
        return isset($settings['temperature']) && is_numeric($settings['temperature'])
            ? (float) $settings['temperature']
            : 0.0;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function batch(array $settings): ?string
    {
        return \is_string($settings['batch'] ?? null) ? $settings['batch'] : null;
    }
}
