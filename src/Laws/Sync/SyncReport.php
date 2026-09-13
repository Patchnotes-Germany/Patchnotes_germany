<?php

declare(strict_types=1);

namespace App\Laws\Sync;

use App\Laws\Sync\Safeguard\SafeguardReport;

/**
 * What one synchronisation run did — the summary for the console, the `SourceRun` row and the
 * admin (SPEC.md § 6.1).
 */
final readonly class SyncReport
{
    /**
     * @param list<LawSyncResult>  $results
     * @param list<LawChangeGroup> $groups
     * @param list<string>         $changeRequests branches or pull request URLs that were opened
     */
    public function __construct(
        public array $results,
        public array $groups,
        public array $changeRequests,
        public SafeguardReport $safeguards,
        public bool $merged,
        public string $correlationId,
    ) {
    }

    public function documentsSeen(): int
    {
        return \count($this->results);
    }

    public function documentsChanged(): int
    {
        return \count(array_filter($this->results, static fn (LawSyncResult $r): bool => $r->changesTheRepository()));
    }

    public function failures(): int
    {
        return \count(array_filter($this->results, static fn (LawSyncResult $r): bool => SyncOutcome::Failed === $r->outcome));
    }

    /**
     * @return list<LawSyncResult>
     */
    public function failed(): array
    {
        return array_values(array_filter($this->results, static fn (LawSyncResult $r): bool => SyncOutcome::Failed === $r->outcome));
    }

    public function countOf(SyncOutcome $outcome): int
    {
        return \count(array_filter($this->results, static fn (LawSyncResult $r): bool => $outcome === $r->outcome));
    }

    public function isSuccessful(): bool
    {
        return 0 === $this->failures();
    }
}
