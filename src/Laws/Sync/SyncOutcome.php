<?php

declare(strict_types=1);

namespace App\Laws\Sync;

/**
 * What one synchronisation did to one law (SPEC.md § 6.2 A).
 */
enum SyncOutcome: string
{
    /** The source delivered nothing new — the normal case, and it must produce no commit. */
    case Unchanged = 'unchanged';
    case Created = 'created';
    case Updated = 'updated';
    /** Confirmed as repealed and moved to `_repealed/` (SPEC.md § 24.3). */
    case Repealed = 'repealed';
    /** Absent from the source, but not yet confirmed often enough to act on it. */
    case Missing = 'missing';
    case Failed = 'failed';

    public function changesTheRepository(): bool
    {
        return \in_array($this, [self::Created, self::Updated, self::Repealed], true);
    }
}
