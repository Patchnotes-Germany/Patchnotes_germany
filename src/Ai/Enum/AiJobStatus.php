<?php

declare(strict_types=1);

namespace App\Ai\Enum;

/**
 * Lifecycle of a job handed to a remote AI worker (SPEC.md § 8.3).
 *
 * Leases expire, so a worker that goes offline mid-job returns it to the queue; after
 * ai.local_worker_fallback_after_minutes the job falls back to a cloud provider.
 */
enum AiJobStatus: string
{
    case Pending = 'pending';
    case Leased = 'leased';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case FellBack = 'fell_back';
}
