<?php

declare(strict_types=1);

namespace App\Content\Enum;

/**
 * Runtime state derived from dates.effective and "now" in Europe/Berlin (SPEC.md § 24.6).
 * Cached in the database by the daily 00:05 job, never written to git.
 */
enum EffectiveState: string
{
    case Upcoming = 'upcoming';
    case PartiallyInForce = 'partially_in_force';
    case InForce = 'in_force';
    /** No entry-into-force date is known (yet), e.g. a bill under discussion. */
    case Unknown = 'unknown';
}
