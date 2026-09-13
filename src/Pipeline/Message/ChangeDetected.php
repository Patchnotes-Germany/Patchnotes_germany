<?php

declare(strict_types=1);

namespace App\Pipeline\Message;

/**
 * A merged pull request in the `laws` repository turned out to be a change of the law
 * (SPEC.md § 7.1).
 *
 * The first event of the pipeline. Everything after it works from the `Change` row, so replaying
 * this message is harmless: the change is found by its natural id and updated, never duplicated.
 */
final readonly class ChangeDetected
{
    public function __construct(
        public string $changeId,
        public ?string $correlationId = null,
    ) {
    }
}
