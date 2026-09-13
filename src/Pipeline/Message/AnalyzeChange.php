<?php

declare(strict_types=1);

namespace App\Pipeline\Message;

/**
 * Run `change_analyze` for a change: facts, audience, topics, impact (SPEC.md § 7.1, § 8.4).
 *
 * Dispatched when the settling window of § 24.4 has passed — that is, when every law the act
 * touches has caught up, or 72 hours after the first pull request, whichever comes first. A later
 * pull request for the same act dispatches it again, and the card is updated rather than duplicated.
 */
final readonly class AnalyzeChange
{
    public function __construct(
        public string $changeId,
        /** Analyse again even though the change has been analysed before. */
        public bool $force = false,
    ) {
    }
}
