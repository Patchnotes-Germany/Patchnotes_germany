<?php

declare(strict_types=1);

namespace App\Source\Value;

/**
 * A document a source offers, plus what we knew about it after the last run.
 *
 * The fingerprints turn the daily synchronisation into a cheap conditional request: without a
 * change at the source there is no download, no commit and no notification (SPEC.md § 1.1, § 6.1).
 */
final readonly class DocumentRef
{
    public function __construct(
        /** Stable identifier within the source, e.g. the law slug "aufenthg_2004". */
        public string $id,
        public string $url,
        public ?string $title = null,
        /** ETag of the previous fetch, if any. */
        public ?string $etag = null,
        /** Last-Modified of the previous fetch, if any. */
        public ?string $lastModified = null,
        /** Content hash of the previous fetch, if any. */
        public ?string $knownHash = null,
        /**
         * Free-form source metadata (e.g. the toc title) carried to the normalizer.
         *
         * @var array<string, string>
         */
        public array $attributes = [],
    ) {
    }

    public function withFingerprints(?string $etag, ?string $lastModified, ?string $knownHash): self
    {
        return new self($this->id, $this->url, $this->title, $etag, $lastModified, $knownHash, $this->attributes);
    }

    public function hasFingerprints(): bool
    {
        return null !== $this->etag || null !== $this->lastModified;
    }
}
