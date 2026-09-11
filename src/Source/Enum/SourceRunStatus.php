<?php

declare(strict_types=1);

namespace App\Source\Enum;

/**
 * Lifecycle of one synchronisation run of a source adapter (SPEC.md § 6.1).
 */
enum SourceRunStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';
    /** The run did not start because another run holds the lock. */
    case Skipped = 'skipped';
}
