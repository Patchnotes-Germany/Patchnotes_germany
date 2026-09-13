<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ai;

use App\Ai\Client\FakeLlmClient;
use App\Ai\Client\LlmClientRegistry;
use App\Ai\Entity\AiJob;
use App\Ai\Entity\AiUsage;
use App\Ai\Entity\WorkerToken;
use App\Ai\Enum\AiJobStatus;
use App\Ai\Enum\AiTask;
use App\Ai\Handler\FallbackStaleAiJobsHandler;
use App\Ai\Message\FallbackStaleAiJobs;
use App\Ai\Value\LlmRequest;
use App\Ai\Value\ModelReference;
use App\Ai\Worker\AiJobQueue;
use App\Ai\Worker\WorkerTokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What happens when the computer with the local model is simply not there (SPEC.md § 8.3).
 *
 * This is the half of the remote-worker design that has to work unattended: a laptop that is closed
 * for the weekend must not hold up an alert about a change in the law. After the configured grace
 * period the job runs on a cloud provider instead.
 */
#[CoversClass(FallbackStaleAiJobsHandler::class)]
final class FallbackStaleAiJobsHandlerTest extends KernelTestCase
{
    private FallbackStaleAiJobsHandler $handler;
    private AiJobQueue $queue;
    private EntityManagerInterface $entityManager;
    private FakeLlmClient $cloud;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;
        $this->queue = new AiJobQueue($this->entityManager, new NullLogger());

        $handler = self::getContainer()->get(FallbackStaleAiJobsHandler::class);
        \assert($handler instanceof FallbackStaleAiJobsHandler);
        $this->handler = $handler;

        $registry = self::getContainer()->get(LlmClientRegistry::class);
        \assert($registry instanceof LlmClientRegistry);
        // norm_translate is routed "@medium, @local_default": the medium alias is the cloud
        // provider the fallback is expected to reach for.
        $cloud = $registry->get('fake_second');
        \assert($cloud instanceof FakeLlmClient);
        $this->cloud = $cloud;

        $this->entityManager->createQuery('DELETE FROM '.AiJob::class.' j')->execute();
        $this->entityManager->createQuery('DELETE FROM '.AiUsage::class.' u')->execute();
        $this->entityManager->createQuery('DELETE FROM '.WorkerToken::class.' t')->execute();
    }

    public function testAJobNobodyPickedUpIsRunByACloudProvider(): void
    {
        $job = $this->queue->enqueue($this->request(), 'local');
        $this->age($job, '-2 hours');
        $this->cloud->willAnswer(AiTask::NormTranslate, '{"text":"Статья 1"}');

        ($this->handler)(new FallbackStaleAiJobs());

        $this->entityManager->clear();
        $stored = $this->entityManager->find(AiJob::class, $job->id());
        self::assertInstanceOf(AiJob::class, $stored);
        self::assertSame(AiJobStatus::Succeeded, $stored->status());
        self::assertSame('{"text":"Статья 1"}', $stored->result()['content'] ?? null);
        self::assertTrue($stored->result()['fell_back'] ?? false);
        self::assertSame('fake_second:medium-model', $stored->model());

        // The cloud call costs money, so it is accounted for like any other.
        $usage = $this->entityManager->getRepository(AiUsage::class)->findAll();
        self::assertCount(1, $usage);
        self::assertSame('fake_second', $usage[0]->provider());
        self::assertTrue($usage[0]->isSuccess());
    }

    /**
     * The grace period is the whole point: a worker that is merely slow must keep its work.
     */
    public function testAJobInsideTheGracePeriodIsLeftForTheWorker(): void
    {
        $job = $this->queue->enqueue($this->request(), 'local');

        ($this->handler)(new FallbackStaleAiJobs());

        $this->entityManager->clear();
        $stored = $this->entityManager->find(AiJob::class, $job->id());
        self::assertInstanceOf(AiJob::class, $stored);
        self::assertSame(AiJobStatus::Pending, $stored->status());
        self::assertSame(0, $this->cloud->callCount());
    }

    public function testAJobWhoseLeaseExpiredGoesBackIntoTheQueue(): void
    {
        $issuer = self::getContainer()->get(WorkerTokenIssuer::class);
        \assert($issuer instanceof WorkerTokenIssuer);
        [$worker] = $issuer->issue('closed-laptop');

        $this->queue->enqueue($this->request(), 'local');
        $this->queue->claim($worker, 'local', 5, -60);

        ($this->handler)(new FallbackStaleAiJobs());

        // Released first, and still inside its grace period, so it waits for the worker again.
        self::assertSame(1, $this->queue->countPending('local'));
        self::assertSame(0, $this->cloud->callCount());
    }

    /**
     * A cloud provider that fails must not lose the job: it stays queued for the next round.
     */
    public function testAFailingCloudProviderLeavesTheJobAlone(): void
    {
        $job = $this->queue->enqueue($this->request(), 'local');
        $this->age($job, '-2 hours');
        $this->cloud->willFail(AiTask::NormTranslate);

        ($this->handler)(new FallbackStaleAiJobs());

        $this->entityManager->clear();
        $stored = $this->entityManager->find(AiJob::class, $job->id());
        self::assertInstanceOf(AiJob::class, $stored);
        self::assertSame(AiJobStatus::Pending, $stored->status());

        $usage = $this->entityManager->getRepository(AiUsage::class)->findAll();
        self::assertCount(1, $usage);
        self::assertFalse($usage[0]->isSuccess());
    }

    private function age(AiJob $job, string $interval): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE ai_job SET created_at = :created WHERE id = :id',
            ['created' => new \DateTimeImmutable($interval)->format('Y-m-d H:i:s'), 'id' => $job->id()],
        );
        $this->entityManager->clear();
    }

    private function request(): LlmRequest
    {
        return new LlmRequest(
            task: AiTask::NormTranslate,
            systemPrompt: 'Translate the norm.',
            userPrompt: '§ 1 Anwendungsbereich',
            model: ModelReference::parse('local:local-model'),
        );
    }
}
