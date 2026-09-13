<?php

declare(strict_types=1);

namespace App\Laws\Value;

use App\Laws\Enum\LawStatus;
use App\Laws\Enum\LawType;

/**
 * A law as it will be written into the `laws` repository: `_law.yml` plus one Markdown file per
 * norm (SPEC.md § 4.1–4.3).
 */
final readonly class NormalizedLaw
{
    /**
     * @param list<NormalizedNorm>       $norms
     * @param list<array<string, mixed>> $structure         Teil/Kapitel/Abschnitt tree (§ 24.1)
     * @param list<string>               $pendingAmendments "Hinweis" notes: changes announced
     *                                                      but not yet incorporated (§ 6.2 A)
     */
    public function __construct(
        public string $slug,
        public string $jurisdiction,
        public LawType $type,
        public LawStatus $status,
        public string $title,
        public ?string $shortTitle,
        public ?string $abbreviation,
        public ?string $officialAbbreviation,
        public ?\DateTimeImmutable $dateOfIssue,
        public ?string $promulgation,
        public ?string $statusNote,
        public ?string $lastAmendingAct,
        public string $sourceName,
        public string $sourceUrl,
        public ?string $sourceDocumentId,
        public array $norms,
        public array $structure = [],
        public array $pendingAmendments = [],
    ) {
    }

    /** Reference prefix used everywhere: "bund/aufenthg_2004" (SPEC.md § 24.1). */
    public function reference(): string
    {
        return $this->jurisdiction.'/'.$this->slug;
    }

    /**
     * @return list<string>
     */
    public function normKeys(): array
    {
        return array_map(static fn (NormalizedNorm $norm): string => $norm->key, $this->norms);
    }

    public function norm(string $key): ?NormalizedNorm
    {
        foreach ($this->norms as $norm) {
            if ($norm->key === $key) {
                return $norm;
            }
        }

        return null;
    }
}
