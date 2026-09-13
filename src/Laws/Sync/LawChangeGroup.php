<?php

declare(strict_types=1);

namespace App\Laws\Sync;

/**
 * One amending act and the laws it changed in this run — the unit that becomes one branch, one
 * pull request and later one change card (SPEC.md § 4.5, § 7.2).
 */
final readonly class LawChangeGroup
{
    /**
     * @param list<LawSyncResult> $laws
     */
    public function __construct(
        public string $changeId,
        public string $jurisdiction,
        /** Canonical citation, e.g. "BGBl. 2026 I Nr. 221". */
        public ?string $amendingAct,
        public array $laws,
        public \DateTimeImmutable $runDate,
        /** The source note behind the citation, shown in the pull request body. */
        public ?string $amendingActNote = null,
    ) {
    }

    /** `sync/{jurisdiction}/{YYYY-MM-DD}/{change-id}` (SPEC.md § 4.5). */
    public function branch(): string
    {
        return \sprintf('sync/%s/%s/%s', $this->jurisdiction, $this->runDate->format('Y-m-d'), $this->changeId);
    }

    /**
     * "BGBl. 2026 I Nr. 123 — Änderung des Aufenthaltsgesetzes" or, without an identifiable act,
     * "Aktualisierung: {law}".
     */
    public function title(): string
    {
        $laws = implode(', ', array_map(static fn (LawSyncResult $law): string => $law->title, \array_slice($this->laws, 0, 3)));
        if (\count($this->laws) > 3) {
            $laws .= \sprintf(' und %d weitere', \count($this->laws) - 3);
        }

        if (null === $this->amendingAct) {
            return 'Aktualisierung: '.$laws;
        }

        return $this->citation().' — '.$laws;
    }

    /**
     * The canonical citation of SPEC.md § 24.1; without an identifiable act the change id stands in.
     */
    public function citation(): string
    {
        return $this->amendingAct ?? $this->changeId;
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        $labels = ['official-sync', 'jurisdiction:'.$this->jurisdiction];

        if (null !== $this->amendingAct) {
            $labels[] = 'amending-act';
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    public function slugs(): array
    {
        return array_map(static fn (LawSyncResult $law): string => $law->slug, $this->laws);
    }

    public function repealsOnly(): bool
    {
        foreach ($this->laws as $law) {
            if (SyncOutcome::Repealed !== $law->outcome) {
                return false;
            }
        }

        return [] !== $this->laws;
    }
}
