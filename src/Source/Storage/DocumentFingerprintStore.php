<?php

declare(strict_types=1);

namespace App\Source\Storage;

use App\Source\Entity\Source;
use App\Source\Entity\SourceDocument;
use App\Source\Value\RawDocument;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Remembers what we already downloaded from a source (SPEC.md § 6.1).
 *
 * These fingerprints are what makes a daily synchronisation cheap and idempotent: with an ETag or a
 * Last-Modified the source answers 304, and even when it insists on sending the file again, the
 * content hash tells us that nothing actually changed.
 */
final readonly class DocumentFingerprintStore
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Latest fingerprint per document URL of one source.
     *
     * @return array<string, DocumentFingerprint> url hash => fingerprint
     */
    public function forSource(string $sourceKey): array
    {
        /** @var Connection $connection */
        $connection = $this->entityManager->getConnection();

        /** @var list<array{url_hash: string, content_hash: string, etag: ?string, last_modified: ?string}> $rows */
        $rows = $connection->fetchAllAssociative(
            <<<'SQL'
                SELECT d.url_hash, d.content_hash, d.etag, d.last_modified
                FROM source_document d
                INNER JOIN (
                    SELECT url_hash, MAX(fetched_at) AS fetched_at
                    FROM source_document
                    WHERE source_key = :source
                    GROUP BY url_hash
                ) latest ON latest.url_hash = d.url_hash AND latest.fetched_at = d.fetched_at
                WHERE d.source_key = :source
                SQL,
            ['source' => $sourceKey],
        );

        $fingerprints = [];
        foreach ($rows as $row) {
            $fingerprints[$row['url_hash']] = new DocumentFingerprint(
                $row['url_hash'],
                $row['content_hash'],
                $row['etag'],
                $row['last_modified'],
            );
        }

        return $fingerprints;
    }

    /**
     * Records a downloaded document. Identical content is not recorded twice: the pair
     * (source, content hash) is unique.
     */
    public function record(string $sourceKey, RawDocument $document): ?SourceDocument
    {
        if ($document->unchanged || null === $document->storagePath) {
            return null;
        }

        $source = $this->entityManager->find(Source::class, $sourceKey);
        if (!$source instanceof Source) {
            throw new \RuntimeException(\sprintf('Source "%s" is not registered.', $sourceKey));
        }

        $existing = $this->entityManager->getRepository(SourceDocument::class)->findOneBy([
            'source' => $source,
            'contentHash' => $document->contentHash,
        ]);

        if ($existing instanceof SourceDocument) {
            return $existing;
        }

        $record = new SourceDocument(
            $source,
            $document->ref->url,
            $document->contentHash,
            $document->storagePath,
            $document->httpStatus,
        );
        $record->setContentType($document->contentType);
        $record->setSizeBytes($document->size());
        $record->setConditionalHeaders($document->etag, $document->lastModified);

        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return $record;
    }
}
