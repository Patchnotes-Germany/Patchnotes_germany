<?php

declare(strict_types=1);

namespace App\Source\Storage;

use App\Source\Value\RawDocument;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Keeps every byte a source ever delivered (SPEC.md § 6.1).
 *
 * Layout: `var/storage/raw/{source}/{YYYY}/{MM}/{DD}/{hash}.{ext}`. The file name is the content
 * hash, so identical content is stored once and every changed version is kept indefinitely — that
 * is what lets us reproduce any conversion later and prove where a sentence in the laws repository
 * came from.
 */
final readonly class RawDocumentStorage
{
    private Filesystem $filesystem;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/storage/raw')]
        private string $basePath,
        private LoggerInterface $logger,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * Stores the document unless its content is already known, and returns it with the path set.
     */
    public function store(string $sourceKey, RawDocument $document): RawDocument
    {
        if ($document->unchanged || '' === $document->content) {
            return $document;
        }

        $relative = $this->pathFor($sourceKey, $document);
        $absolute = $this->basePath.'/'.$relative;

        if (is_file($absolute)) {
            // Content-addressed: the same bytes are never written twice.
            return $document->withStoragePath($relative);
        }

        $this->filesystem->dumpFile($absolute, $document->content);
        $this->logger->debug('Stored raw document', [
            'source' => $sourceKey,
            'document' => $document->ref->id,
            'path' => $relative,
            'bytes' => $document->size(),
        ]);

        return $document->withStoragePath($relative);
    }

    public function read(string $relativePath): ?string
    {
        $absolute = $this->basePath.'/'.ltrim($relativePath, '/');
        if (!is_file($absolute)) {
            return null;
        }

        $content = file_get_contents($absolute);

        return false === $content ? null : $content;
    }

    public function exists(string $relativePath): bool
    {
        return is_file($this->basePath.'/'.ltrim($relativePath, '/'));
    }

    public function absolutePath(string $relativePath): string
    {
        return $this->basePath.'/'.ltrim($relativePath, '/');
    }

    private function pathFor(string $sourceKey, RawDocument $document): string
    {
        return \sprintf(
            '%s/%s/%s.%s',
            $this->sanitise($sourceKey),
            $document->fetchedAt->format('Y/m/d'),
            $document->contentHash,
            $this->extension($document),
        );
    }

    private function extension(RawDocument $document): string
    {
        if (str_starts_with($document->content, "PK\x03\x04")) {
            return 'zip';
        }
        if (str_starts_with($document->content, '%PDF')) {
            return 'pdf';
        }

        return match (true) {
            str_contains($document->contentType, 'xml') => 'xml',
            str_contains($document->contentType, 'json') => 'json',
            str_contains($document->contentType, 'html') => 'html',
            str_contains($document->contentType, 'pdf') => 'pdf',
            str_contains($document->contentType, 'zip') => 'zip',
            default => 'bin',
        };
    }

    private function sanitise(string $value): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $value), '-');
    }
}
