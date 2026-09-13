<?php

declare(strict_types=1);

namespace App\Content\Value;

/**
 * A citation of the act that last changed a law, parsed out of the source's "Stand" note
 * (SPEC.md § 24.1, § 6.2 A).
 *
 * This is the hinge of the whole grouping logic: the same act must produce the same change id no
 * matter whether it arrives from the gazette (BGBl) or from the consolidated text of a law, because
 * that id decides which changes end up in one pull request and in one card.
 */
final readonly class AmendingActReference
{
    public function __construct(
        /** "G" (Gesetz), "V" (Verordnung), "Bek." (Bekanntmachung/re-publication). */
        public AmendingActKind $kind,
        /** Date of the act, a legal date without time zone. */
        public \DateTimeImmutable $date,
        /** Part of the Bundesgesetzblatt: "I" or "II". */
        public string $part,
        /** Issue number, used since the electronic BGBl of 2023 ("I Nr. 221"). */
        public ?int $number,
        /** Page, used by citations before 2023 ("I 1762"). */
        public ?int $page,
        /** "Art. 11 Abs. 17", when the note names the amending article. */
        public ?string $article,
        /** The note the citation was parsed from, kept verbatim for the audit trail. */
        public string $sourceText,
    ) {
    }

    /**
     * Change id of SPEC.md § 24.1: `{year}-{jurisdiction}-{gazette}-{number}`.
     *
     * Citations that predate the electronic gazette have no issue number; their page is used
     * instead, prefixed with "s" so the two numbering schemes can never collide.
     */
    public function changeId(string $jurisdiction = 'bund'): string
    {
        $gazette = 'bgbl-'.strtolower($this->part);
        $identifier = null !== $this->number ? (string) $this->number : 's'.$this->page;

        return \sprintf('%d-%s-%s-%s', (int) $this->date->format('Y'), $jurisdiction, $gazette, $identifier);
    }

    /**
     * Canonical citation: "BGBl. 2026 I Nr. 221" for the electronic gazette, "BGBl. I 2015, 1474"
     * for the printed one.
     */
    public function citation(): string
    {
        if (null !== $this->number) {
            return \sprintf('BGBl. %d %s Nr. %d', (int) $this->date->format('Y'), $this->part, $this->number);
        }

        return \sprintf('BGBl. %s %d, %d', $this->part, (int) $this->date->format('Y'), (int) $this->page);
    }

    /**
     * ELI permalink of the electronic Bundesgesetzblatt; null for pre-2023 citations, which have no
     * stable URL.
     */
    public function url(): ?string
    {
        if (null === $this->number) {
            return null;
        }

        return \sprintf(
            'https://www.recht.bund.de/eli/bund/BGBl-%s/%d/%d/',
            'I' === $this->part ? '1' : '2',
            (int) $this->date->format('Y'),
            $this->number,
        );
    }

    public function isRepublication(): bool
    {
        return AmendingActKind::Bekanntmachung === $this->kind;
    }
}
