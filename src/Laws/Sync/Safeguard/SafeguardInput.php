<?php

declare(strict_types=1);

namespace App\Laws\Sync\Safeguard;

use App\Laws\Sync\LawSyncResult;

/**
 * Everything the safeguards of SPEC.md § 4.6 need to judge one synchronisation run.
 */
final readonly class SafeguardInput
{
    /**
     * @param list<LawSyncResult>   $laws               laws touched by this run
     * @param array<string, string> $files              produced files: path => content
     * @param int                   $lawsInJurisdiction total number of laws known for the jurisdiction
     * @param bool                  $deterministic      re-conversion produced identical output
     * @param list<string>          $repealedAtSource   slugs the source itself marks as repealed
     */
    public function __construct(
        public array $laws,
        public array $files,
        public int $lawsInJurisdiction,
        public bool $deterministic = true,
        public array $repealedAtSource = [],
    ) {
    }

    public function sourceMarksRepealed(string $slug): bool
    {
        return \in_array($slug, $this->repealedAtSource, true);
    }
}
