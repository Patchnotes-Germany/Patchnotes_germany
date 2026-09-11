<?php

declare(strict_types=1);

namespace App\Core\Scheduler\Message;

/**
 * Dispatched every 15 minutes by MainSchedule to prove that the scheduler is alive.
 */
final class SchedulerHeartbeat
{
}
