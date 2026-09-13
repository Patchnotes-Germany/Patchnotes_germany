<?php

declare(strict_types=1);

namespace App\Git\Forge;

/**
 * What to open at the forge (SPEC.md § 4.5, § 5.6).
 *
 * Preview and bill pull requests are opened as drafts where the forge supports it, because they
 * must never be merged automatically.
 */
final readonly class ChangeRequestDraft
{
    /**
     * @param list<string> $labels
     */
    public function __construct(
        public string $branch,
        public string $baseBranch,
        public string $title,
        public string $body,
        public array $labels = [],
        public bool $draft = false,
    ) {
    }
}
