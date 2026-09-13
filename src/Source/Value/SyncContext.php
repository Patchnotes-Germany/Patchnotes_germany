<?php

declare(strict_types=1);

namespace App\Source\Value;

/**
 * The parameters of one synchronisation run (SPEC.md § 6.1).
 *
 * The correlation id ties every log line, raw document, pull request and pipeline message of the
 * run together (SPEC.md § 19).
 */
final readonly class SyncContext
{
    /**
     * @param list<string>|null $only
     */
    public function __construct(
        public string $correlationId,
        /** Ignore stored fingerprints and re-download everything. */
        public bool $force = false,
        /** Stop after this many documents; used by tests and manual runs. */
        public ?int $limit = null,
        /** Only these document ids (law slugs), for targeted re-imports. */
        public ?array $only = null,
        /** Do not write anything: fetch, convert and report what would change. */
        public bool $dryRun = false,
    ) {
    }

    public static function forRun(?string $correlationId = null): self
    {
        return new self($correlationId ?? bin2hex(random_bytes(8)));
    }

    public function wants(string $documentId): bool
    {
        return null === $this->only || \in_array($documentId, $this->only, true);
    }
}
