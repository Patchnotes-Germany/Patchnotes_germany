<?php

declare(strict_types=1);

namespace App\Content\Card;

use App\Content\Entity\Card;

/**
 * A card file as read from the repository (SPEC.md § 5.4).
 */
final readonly class ParsedCard
{
    /**
     * @param array<string, mixed>  $frontMatter lang, master_hash, translation, translated_by, …
     * @param array<string, string> $sections    section key => body, placeholders untouched
     * @param array<string, string> $headings    section key => the localized heading as written
     * @param list<string>          $unknownKeys anchors that are not part of the fixed set
     */
    public function __construct(
        public array $frontMatter,
        public array $sections,
        public array $headings = [],
        public array $unknownKeys = [],
    ) {
    }

    public function lang(): ?string
    {
        $lang = $this->frontMatter['lang'] ?? null;

        return \is_string($lang) && '' !== $lang ? $lang : null;
    }

    public function masterHash(): ?string
    {
        $hash = $this->frontMatter['master_hash'] ?? null;

        return \is_string($hash) && '' !== $hash ? $hash : null;
    }

    public function section(string $key): ?string
    {
        return $this->sections[$key] ?? null;
    }

    public function summary(): ?string
    {
        return $this->sections['summary'] ?? null;
    }

    /**
     * The section keys of § 24.13 that carry no text. Everything but the summary may be empty.
     *
     * @return list<string>
     */
    public function emptySections(): array
    {
        $empty = [];

        foreach (Card::SECTION_KEYS as $key) {
            if ('' === trim($this->sections[$key] ?? '')) {
                $empty[] = $key;
            }
        }

        return $empty;
    }

    public function isUsable(): bool
    {
        return [] === $this->unknownKeys && '' !== trim($this->sections['summary'] ?? '');
    }
}
