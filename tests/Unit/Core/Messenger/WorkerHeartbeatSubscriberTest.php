<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Messenger;

use App\Core\Messenger\WorkerHeartbeatSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

#[CoversClass(WorkerHeartbeatSubscriber::class)]
final class WorkerHeartbeatSubscriberTest extends TestCase
{
    private string $heartbeatFile;

    protected function setUp(): void
    {
        $this->heartbeatFile = sys_get_temp_dir().'/patchnotes-heartbeat-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->heartbeatFile);
    }

    public function testItSubscribesToTheWorkerLifecycleEvents(): void
    {
        self::assertSame(
            [WorkerStartedEvent::class, WorkerRunningEvent::class],
            array_keys(WorkerHeartbeatSubscriber::getSubscribedEvents()),
        );
    }

    public function testItWritesTheHeartbeatFileWhenTheWorkerStarts(): void
    {
        $subscriber = new WorkerHeartbeatSubscriber($this->heartbeatFile, 10.0);

        $subscriber->onWorkerStarted();

        self::assertFileExists($this->heartbeatFile);
    }

    public function testItThrottlesWritesWhileTheWorkerLoops(): void
    {
        $subscriber = new WorkerHeartbeatSubscriber($this->heartbeatFile, 10.0);
        $subscriber->onWorkerStarted();

        // Simulate a heartbeat written a while ago: within the interval it must not be refreshed.
        $stale = time() - 100;
        touch($this->heartbeatFile, $stale);
        clearstatcache(true, $this->heartbeatFile);

        $subscriber->onWorkerRunning();

        clearstatcache(true, $this->heartbeatFile);
        self::assertSame($stale, filemtime($this->heartbeatFile));
    }

    public function testItRefreshesTheHeartbeatOnceTheIntervalHasElapsed(): void
    {
        $subscriber = new WorkerHeartbeatSubscriber($this->heartbeatFile, 0.0);
        $subscriber->onWorkerStarted();

        touch($this->heartbeatFile, time() - 100);
        clearstatcache(true, $this->heartbeatFile);

        $subscriber->onWorkerRunning();

        clearstatcache(true, $this->heartbeatFile);
        self::assertGreaterThan(time() - 5, (int) filemtime($this->heartbeatFile));
    }
}
