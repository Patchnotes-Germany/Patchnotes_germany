<?php

declare(strict_types=1);

namespace App\Pipeline\Message;

/**
 * Translate the master card into the other languages (SPEC.md § 7.1, § 24.15).
 *
 * The languages come from `patchnotes.languages`, never from a list in the code. A single language
 * can be requested on its own, which is what a stale translation (§ 5.4) and a re-run after a
 * correction need.
 */
final readonly class TranslateCards
{
    public function __construct(
        public string $changeId,
        /** Null means every language except the master. */
        public ?string $language = null,
    ) {
    }
}
