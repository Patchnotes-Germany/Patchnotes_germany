<?php

declare(strict_types=1);

namespace App\Ai\Budget;

use App\Ai\Cost\CostCalculator;
use App\Ai\Cost\UsageRecorder;
use App\Ai\Enum\AiTask;
use App\Ai\Enum\BudgetState;
use App\Ai\Value\ModelReference;
use App\Core\Config\PatchnotesConfig;
use Psr\Log\LoggerInterface;

/**
 * Keeps the month inside the configured budget (SPEC.md § 8.2).
 *
 * The project is meant to be affordable to run, so the budget is enforced rather than merely
 * reported. Two policies exist: "degrade" keeps working on free models — typically the operator's
 * own machine — and "pause" stops everything that is not needed to publish a change at all.
 */
final class BudgetGuard
{
    private ?float $spent = null;

    public function __construct(
        private readonly PatchnotesConfig $config,
        private readonly UsageRecorder $usage,
        private readonly CostCalculator $costs,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function limitEur(): float
    {
        /** @var array<string, mixed> $budget */
        $budget = $this->config->ai()['budget'] ?? [];

        return (float) ($budget['monthly_limit_eur'] ?? 0);
    }

    public function spentEur(): float
    {
        return $this->spent ??= $this->usage->spentThisMonthEur();
    }

    /**
     * Call after recording usage, so the next decision sees the new total.
     */
    public function refresh(): void
    {
        $this->spent = null;
    }

    public function state(): BudgetState
    {
        $limit = $this->limitEur();

        // No limit configured means the operator accepts whatever it costs.
        if ($limit <= 0.0) {
            return BudgetState::Ok;
        }

        $ratio = $this->spentEur() / $limit;

        if ($ratio >= 1.0) {
            return BudgetState::Exceeded;
        }

        return $ratio >= $this->warningThreshold() ? BudgetState::Warning : BudgetState::Ok;
    }

    /**
     * Whether this task may run on this model right now.
     */
    public function allows(AiTask $task, ModelReference $model): bool
    {
        if (BudgetState::Exceeded !== $this->state()) {
            return true;
        }

        if ($this->isPausePolicy()) {
            // Only the tasks without which nothing can be published at all.
            return $task->isCritical();
        }

        // "degrade": whatever costs nothing keeps running.
        return $this->costs->isFree($model);
    }

    /**
     * True when the budget stops this task entirely, whatever the model.
     */
    public function pauses(AiTask $task): bool
    {
        return BudgetState::Exceeded === $this->state()
            && $this->isPausePolicy()
            && !$task->isCritical();
    }

    /**
     * Logged here and turned into admin alerts in M9; the thresholds come from configuration.
     */
    public function reportIfCrossed(): void
    {
        $state = $this->state();

        if (BudgetState::Ok === $state) {
            return;
        }

        $this->logger->warning('The monthly AI budget is under pressure', [
            'state' => $state->value,
            'spent_eur' => round($this->spentEur(), 2),
            'limit_eur' => $this->limitEur(),
            'policy' => $this->policy(),
        ]);
    }

    public function policy(): string
    {
        /** @var array<string, mixed> $budget */
        $budget = $this->config->ai()['budget'] ?? [];
        $policy = $budget['on_exceed'] ?? 'degrade';

        return \is_string($policy) ? $policy : 'degrade';
    }

    private function isPausePolicy(): bool
    {
        return 'pause' === $this->policy();
    }

    private function warningThreshold(): float
    {
        /** @var array<string, mixed> $budget */
        $budget = $this->config->ai()['budget'] ?? [];
        /** @var list<float> $thresholds */
        $thresholds = \is_array($budget['alert_thresholds'] ?? null) ? $budget['alert_thresholds'] : [];

        $below = array_filter($thresholds, static fn (float $threshold): bool => $threshold < 1.0);

        return [] === $below ? 0.8 : min($below);
    }
}
