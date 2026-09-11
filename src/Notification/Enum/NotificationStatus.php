<?php

declare(strict_types=1);

namespace App\Notification\Enum;

enum NotificationStatus: string
{
    case Scheduled = 'scheduled';
    /** Held back by quiet hours or the daily limit; will be sent later or folded into the digest. */
    case Deferred = 'deferred';
    case Sent = 'sent';
    case Failed = 'failed';
    /** Dropped on purpose, e.g. the user unsubscribed before the send window. */
    case Cancelled = 'cancelled';
}
