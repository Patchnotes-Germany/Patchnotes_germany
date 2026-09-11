<?php

declare(strict_types=1);

namespace App\Git\Enum;

enum ChangeRequestStatus: string
{
    case Open = 'open';
    case Draft = 'draft';
    case Merged = 'merged';
    case Closed = 'closed';
    /** Checks failed or a safeguard tripped: waiting for a human (SPEC.md § 4.6). */
    case NeedsReview = 'needs_review';
}
