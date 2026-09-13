<?php

declare(strict_types=1);

namespace App\Ai\Cost;

use App\Ai\Entity\AiUsage;
use App\Ai\Enum\AiTask;
use App\Ai\Value\LlmUsage;
use App\Ai\Value\ModelReference;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes one AiUsage row per model call and answers what has been spent (SPEC.md § 8.2).
 *
 * Failed calls are recorded too: a model that times out after burning input tokens still costs
 * money, and a provider that fails often has to be visible on the dashboard.
 */
final readonly class UsageRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CostCalculator $costs,
    ) {
    }

    public function record(
        AiTask $task,
        ModelReference $model,
        LlmUsage $usage,
        int $durationMs,
        bool $success,
        ?string $subject = null,
    ): AiUsage {
        $record = new AiUsage($task, $model->provider, $model->model, $success);
        $record->setTokens($usage->inputTokens, $usage->outputTokens, $usage->cachedInputTokens);
        $record->setCostEur($this->costs->costEur($model, $usage));
        $record->setDurationMs($durationMs);

        [$type, $id] = $this->splitSubject($subject);
        $record->setSubject($type, $id);

        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return $record;
    }

    /**
     * Spend since the first day of the month the given moment falls into — the basis of the budget.
     */
    public function spentThisMonthEur(?\DateTimeImmutable $now = null): float
    {
        $now ??= new \DateTimeImmutable();
        $start = $now->setDate((int) $now->format('Y'), (int) $now->format('n'), 1)->setTime(0, 0);

        /** @var string|null $sum */
        $sum = $this->entityManager->createQuery(
            'SELECT SUM(u.costEur) FROM '.AiUsage::class.' u WHERE u.createdAt >= :start',
        )
            ->setParameter('start', $start)
            ->getSingleScalarResult();

        return null === $sum ? 0.0 : (float) $sum;
    }

    /**
     * @return array{string|null, string|null}
     */
    private function splitSubject(?string $subject): array
    {
        if (null === $subject || '' === $subject) {
            return [null, null];
        }

        $separator = strpos($subject, ':');

        if (false === $separator) {
            return [null, mb_substr($subject, 0, 96)];
        }

        return [
            mb_substr(substr($subject, 0, $separator), 0, 16),
            mb_substr(substr($subject, $separator + 1), 0, 96),
        ];
    }
}
