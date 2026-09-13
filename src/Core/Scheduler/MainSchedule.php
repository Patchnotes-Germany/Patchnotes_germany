<?php

declare(strict_types=1);

namespace App\Core\Scheduler;

use App\Core\Scheduler\Message\SchedulerHeartbeat;
use App\Git\Message\SynchroniseAllRepositories;
use App\Source\Message\SynchroniseBundLaws;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The single application schedule (SPEC.md § 11.1).
 *
 * The scheduler container only redispatches messages into the work transports; it never runs long
 * jobs itself. The schedule is stateful (missed runs survive a restart) and locked, so exactly one
 * scheduler instance dispatches a given run.
 *
 * Tasks are added milestone by milestone: sources sync (M3/M6/M11), pipeline maintenance (M5),
 * notifications and digests (M9), health checks and budget alerts (M10).
 */
#[AsSchedule('default')]
final readonly class MainSchedule implements ScheduleProviderInterface
{
    /** All schedules are expressed in the legal time zone of Germany (SPEC.md § 24.16). */
    private const string TIMEZONE = 'Europe/Berlin';

    public function __construct(
        #[Autowire(service: 'cache.scheduler')]
        private CacheInterface $cache,
        private LockFactory $lockFactory,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return new Schedule()
            ->add(
                // Proof of life for the scheduler itself; /readyz and the admin dashboard read it.
                RecurringMessage::every('15 minutes', new RedispatchMessage(new SchedulerHeartbeat(), 'default')),
                // Fallback for missed forge webhooks (SPEC.md § 3.2).
                RecurringMessage::every('10 minutes', new RedispatchMessage(new SynchroniseAllRepositories(), 'git')),
                // Federal consolidated laws: nightly, with a second pass in the afternoon
                // (SPEC.md § 11.1). Never inside 02:00-03:00, which does not exist twice a year.
                RecurringMessage::cron('0 3 * * *', new RedispatchMessage(new SynchroniseBundLaws(), 'sources'), self::TIMEZONE),
                RecurringMessage::cron('0 15 * * *', new RedispatchMessage(new SynchroniseBundLaws(), 'sources'), self::TIMEZONE),
            )
            ->stateful($this->cache)
            ->lock($this->lockFactory->createLock('scheduler-default'))
            ->processOnlyLastMissedRun(true);
    }
}
