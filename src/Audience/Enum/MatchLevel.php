<?php

declare(strict_types=1);

namespace App\Audience\Enum;

/**
 * How strongly a change concerns a person (SPEC.md § 9.2).
 *
 * The match is deterministic — computed from audience tags, never by AI at delivery time — and is
 * always accompanied by an explanation of which of the person's own tags matched.
 */
enum MatchLevel: string
{
    /** 🔴 Concerns you. */
    case Direct = 'direct';
    /** 🟡 May concern you. */
    case Possible = 'possible';
    /** ⚪ Everything else within the person's geography. */
    case Other = 'other';
}
