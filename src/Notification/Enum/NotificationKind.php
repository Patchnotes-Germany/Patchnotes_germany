<?php

declare(strict_types=1);

namespace App\Notification\Enum;

/**
 * Notification types of SPEC.md § 12.1.
 */
enum NotificationKind: string
{
    case ChangeDirect = 'change_direct';
    case EffectiveReminder = 'effective_reminder';
    case EffectiveToday = 'effective_today';
    case BillUpdate = 'bill_update';
    case WeeklyDigest = 'weekly_digest';
    case Correction = 'correction';

    /** Instant alerts count against the daily limit and respect quiet hours. */
    public function isInstant(): bool
    {
        return \in_array($this, [self::ChangeDirect, self::BillUpdate, self::Correction], true);
    }
}
