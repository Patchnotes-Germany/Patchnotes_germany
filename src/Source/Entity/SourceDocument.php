<?php

declare(strict_types=1);

namespace App\Source\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A raw document exactly as fetched from a source (SPEC.md § 6.1).
 *
 * The bytes live in var/storage/raw/{source}/{Y}/{m}/{d}/{hash}.{ext}; this row proves where a text
 * came from and lets any conversion be reproduced. Identical content is stored once (hash), while
 * every changed version is kept indefinitely.
 */
#[ORM\Entity]
#[ORM\Table(name: 'source_document')]
#[ORM\UniqueConstraint(name: 'uniq_source_document_hash', columns: ['source_key', 'content_hash'])]
#[ORM\Index(name: 'idx_source_document_url', columns: ['source_key', 'url_hash'])]
#[ORM\Index(name: 'idx_source_document_fetched', columns: ['fetched_at'])]
class SourceDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Source::class)]
    #[ORM\JoinColumn(name: 'source_key', referencedColumnName: 'source_key', nullable: false)]
    private Source $source;

    #[ORM\Column(type: Types::TEXT)]
    private string $url;

    /** sha256 of the URL: TEXT cannot be indexed as a whole. */
    #[ORM\Column(length: 64, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $urlHash;

    #[ORM\Column(length: 64, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $contentHash;

    #[ORM\Column(length: 512)]
    private string $storagePath;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $fetchedAt;

    #[ORM\Column]
    private int $httpStatus;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $contentType = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $sizeBytes = 0;

    /** Conditional GET metadata for the next run (SPEC.md § 6.1). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $etag = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastModified = null;

    public function __construct(
        Source $source,
        string $url,
        string $contentHash,
        string $storagePath,
        int $httpStatus,
    ) {
        $this->source = $source;
        $this->url = $url;
        $this->urlHash = hash('sha256', $url);
        $this->contentHash = $contentHash;
        $this->storagePath = $storagePath;
        $this->httpStatus = $httpStatus;
        $this->fetchedAt = new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function source(): Source
    {
        return $this->source;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function urlHash(): string
    {
        return $this->urlHash;
    }

    public function contentHash(): string
    {
        return $this->contentHash;
    }

    public function storagePath(): string
    {
        return $this->storagePath;
    }

    public function fetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function contentType(): ?string
    {
        return $this->contentType;
    }

    public function setContentType(?string $contentType): void
    {
        $this->contentType = $contentType;
    }

    public function sizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function setSizeBytes(int $sizeBytes): void
    {
        $this->sizeBytes = $sizeBytes;
    }

    public function etag(): ?string
    {
        return $this->etag;
    }

    public function lastModified(): ?string
    {
        return $this->lastModified;
    }

    public function setConditionalHeaders(?string $etag, ?string $lastModified): void
    {
        $this->etag = $etag;
        $this->lastModified = $lastModified;
    }
}
