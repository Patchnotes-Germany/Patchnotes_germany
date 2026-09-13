<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Ai\Cost\UsageRecorder;
use App\Ai\Entity\AiJob;
use App\Ai\Entity\WorkerToken;
use App\Ai\Value\LlmUsage;
use App\Ai\Value\ModelReference;
use App\Ai\Worker\AiJobQueue;
use App\Ai\Worker\WorkerTokenAuthenticator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The API a remote AI worker talks to (SPEC.md § 8.3).
 *
 * The model may run on the owner's laptop while the application runs on a VPS, so the work travels
 * the other way round: the worker connects out, claims a lease on a few jobs, and posts the results
 * back. Nothing here blocks a pipeline stage, and a worker that disappears mid-job simply lets its
 * lease expire.
 */
#[Route('/api/worker/v1', name: 'app_ai_worker_')]
final readonly class AiWorkerController
{
    private const int DEFAULT_LEASE_SECONDS = 600;
    private const int MAX_LEASE_SECONDS = 3600;
    private const int DEFAULT_BATCH = 2;

    public function __construct(
        private WorkerTokenAuthenticator $authenticator,
        private AiJobQueue $queue,
        private UsageRecorder $usage,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/claim', name: 'claim', methods: ['POST'])]
    public function claim(Request $request): JsonResponse
    {
        $worker = $this->authenticator->authenticate($request);

        if (!$worker instanceof WorkerToken) {
            return $this->unauthorised();
        }

        $payload = $this->payload($request);
        $provider = \is_string($payload['provider'] ?? null) ? $payload['provider'] : 'local';
        $max = $this->int($payload['max'] ?? null, self::DEFAULT_BATCH);
        $lease = min($this->int($payload['lease_seconds'] ?? null, self::DEFAULT_LEASE_SECONDS), self::MAX_LEASE_SECONDS);

        $jobs = $this->queue->claim($worker, $provider, $max, $lease);

        return new JsonResponse([
            'lease_seconds' => $lease,
            'jobs' => array_map(static fn (AiJob $job): array => [
                'id' => $job->id(),
                'task' => $job->task()->value,
                'model' => $job->model(),
                'payload' => $job->payload(),
            ], $jobs),
        ]);
    }

    #[Route('/jobs/{id}/complete', name: 'complete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function complete(int $id, Request $request): JsonResponse
    {
        $worker = $this->authenticator->authenticate($request);

        if (!$worker instanceof WorkerToken) {
            return $this->unauthorised();
        }

        $job = $this->queue->find($id);

        if (!$job instanceof AiJob) {
            return new JsonResponse(['status' => 'unknown job'], Response::HTTP_NOT_FOUND);
        }

        if ($job->leasedBy() !== $worker) {
            // The lease expired and somebody else owns the job now; the answer is dropped rather
            // than overwriting whatever the new owner is doing.
            return new JsonResponse(['status' => 'lease lost'], Response::HTTP_CONFLICT);
        }

        $payload = $this->payload($request);
        $content = \is_string($payload['content'] ?? null) ? $payload['content'] : '';
        $model = \is_string($payload['model'] ?? null) && '' !== $payload['model'] ? $payload['model'] : $job->model();
        $durationMs = $this->int($payload['duration_ms'] ?? null, 0);

        $this->queue->complete($job, ['content' => $content], $model);

        // Local models cost nothing, but the dashboard should still show what ran where.
        if (\is_string($model) && '' !== $model) {
            $this->usage->record(
                $job->task(),
                ModelReference::parse($model),
                new LlmUsage($this->int($payload['input_tokens'] ?? null, 0), $this->int($payload['output_tokens'] ?? null, 0)),
                $durationMs,
                true,
                $this->subject($job),
            );
        }

        $this->logger->info('Remote AI worker completed a job', [
            'job' => $job->id(),
            'task' => $job->task()->value,
            'worker' => $worker->name(),
        ]);

        return new JsonResponse(['status' => 'accepted']);
    }

    #[Route('/jobs/{id}/fail', name: 'fail', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function fail(int $id, Request $request): JsonResponse
    {
        $worker = $this->authenticator->authenticate($request);

        if (!$worker instanceof WorkerToken) {
            return $this->unauthorised();
        }

        $job = $this->queue->find($id);

        if (!$job instanceof AiJob) {
            return new JsonResponse(['status' => 'unknown job'], Response::HTTP_NOT_FOUND);
        }

        if ($job->leasedBy() !== $worker) {
            return new JsonResponse(['status' => 'lease lost'], Response::HTTP_CONFLICT);
        }

        $payload = $this->payload($request);
        $error = \is_string($payload['error'] ?? null) ? mb_substr($payload['error'], 0, 2000) : 'the worker reported a failure';

        $this->queue->fail($job, $error);

        $this->logger->warning('Remote AI worker failed a job', [
            'job' => $job->id(),
            'task' => $job->task()->value,
            'error' => $error,
        ]);

        return new JsonResponse(['status' => 'accepted']);
    }

    #[Route('/jobs/{id}/extend', name: 'extend', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function extend(int $id, Request $request): JsonResponse
    {
        $worker = $this->authenticator->authenticate($request);

        if (!$worker instanceof WorkerToken) {
            return $this->unauthorised();
        }

        $job = $this->queue->find($id);

        if (!$job instanceof AiJob || $job->leasedBy() !== $worker) {
            return new JsonResponse(['status' => 'lease lost'], Response::HTTP_CONFLICT);
        }

        $lease = min($this->int($this->payload($request)['lease_seconds'] ?? null, self::DEFAULT_LEASE_SECONDS), self::MAX_LEASE_SECONDS);
        $this->queue->extendLease($job, $lease);

        return new JsonResponse(['status' => 'extended', 'lease_seconds' => $lease]);
    }

    /**
     * Tells the dashboard the worker is alive, so the operator can see whether the local model is
     * reachable before the cloud fallback kicks in.
     */
    #[Route('/heartbeat', name: 'heartbeat', methods: ['POST'])]
    public function heartbeat(Request $request): JsonResponse
    {
        $worker = $this->authenticator->authenticate($request);

        if (!$worker instanceof WorkerToken) {
            return $this->unauthorised();
        }

        $this->queue->heartbeat($worker);

        return new JsonResponse(['status' => 'ok', 'worker' => $worker->name()]);
    }

    private function unauthorised(): JsonResponse
    {
        return new JsonResponse(['status' => 'unauthorised'], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $body = $request->getContent();

        if ('' === $body) {
            return [];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = (array) json_decode($body, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return $decoded;
    }

    private function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private function subject(AiJob $job): ?string
    {
        if (null === $job->subjectId()) {
            return null;
        }

        return null === $job->subjectType() ? $job->subjectId() : $job->subjectType().':'.$job->subjectId();
    }
}
