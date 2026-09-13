<?php

declare(strict_types=1);

namespace App\Git\Value;

/**
 * One line of `git blame`: which change introduced this sentence (SPEC.md § 13.2, blame view).
 *
 * Because law texts are stored with one sentence per line, blame answers "which amendment changed
 * this sentence" instead of "which amendment touched this paragraph".
 */
final readonly class BlameLine
{
    public function __construct(
        public int $lineNumber,
        public string $commit,
        public \DateTimeImmutable $date,
        public string $summary,
        public string $content,
        public ?string $changeId = null,
    ) {
    }
}
