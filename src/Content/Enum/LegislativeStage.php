<?php

declare(strict_types=1);

namespace App\Content\Enum;

/**
 * The legislative stage stored in git (SPEC.md § 24.6).
 *
 * "in force", "partially in force" and "upcoming" are NOT stages: they are derived at runtime from
 * dates.effective and the current date in Europe/Berlin.
 */
enum LegislativeStage: string
{
    case Discussed = 'discussed';
    case Adopted = 'adopted';
    case Promulgated = 'promulgated';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function isFinalNegative(): bool
    {
        return self::Rejected === $this || self::Withdrawn === $this;
    }
}
