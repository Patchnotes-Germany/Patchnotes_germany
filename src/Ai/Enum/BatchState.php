<?php

declare(strict_types=1);

namespace App\Ai\Enum;

/**
 * State of a provider-side batch (SPEC.md § 24.14).
 *
 * Batches are cheap but slow — hours, not seconds — so they are used for bootstrap, backfill and
 * pre-translation only, never for the live pipeline.
 */
enum BatchState: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isFinished(): bool
    {
        return match ($this) {
            self::Pending, self::InProgress => false,
            default => true,
        };
    }
}
