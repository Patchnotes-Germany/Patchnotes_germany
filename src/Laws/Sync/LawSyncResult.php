<?php

declare(strict_types=1);

namespace App\Laws\Sync;

/**
 * The outcome for one law, and everything the grouping and the safeguards need afterwards.
 */
final readonly class LawSyncResult
{
    /**
     * @param list<string> $writtenPaths files written for this law, relative to the repository root
     */
    public function __construct(
        public string $slug,
        public SyncOutcome $outcome,
        public string $title,
        /** Change id derived from the source's "Stand" note; null when no act is identifiable. */
        public ?string $changeId = null,
        /** Canonical citation of the act, e.g. "BGBl. 2026 I Nr. 221" (SPEC.md § 24.1). */
        public ?string $amendingAct = null,
        /** The source note the citation was parsed from, kept verbatim for the pull request body. */
        public ?string $amendingActNote = null,
        public array $writtenPaths = [],
        public int $bytesBefore = 0,
        public int $bytesAfter = 0,
        /** Hash of the produced text, used for the fallback change id of § 24.1. */
        public string $contentHash = '',
        public ?string $error = null,
    ) {
    }

    public static function unchanged(string $slug, string $title): self
    {
        return new self($slug, SyncOutcome::Unchanged, $title);
    }

    public static function failed(string $slug, string $title, string $error): self
    {
        return new self($slug, SyncOutcome::Failed, $title, error: $error);
    }

    public function changesTheRepository(): bool
    {
        return $this->outcome->changesTheRepository();
    }

    /**
     * Share of the previous text that disappeared — the input for the parser-breakage safeguard
     * of SPEC.md § 4.6.
     */
    public function deletionRatio(): float
    {
        if ($this->bytesBefore <= 0) {
            return 0.0;
        }

        $removed = $this->bytesBefore - $this->bytesAfter;

        return $removed > 0 ? $removed / $this->bytesBefore : 0.0;
    }
}
