<?php

declare(strict_types=1);

namespace App\Source\Message;

/**
 * Run the federal synchronisation (SPEC.md § 11.1: daily at 03:00 with a second pass at 15:00).
 */
final readonly class SynchroniseBundLaws
{
    /**
     * @param list<string>|null $only law slugs to restrict the run to
     */
    public function __construct(
        public bool $force = false,
        public ?int $limit = null,
        public ?array $only = null,
        public bool $dryRun = false,
        public ?string $correlationId = null,
    ) {
    }
}
