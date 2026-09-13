<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ai;

use App\Ai\Entity\AiJob;
use App\Ai\Entity\WorkerToken;
use App\Ai\Enum\AiJobStatus;
use App\Ai\Enum\AiTask;
use App\Ai\Value\LlmRequest;
use App\Ai\Value\ModelReference;
use App\Ai\Worker\AiJobQueue;
use App\Ai\Worker\WorkerTokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The queue between the server and a computer that is not part of the deployment
 * (SPEC.md § 8.3).
 *
 * The lease is the whole point: a laptop that closes its lid must not take work with it.
 */
#[CoversClass(AiJobQueue::class)]
final class AiJobQueueTest extends KernelTestCase
{
    private AiJobQueue $queue;
    private EntityManagerInterface $entityManager;
    private WorkerToken $worker;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;
        $this->queue = new AiJobQueue($this->entityManager, new NullLogger());

        $this->entityManager->createQuery('DELETE FROM '.AiJob::class.' j')->execute();
        $this->entityManager->createQuery('DELETE FROM '.WorkerToken::class.' t')->execute();

        $issuer = self::getContainer()->get(WorkerTokenIssuer::class);
        \assert($issuer instanceof WorkerTokenIssuer);
        [$this->worker] = $issuer->issue('test-machine');
    }

    public function testAQueuedJobIsClaimedOnceAndOnlyOnce(): void
    {
        $this->queue->enqueue($this->request('§ 1'), 'local');

        self::assertSame(1, $this->queue->countPending('local'));

        $claimed = $this->queue->claim($this->worker, 'local', 5, 600);

        self::assertCount(1, $claimed);
        self::assertSame(AiJobStatus::Leased, $claimed[0]->status());
        self::assertSame($this->worker, $claimed[0]->leasedBy());
        self::assertSame(1, $claimed[0]->attempts());
        self::assertSame(0, $this->queue->countPending('local'));
        self::assertSame([], $this->queue->claim($this->worker, 'local', 5, 600));
    }

    public function testJobsOfAnotherProviderAreNotClaimed(): void
    {
        $this->queue->enqueue($this->request('§ 1'), 'local');

        self::assertSame([], $this->queue->claim($this->worker, 'other', 5, 600));
    }

    public function testTheHighestPriorityIsHandedOutFirst(): void
    {
        $this->queue->enqueue($this->request('§ 1'), 'local');
        $urgent = $this->queue->enqueue($this->request('§ 2'), 'local', 10);

        $claimed = $this->queue->claim($this->worker, 'local', 1, 600);

        self::assertSame($urgent->id(), $claimed[0]->id());
    }

    public function testAnExpiredLeaseReturnsTheJobToTheQueue(): void
    {
        $this->queue->enqueue($this->request('§ 1'), 'local');
        $this->queue->claim($this->worker, 'local', 5, -60);

        self::assertSame(1, $this->queue->releaseExpiredLeases());
        self::assertSame(1, $this->queue->countPending('local'));
    }

    public function testAFreshLeaseIsNotTakenAway(): void
    {
        $this->queue->enqueue($this->request('§ 1'), 'local');
        $this->queue->claim($this->worker, 'local', 5, 600);

        self::assertSame(0, $this->queue->releaseExpiredLeases());
    }

    public function testAResultIsStoredWithTheModelThatProducedIt(): void
    {
        $job = $this->queue->enqueue($this->request('§ 1'), 'local');

        $this->queue->complete($job, ['content' => '{"text":"Статья 1"}'], 'local:qwen');

        self::assertSame(AiJobStatus::Succeeded, $job->status());
        self::assertSame('{"text":"Статья 1"}', $job->result()['content'] ?? null);
        self::assertSame('local:qwen', $job->model());
        self::assertNotNull($job->finishedAt());
    }

    /**
     * A failure is worth another worker's attempt before it is given up on.
     */
    public function testAFailedJobGoesBackToTheQueueUntilTheAttemptsAreUsedUp(): void
    {
        $job = $this->queue->enqueue($this->request('§ 1'), 'local');

        $this->queue->claim($this->worker, 'local', 5, 600);
        $this->queue->fail($job, 'the model ran out of memory', 2);
        self::assertSame(AiJobStatus::Pending, $job->status());

        $this->queue->claim($this->worker, 'local', 5, 600);
        $this->queue->fail($job, 'the model ran out of memory', 2);
        self::assertSame(AiJobStatus::Failed, $job->status());
        self::assertSame('the model ran out of memory', $job->error());
    }

    public function testTheSameRequestIsQueuedOnlyOnce(): void
    {
        $first = $this->queue->enqueue($this->request('§ 1'), 'local');
        $second = $this->queue->enqueue($this->request('§ 1'), 'local');

        self::assertSame($first->id(), $second->id());
        self::assertSame(1, $this->queue->countPending('local'));
    }

    public function testJobsWaitingLongerThanTheGracePeriodAreFound(): void
    {
        $job = $this->queue->enqueue($this->request('§ 1'), 'local');

        self::assertSame([], $this->queue->pendingSince(new \DateTimeImmutable('-1 hour')));

        // Age the row the way an hour of waiting would.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE ai_job SET created_at = :created WHERE id = :id',
            ['created' => new \DateTimeImmutable('-2 hours')->format('Y-m-d H:i:s'), 'id' => $job->id()],
        );
        $this->entityManager->clear();

        $stale = $this->queue->pendingSince(new \DateTimeImmutable('-1 hour'));

        self::assertCount(1, $stale);
        self::assertSame($job->id(), $stale[0]->id());
    }

    private function request(string $text): LlmRequest
    {
        return new LlmRequest(
            task: AiTask::NormTranslate,
            systemPrompt: 'Translate.',
            userPrompt: $text,
            model: ModelReference::parse('local:local-model'),
        );
    }
}
