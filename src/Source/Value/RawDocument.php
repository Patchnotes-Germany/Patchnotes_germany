<?php

declare(strict_types=1);

namespace App\Source\Value;

/**
 * Raw bytes exactly as delivered by a source (SPEC.md § 6.1).
 *
 * Everything the pipeline later claims about a law must be reproducible from this: the bytes are
 * stored under var/storage/raw/… and recorded as a SourceDocument row, so any conversion can be
 * replayed and the origin of every sentence can be proven.
 */
final readonly class RawDocument
{
    public function __construct(
        public DocumentRef $ref,
        public string $content,
        public string $contentHash,
        public string $contentType,
        public int $httpStatus,
        public \DateTimeImmutable $fetchedAt,
        public ?string $etag = null,
        public ?string $lastModified = null,
        /** Path inside the storage volume; null until the document has been stored. */
        public ?string $storagePath = null,
        /** True when the source answered 304 or the hash equals the previous one. */
        public bool $unchanged = false,
    ) {
    }

    public static function unchanged(DocumentRef $ref, \DateTimeImmutable $fetchedAt): self
    {
        return new self(
            $ref,
            '',
            $ref->knownHash ?? '',
            '',
            304,
            $fetchedAt,
            $ref->etag,
            $ref->lastModified,
            null,
            true,
        );
    }

    public function withStoragePath(string $storagePath): self
    {
        return new self(
            $this->ref,
            $this->content,
            $this->contentHash,
            $this->contentType,
            $this->httpStatus,
            $this->fetchedAt,
            $this->etag,
            $this->lastModified,
            $storagePath,
            $this->unchanged,
        );
    }

    public function size(): int
    {
        return \strlen($this->content);
    }
}
