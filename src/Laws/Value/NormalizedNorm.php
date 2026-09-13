<?php

declare(strict_types=1);

namespace App\Laws\Value;

use App\Laws\Enum\NormStatus;

/**
 * One norm file of the `laws` repository (SPEC.md § 4.2): front matter plus the Markdown body,
 * one sentence per line.
 */
final readonly class NormalizedNorm
{
    public function __construct(
        /** File name without extension and canonical key: "p18g", "art3", "anl1", "n-{doknr}". */
        public string $key,
        public ?string $designation,
        public ?string $title,
        public NormStatus $status,
        /** The body without front matter, ending with exactly one newline. */
        public string $markdown,
        public ?string $sourceId = null,
        /** Position within the law, used to order `norms:` in `_law.yml`. */
        public int $position = 0,
    ) {
    }

    public function contentHash(): string
    {
        return hash('sha256', $this->markdown);
    }

    public function fileName(): string
    {
        return $this->key.'.md';
    }
}
