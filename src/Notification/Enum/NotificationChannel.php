<?php

declare(strict_types=1);

namespace App\Notification\Enum;

/**
 * Delivery channels (SPEC.md § 12.2). RSS and iCal are pull channels and therefore not scheduled
 * per user; the calendar feed is generated from the same data on request.
 */
enum NotificationChannel: string
{
    case Email = 'email';
    case Telegram = 'telegram';
    case WebPush = 'webpush';
    /** Public per-language Telegram channel, not a personal delivery. */
    case TelegramChannel = 'telegram_channel';
}
