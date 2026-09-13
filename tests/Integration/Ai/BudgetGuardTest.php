<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ai;

use App\Ai\Budget\BudgetGuard;
use App\Ai\Cost\CostCalculator;
use App\Ai\Cost\UsageRecorder;
use App\Ai\Entity\AiUsage;
use App\Ai\Enum\AiTask;
use App\Ai\Enum\BudgetState;
use App\Ai\Value\ModelReference;
use App\Core\Config\PatchnotesConfig;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The monthly budget (SPEC.md § 8.2).
 *
 * The point of enforcing it rather than reporting it: this project has to be affordable to run, and
 * an unnoticed loop over 6000 laws must not empty the operator's account.
 */
#[CoversClass(BudgetGuard::class)]
#[CoversClass(UsageRecorder::class)]
final class BudgetGuardTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;

        $this->entityManager->createQuery('DELETE FROM '.AiUsage::class.' u')->execute();
    }

    public function testSpendingIsSummedForTheCurrentMonth(): void
    {
        $this->spend(4.0);
        $this->spend(1.5);

        self::assertSame(5.5, $this->guard(10.0)->spentEur());
    }

    public function testAnUnlimitedBudgetNeverInterferes(): void
    {
        $this->spend(1000.0);

        $guard = $this->guard(0.0);

        self::assertSame(BudgetState::Ok, $guard->state());
        self::assertTrue($guard->allows(AiTask::CardTranslate, $this->paidModel()));
    }

    public function testCrossingTheAlertThresholdIsAWarningButChangesNothing(): void
    {
        $this->spend(8.5);

        $guard = $this->guard(10.0);

        self::assertSame(BudgetState::Warning, $guard->state());
        self::assertTrue($guard->allows(AiTask::CardTranslate, $this->paidModel()));
    }

    /**
     * "degrade": keep working on whatever costs nothing — typically the operator's own machine.
     */
    public function testWhenExhaustedTheDegradePolicyAllowsOnlyFreeModels(): void
    {
        $this->spend(10.0);

        $guard = $this->guard(10.0, 'degrade');

        self::assertSame(BudgetState::Exceeded, $guard->state());
        self::assertFalse($guard->allows(AiTask::CardTranslate, $this->paidModel()));
        self::assertTrue($guard->allows(AiTask::CardTranslate, $this->freeModel()));
        self::assertFalse($guard->pauses(AiTask::CardTranslate));
    }

    /**
     * "pause": everything stops except what is needed to publish a change at all — a missed
     * translation is an inconvenience, a missed change is the point of the project.
     */
    public function testWhenExhaustedThePausePolicyStopsOnlyTheNonCriticalTasks(): void
    {
        $this->spend(12.0);

        $guard = $this->guard(10.0, 'pause');

        self::assertTrue($guard->pauses(AiTask::CardTranslate));
        self::assertFalse($guard->allows(AiTask::CardTranslate, $this->freeModel()));

        self::assertFalse($guard->pauses(AiTask::ChangeAnalyze));
        self::assertTrue($guard->allows(AiTask::ChangeAnalyze, $this->paidModel()));
    }

    public function testSpendingOfAPreviousMonthDoesNotCount(): void
    {
        $usage = $this->spend(9.0);

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE ai_usage SET created_at = :created WHERE id = :id',
            ['created' => new \DateTimeImmutable('-2 months')->format('Y-m-d H:i:s'), 'id' => $usage->id()],
        );
        $this->entityManager->clear();

        self::assertSame(0.0, $this->guard(10.0)->spentEur());
    }

    private function spend(float $eur): AiUsage
    {
        $usage = new AiUsage(AiTask::CardWrite, 'openai', 'test-model', true);
        $usage->setTokens(1000, 500);
        $usage->setCostEur(\sprintf('%.6F', $eur));

        $this->entityManager->persist($usage);
        $this->entityManager->flush();

        return $usage;
    }

    private function guard(float $limit, string $onExceed = 'degrade'): BudgetGuard
    {
        $config = new PatchnotesConfig([
            'ai' => [
                'budget' => [
                    'monthly_limit_eur' => $limit,
                    'on_exceed' => $onExceed,
                    'alert_thresholds' => [0.8, 1.0],
                ],
                'pricing' => [
                    'openai:paid-model' => ['input_per_mtok' => 2.0, 'output_per_mtok' => 8.0, 'currency' => 'USD'],
                ],
                'fx' => ['usd_eur' => 0.92],
            ],
        ]);

        $costs = new CostCalculator($config);

        return new BudgetGuard($config, new UsageRecorder($this->entityManager, $costs), $costs, new NullLogger());
    }

    private function paidModel(): ModelReference
    {
        return ModelReference::parse('openai:paid-model');
    }

    private function freeModel(): ModelReference
    {
        return ModelReference::parse('local:qwen');
    }
}
