<?php

declare(strict_types=1);

namespace App\Pipeline\Message;

/**
 * Check the facts of a change against the German source (SPEC.md § 7.1, § 7.4 check 1).
 *
 * A separate stage from the analysis on purpose: it is deterministic, it is the check that can stop
 * a wrong figure from reaching a reader, and keeping it separate means it can be re-run after a
 * correction without paying for the analysis again.
 */
final readonly class VerifyChangeFacts
{
    public function __construct(public string $changeId)
    {
    }
}
