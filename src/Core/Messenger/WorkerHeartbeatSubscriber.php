<?php

declare(strict_types=1);

namespace App\Core\Messenger;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * Touches a heartbeat file on every worker loop so that Docker can tell a live consumer from a
 * hung one (see frankenphp/worker-healthcheck.sh and ADR 0003). Workers exit on --time-limit and
 * are restarted by the "restart: unless-stopped" policy.
 */
final class WorkerHeartbeatSubscriber implements EventSubscriberInterface
{
    private float $lastTouch = 0.0;

    public function __construct(
        private readonly string $heartbeatFile = '/tmp/messenger-worker.heartbeat',
        private readonly float $intervalSeconds = 10.0,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'onWorkerStarted',
            WorkerRunningEvent::class => 'onWorkerRunning',
        ];
    }

    public function onWorkerStarted(): void
    {
        $this->touch(force: true);
    }

    public function onWorkerRunning(): void
    {
        $this->touch(force: false);
    }

    private function touch(bool $force): void
    {
        $now = microtime(true);
        if (!$force && $now - $this->lastTouch < $this->intervalSeconds) {
            return;
        }

        $this->lastTouch = $now;

        // A failing heartbeat must never take the worker down: the healthcheck will report it.
        if (!@touch($this->heartbeatFile)) {
            @file_put_contents($this->heartbeatFile, (string) time());
        }
    }
}
