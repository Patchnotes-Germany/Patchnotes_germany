<?php

declare(strict_types=1);

namespace App\Ai\Worker;

use App\Ai\Entity\AiJob;
use App\Ai\Entity\WorkerToken;
use App\Ai\Enum\AiJobStatus;
use App\Ai\Value\LlmRequest;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The queue between the server and the operator's own computer (SPEC.md § 8.3).
 *
 * These jobs are rows rather than Messenger messages on purpose: the worker is not part of the
 * deployment, it connects from outside over HTTPS and pulls work. Claiming uses
 * SELECT … FOR UPDATE SKIP LOCKED so two workers never take the same job, and every claim is a
 * lease — a laptop that closes its lid returns its jobs to the queue by itself.
 */
final readonly class AiJobQueue
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Queues a request, or returns the job that is already queued for exactly this input.
     */
    public function enqueue(LlmRequest $request, string $provider, int $priority = 0): AiJob
    {
        $fingerprint = $request->fingerprint();

        $existing = $this->entityManager->getRepository(AiJob::class)->findOneBy([
            'task' => $request->task,
            'inputHash' => $fingerprint,
            'status' => [AiJobStatus::Pending, AiJobStatus::Leased],
        ]);

        if ($existing instanceof AiJob) {
            return $existing;
        }

        $job = new AiJob($request->task, $provider, $fingerprint, $request->toArray());
        $job->setModel($request->model instanceof \App\Ai\Value\ModelReference ? (string) $request->model : null);
        $job->setPriority($priority);
        [$type, $id] = $this->splitSubject($request->subject);
        $job->setSubject($type, $id);

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }

    /**
     * Hands out up to $limit jobs of one provider, leased for $leaseSeconds.
     *
     * @return list<AiJob>
     */
    public function claim(WorkerToken $worker, string $provider, int $limit, int $leaseSeconds): array
    {
        $limit = max(1, min($limit, 50));
        $now = new \DateTimeImmutable();

        /** @var list<AiJob> $claimed */
        $claimed = $this->entityManager->wrapInTransaction(
            static function (EntityManagerInterface $entityManager) use ($worker, $provider, $limit, $leaseSeconds, $now): array {
                // The limit is interpolated because MySQL will not take a placeholder there; it is
                // an integer clamped to 1…50 by the caller, never user input.
                $sql = \sprintf(
                    'SELECT id FROM ai_job WHERE status = :status AND provider = :provider'
                    .' ORDER BY priority DESC, created_at ASC LIMIT %d FOR UPDATE SKIP LOCKED',
                    $limit,
                );

                /** @var list<int> $ids */
                $ids = $entityManager->getConnection()->executeQuery(
                    $sql,
                    ['status' => AiJobStatus::Pending->value, 'provider' => $provider],
                )->fetchFirstColumn();

                $jobs = [];

                foreach ($ids as $id) {
                    $job = $entityManager->find(AiJob::class, $id, LockMode::NONE);

                    if (!$job instanceof AiJob) {
                        continue;
                    }

                    $job->lease($worker, $now->modify(\sprintf('+%d seconds', $leaseSeconds)));
                    $jobs[] = $job;
                }

                $worker->heartbeat($now);
                $entityManager->flush();

                return $jobs;
            },
        );

        return $claimed;
    }

    /**
     * @param array<string, mixed> $result
     */
    public function complete(AiJob $job, array $result, ?string $model = null): void
    {
        $job->complete($result, $model);
        $this->entityManager->flush();
    }

    public function fail(AiJob $job, string $error, int $maxAttempts = 3): void
    {
        if ($job->attempts() < $maxAttempts) {
            // Another worker — or the same one after a restart — may still succeed.
            $job->releaseLease();
        } else {
            $job->fail($error);
        }

        $this->entityManager->flush();
    }

    public function extendLease(AiJob $job, int $leaseSeconds): void
    {
        $job->extendLease(new \DateTimeImmutable()->modify(\sprintf('+%d seconds', $leaseSeconds)));
        $this->entityManager->flush();
    }

    /**
     * Puts jobs whose lease ran out back into the queue.
     *
     * @return int the number of jobs returned
     */
    public function releaseExpiredLeases(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();

        /** @var list<AiJob> $expired */
        $expired = $this->entityManager->createQuery(
            'SELECT j FROM '.AiJob::class.' j WHERE j.status = :leased AND j.leaseUntil < :now',
        )
            ->setParameter('leased', AiJobStatus::Leased)
            ->setParameter('now', $now)
            ->getResult();

        foreach ($expired as $job) {
            $job->releaseLease();
            $this->logger->info('AI job lease expired and the job returned to the queue', ['job' => $job->id()]);
        }

        if ([] !== $expired) {
            $this->entityManager->flush();
        }

        return \count($expired);
    }

    /**
     * Jobs nobody has picked up for too long — the cloud fallback of SPEC.md § 8.3.
     *
     * @return list<AiJob>
     */
    public function pendingSince(\DateTimeImmutable $threshold, int $limit = 50): array
    {
        /** @var list<AiJob> $jobs */
        $jobs = $this->entityManager->createQuery(
            'SELECT j FROM '.AiJob::class.' j WHERE j.status = :pending AND j.createdAt < :threshold ORDER BY j.createdAt ASC',
        )
            ->setParameter('pending', AiJobStatus::Pending)
            ->setParameter('threshold', $threshold)
            ->setMaxResults($limit)
            ->getResult();

        return $jobs;
    }

    public function markFellBack(AiJob $job): void
    {
        $job->markFellBack();
        $this->entityManager->flush();
    }

    public function find(int $id): ?AiJob
    {
        return $this->entityManager->find(AiJob::class, $id);
    }

    /**
     * Records that a worker is alive even when there was no work for it — the admin dashboard uses
     * this to tell "offline" apart from "idle" before the cloud fallback kicks in.
     */
    public function heartbeat(WorkerToken $worker): void
    {
        $worker->heartbeat(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    /**
     * How many jobs are waiting for a given provider; shown in the dashboard and used by the tests.
     */
    public function countPending(string $provider): int
    {
        return (int) $this->entityManager->createQuery(
            'SELECT COUNT(j.id) FROM '.AiJob::class.' j WHERE j.status = :pending AND j.provider = :provider',
        )
            ->setParameter('pending', AiJobStatus::Pending)
            ->setParameter('provider', $provider)
            ->getSingleScalarResult();
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
