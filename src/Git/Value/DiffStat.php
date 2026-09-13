<?php

declare(strict_types=1);

namespace App\Git\Value;

/**
 * Summary of a diff, used in pull request descriptions and by the safeguards of SPEC.md § 4.6
 * (a synchronisation that deletes too much must not be merged automatically).
 */
final readonly class DiffStat
{
    /**
     * @param array<string, array{added: int, removed: int}> $files
     */
    public function __construct(
        public array $files,
        public int $insertions,
        public int $deletions,
    ) {
    }

    public static function empty(): self
    {
        return new self([], 0, 0);
    }

    public function fileCount(): int
    {
        return \count($this->files);
    }

    public function isEmpty(): bool
    {
        return [] === $this->files;
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_keys($this->files);
    }
}
