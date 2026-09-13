<?php

declare(strict_types=1);

namespace App\Laws\Sync;

/**
 * Outcome of the first import of a jurisdiction (SPEC.md § 4.7).
 */
final readonly class BaselineReport
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public string $jurisdiction,
        public int $laws,
        public int $norms,
        public ?string $commit,
        public bool $skipped,
        public array $errors = [],
    ) {
    }

    public static function skipped(string $jurisdiction): self
    {
        return new self($jurisdiction, 0, 0, null, true);
    }

    public function isSuccessful(): bool
    {
        return [] === $this->errors;
    }
}
