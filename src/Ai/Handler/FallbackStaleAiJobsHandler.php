<?php

declare(strict_types=1);

namespace App\Ai\Handler;

use App\Ai\Client\LlmClientRegistry;
use App\Ai\Cost\UsageRecorder;
use App\Ai\Entity\AiJob;
use App\Ai\Enum\ExecutionMode;
use App\Ai\Exception\LlmException;
use App\Ai\Message\FallbackStaleAiJobs;
use App\Ai\Routing\ModelRouter;
use App\Ai\Value\LlmRequest;
use App\Ai\Value\LlmUsage;
use App\Ai\Value\ModelReference;
use App\Ai\Worker\AiJobQueue;
use App\Core\Config\PatchnotesConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Keeps the queue moving when the computer with the local model is not there (SPEC.md § 8.3).
 *
 * Two things can go wrong with a worker outside the deployment: it takes a job and disappears, or
 * it never shows up at all. The first is handled by expiring the lease, the second by running the
 * job on a cloud provider after the configured grace period — a laptop that is closed for the
 * weekend must not stop the alerts.
 */
#[AsMessageHandler]
final readonly class FallbackStaleAiJobsHandler
{
    public function __construct(
        private AiJobQueue $queue,
        private ModelRouter $router,
        private LlmClientRegistry $clients,
        private UsageRecorder $usage,
        private PatchnotesConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FallbackStaleAiJobs $message): void
    {
        $released = $this->queue->releaseExpiredLeases();

        if ($released > 0) {
            $this->logger->info('Returned AI jobs with an expired lease to the queue', ['jobs' => $released]);
        }

        $minutes = $this->fallbackAfterMinutes();

        if ($minutes <= 0) {
            return;
        }

        $threshold = new \DateTimeImmutable(\sprintf('-%d minutes', $minutes));

        foreach ($this->queue->pendingSince($threshold) as $job) {
            $this->fallback($job);
        }
    }

    private function fallback(AiJob $job): void
    {
        $model = $this->cloudModel($job);

        if (!$model instanceof ModelReference) {
            // Nothing to fall back to: the job keeps waiting for the worker, which is better than
            // failing it — an offline laptop is usually back within a day.
            return;
        }

        $request = LlmRequest::fromArray($job->payload())->withModel($model);
        $startedAt = microtime(true);

        try {
            $response = $this->clients->get($model->provider)->complete($request);
        } catch (LlmException $exception) {
            $this->logger->error('Cloud fallback for a local AI job failed', [
                'job' => $job->id(),
                'model' => (string) $model,
                'error' => $exception->getMessage(),
            ]);
            $this->usage->record($job->task(), $model, new LlmUsage(), 0, false);

            return;
        }

        $this->usage->record(
            $job->task(),
            $model,
            $response->usage,
            (int) round((microtime(true) - $startedAt) * 1000),
            true,
        );

        $this->queue->complete($job, ['content' => $response->content, 'fell_back' => true], (string) $model);

        $this->logger->warning('A local AI job was completed by a cloud provider instead', [
            'job' => $job->id(),
            'task' => $job->task()->value,
            'model' => (string) $model,
        ]);
    }

    private function cloudModel(AiJob $job): ?ModelReference
    {
        foreach ($this->router->route($job->task())->chain as $model) {
            if (ExecutionMode::Direct === $this->clients->executionMode($model->provider)) {
                return $model;
            }
        }

        return null;
    }

    private function fallbackAfterMinutes(): int
    {
        $minutes = $this->config->ai()['local_worker_fallback_after_minutes'] ?? 60;

        return is_numeric($minutes) ? (int) $minutes : 60;
    }
}
