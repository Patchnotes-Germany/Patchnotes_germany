<?php

declare(strict_types=1);

namespace App\Laws\Sync;

/**
 * Groups the laws changed in one run by the act that changed them (SPEC.md § 4.5).
 *
 * "All laws changed by one act in one synchronisation run go into **one** pull request." That is
 * what makes the history readable: one amending act, one merge, one card — instead of a dozen
 * unrelated commits that a reader has to piece together.
 *
 * Laws whose "Stand" note names no identifiable act fall back to a per-law group with the id of
 * SPEC.md § 24.1: `{YYYY-MM-DD}-{jurisdiction}-{law-slug}-{sha8 of the diff}`.
 */
final readonly class AmendingActGrouper
{
    public function __construct(private string $jurisdiction = 'bund')
    {
    }

    /**
     * @param list<LawSyncResult> $results
     *
     * @return list<LawChangeGroup> in a stable order: by change id
     */
    public function group(array $results, \DateTimeImmutable $runDate): array
    {
        /** @var array<string, list<LawSyncResult>> $groups */
        $groups = [];
        /** @var array<string, ?string> $citations */
        $citations = [];
        /** @var array<string, ?string> $notes */
        $notes = [];

        foreach ($results as $result) {
            if (!$result->changesTheRepository()) {
                continue;
            }

            $id = $result->changeId ?? $this->fallbackId($result, $runDate);
            $groups[$id][] = $result;
            $citations[$id] ??= $result->amendingAct;
            $notes[$id] ??= $result->amendingActNote;
        }

        ksort($groups);

        $changeGroups = [];
        foreach ($groups as $id => $laws) {
            $changeGroups[] = new LawChangeGroup(
                $id,
                $this->jurisdiction,
                $citations[$id] ?? null,
                $laws,
                $runDate,
                $notes[$id] ?? null,
            );
        }

        return $changeGroups;
    }

    /**
     * Without an identifiable act the change is attributed to the law itself; the diff hash keeps
     * the id stable for the same content and distinct for a different one.
     */
    private function fallbackId(LawSyncResult $result, \DateTimeImmutable $runDate): string
    {
        $hash = '' !== $result->contentHash ? $result->contentHash : hash('sha256', $result->slug);

        return \sprintf(
            '%s-%s-%s-%s',
            $runDate->format('Y-m-d'),
            $this->jurisdiction,
            $result->slug,
            substr($hash, 0, 8),
        );
    }
}
