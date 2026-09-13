<?php

declare(strict_types=1);

namespace App\Ai\Enum;

/**
 * Whether an AI call produced an answer or a promise of one (SPEC.md § 8.3).
 */
enum AiResultStatus: string
{
    case Completed = 'completed';
    /** Handed to a remote worker; the pipeline continues when the result is posted back. */
    case Queued = 'queued';
}
