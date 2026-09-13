<?php

declare(strict_types=1);

namespace App\Source\Storage;

/**
 * What we knew about a document after the previous run (SPEC.md § 6.1).
 */
final readonly class DocumentFingerprint
{
    public function __construct(
        public string $urlHash,
        public string $contentHash,
        public ?string $etag,
        public ?string $lastModified,
    ) {
    }
}
