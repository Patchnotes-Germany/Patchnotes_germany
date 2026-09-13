<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Ai\Entity\AiJob;
use App\Ai\Entity\AiUsage;
use App\Ai\Entity\WorkerToken;
use App\Ai\Enum\AiJobStatus;
use App\Ai\Enum\AiTask;
use App\Ai\Value\LlmRequest;
use App\Ai\Value\ModelReference;
use App\Ai\Worker\AiJobQueue;
use App\Ai\Worker\WorkerTokenIssuer;
use App\Web\Controller\AiWorkerController;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API the remote AI worker talks to (SPEC.md § 8.3).
 *
 * It is reachable from the open internet — it has to be, the worker sits behind someone's home
 * router — so the tests care as much about who is turned away as about the happy path.
 */
#[CoversClass(AiWorkerController::class)]
final class AiWorkerControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AiJobQueue $queue;
    private string $token;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;

        $queue = self::getContainer()->get(AiJobQueue::class);
        \assert($queue instanceof AiJobQueue);
        $this->queue = $queue;

        $this->entityManager->createQuery('DELETE FROM '.AiJob::class.' j')->execute();
        $this->entityManager->createQuery('DELETE FROM '.AiUsage::class.' u')->execute();
        $this->entityManager->createQuery('DELETE FROM '.WorkerToken::class.' t')->execute();

        [, $this->token] = $this->issuer()->issue('test-machine');
    }

    public function testAWorkerClaimsRunsAndCompletesAJob(): void
    {
        $job = $this->queue->enqueue($this->request(), 'local');

        $claimed = $this->post('/api/worker/v1/claim', ['provider' => 'local', 'max' => 5], $this->token);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $claimed['jobs']);
        self::assertSame($job->id(), $claimed['jobs'][0]['id']);
        self::assertSame('norm_translate', $claimed['jobs'][0]['task']);
        self::assertSame('local:local-model', $claimed['jobs'][0]['model']);
        self::assertStringContainsString('§ 1', (string) $claimed['jobs'][0]['payload']['user']);

        $this->post('/api/worker/v1/jobs/'.$job->id().'/complete', [
            'content' => '{"text":"Статья 1"}',
            'model' => 'local:qwen',
            'input_tokens' => 120,
            'output_tokens' => 45,
            'duration_ms' => 4200,
        ], $this->token);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->entityManager->clear();
        $stored = $this->entityManager->find(AiJob::class, $job->id());
        self::assertInstanceOf(AiJob::class, $stored);
        self::assertSame(AiJobStatus::Succeeded, $stored->status());
        self::assertSame('{"text":"Статья 1"}', $stored->result()['content'] ?? null);

        // What ran on the operator's own machine belongs on the dashboard too, at zero cost.
        $usage = $this->entityManager->getRepository(AiUsage::class)->findAll();
        self::assertCount(1, $usage);
        self::assertSame('local', $usage[0]->provider());
        self::assertSame(120, $usage[0]->inputTokens());
        self::assertSame('0.000000', $usage[0]->costEur());
    }

    public function testAClaimedJobIsNotHandedToASecondWorker(): void
    {
        $this->queue->enqueue($this->request(), 'local');

        $first = $this->post('/api/worker/v1/claim', ['provider' => 'local'], $this->token);
        $second = $this->post('/api/worker/v1/claim', ['provider' => 'local'], $this->token);

        self::assertCount(1, $first['jobs']);
        self::assertCount(0, $second['jobs']);
    }

    public function testAWorkerCanReportAFailure(): void
    {
        $job = $this->queue->enqueue($this->request(), 'local');
        $this->post('/api/worker/v1/claim', ['provider' => 'local'], $this->token);

        $this->post('/api/worker/v1/jobs/'.$job->id().'/fail', ['error' => 'out of memory'], $this->token);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->entityManager->clear();
        $stored = $this->entityManager->find(AiJob::class, $job->id());
        self::assertInstanceOf(AiJob::class, $stored);
        // One attempt of three: the job waits for another try rather than being given up on.
        self::assertSame(AiJobStatus::Pending, $stored->status());
    }

    public function testAHeartbeatMarksTheWorkerAsSeen(): void
    {
        $this->post('/api/worker/v1/heartbeat', [], $this->token);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->entityManager->clear();
        $tokens = $this->entityManager->getRepository(WorkerToken::class)->findAll();
        self::assertNotNull($tokens[0]->lastSeenAt());
    }

    public function testAnUnknownTokenIsRejected(): void
    {
        $this->post('/api/worker/v1/claim', ['provider' => 'local'], 'pnw_not-a-real-token');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    public function testACallWithoutATokenIsRejected(): void
    {
        $this->client->request('POST', '/api/worker/v1/claim', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    public function testARevokedTokenIsRejected(): void
    {
        $issuer = $this->issuer();
        [$token, $plain] = $issuer->issue('retired-laptop');
        $issuer->revoke($token);

        $this->post('/api/worker/v1/claim', ['provider' => 'local'], $plain);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    /**
     * A worker whose lease has expired must not overwrite the result of whoever owns the job now.
     */
    public function testCompletingAJobThatIsNotLeasedByThisWorkerIsRefused(): void
    {
        $job = $this->queue->enqueue($this->request(), 'local');

        $this->post('/api/worker/v1/jobs/'.$job->id().'/complete', ['content' => 'x'], $this->token);

        self::assertSame(Response::HTTP_CONFLICT, $this->client->getResponse()->getStatusCode());
    }

    public function testCompletingAnUnknownJobIsANotFound(): void
    {
        $this->post('/api/worker/v1/jobs/999999/complete', ['content' => 'x'], $this->token);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    private function issuer(): WorkerTokenIssuer
    {
        $issuer = self::getContainer()->get(WorkerTokenIssuer::class);
        \assert($issuer instanceof WorkerTokenIssuer);

        return $issuer;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload, string $token): array
    {
        $this->client->request(
            'POST',
            $path,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );

        $content = $this->client->getResponse()->getContent();

        /** @var array<string, mixed> $decoded */
        $decoded = \is_string($content) && '' !== $content ? (array) json_decode($content, true) : [];

        return $decoded;
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
