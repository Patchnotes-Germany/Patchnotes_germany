<?php

declare(strict_types=1);

namespace App\Source\Http;

/**
 * One HTTP answer from a source, reduced to what the adapters need.
 */
final readonly class FetchResult
{
    public function __construct(
        public int $statusCode,
        public string $content,
        public string $contentType,
        public ?string $etag = null,
        public ?string $lastModified = null,
    ) {
    }

    public static function notModified(?string $etag, ?string $lastModified): self
    {
        return new self(304, '', '', $etag, $lastModified);
    }

    public function isNotModified(): bool
    {
        return 304 === $this->statusCode;
    }

    public function hash(): string
    {
        return hash('sha256', $this->content);
    }
}
