<?php

declare(strict_types=1);

namespace App\Tests\Unit\Source\Storage;

use App\Source\Storage\RawDocumentStorage;
use App\Source\Value\DocumentRef;
use App\Source\Value\RawDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Raw documents are the evidence layer (SPEC.md § 6.1): they must be reproducible, deduplicated and
 * never overwritten.
 */
#[CoversClass(RawDocumentStorage::class)]
final class RawDocumentStorageTest extends TestCase
{
    private string $root;
    private RawDocumentStorage $storage;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/patchnotes-raw-'.bin2hex(random_bytes(4));
        $this->storage = new RawDocumentStorage($this->root, new NullLogger());
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testTheDocumentIsStoredUnderSourceDateAndHash(): void
    {
        $document = $this->document('<dokumente/>', 'application/xml');

        $stored = $this->storage->store('bund.gii', $document);

        self::assertSame(
            'bund.gii/2026/06/10/'.$document->contentHash.'.xml',
            $stored->storagePath,
        );
        self::assertTrue($this->storage->exists((string) $stored->storagePath));
        self::assertSame('<dokumente/>', $this->storage->read((string) $stored->storagePath));
    }

    public function testIdenticalContentIsStoredOnlyOnce(): void
    {
        $first = $this->storage->store('bund.gii', $this->document('same bytes', 'application/xml'));
        $writtenAt = filemtime($this->storage->absolutePath((string) $first->storagePath));

        $second = $this->storage->store('bund.gii', $this->document('same bytes', 'application/xml'));

        self::assertSame($first->storagePath, $second->storagePath);
        self::assertSame($writtenAt, filemtime($this->storage->absolutePath((string) $second->storagePath)));
    }

    public function testChangedContentIsKeptAlongsideTheOldVersion(): void
    {
        $first = $this->storage->store('bund.gii', $this->document('version one', 'application/xml'));
        $second = $this->storage->store('bund.gii', $this->document('version two', 'application/xml'));

        self::assertNotSame($first->storagePath, $second->storagePath);
        // Every version stays available for reproducing a conversion.
        self::assertSame('version one', $this->storage->read((string) $first->storagePath));
        self::assertSame('version two', $this->storage->read((string) $second->storagePath));
    }

    public function testTheExtensionFollowsTheRealContent(): void
    {
        $zip = $this->storage->store('bund.gii', $this->document("PK\x03\x04binary", 'application/octet-stream'));
        $pdf = $this->storage->store('bund.bgbl', $this->document('%PDF-1.7 …', 'application/octet-stream'));
        $json = $this->storage->store('bund.dip', $this->document('{"a":1}', 'application/json'));

        self::assertStringEndsWith('.zip', (string) $zip->storagePath);
        self::assertStringEndsWith('.pdf', (string) $pdf->storagePath);
        self::assertStringEndsWith('.json', (string) $json->storagePath);
    }

    public function testAnUnchangedDocumentIsNotWritten(): void
    {
        $ref = new DocumentRef('aufenthg_2004', 'https://example.org/aufenthg_2004/xml.zip');
        $unchanged = RawDocument::unchanged($ref, new \DateTimeImmutable('2026-06-10 08:00:00'));

        $stored = $this->storage->store('bund.gii', $unchanged);

        self::assertNull($stored->storagePath);
        self::assertDirectoryDoesNotExist($this->root.'/bund.gii');
    }

    public function testReadingAnUnknownPathReturnsNull(): void
    {
        self::assertNull($this->storage->read('bund.gii/2026/01/01/does-not-exist.xml'));
        self::assertFalse($this->storage->exists('bund.gii/2026/01/01/does-not-exist.xml'));
    }

    private function document(string $content, string $contentType): RawDocument
    {
        return new RawDocument(
            new DocumentRef('aufenthg_2004', 'https://example.org/aufenthg_2004/xml.zip'),
            $content,
            hash('sha256', $content),
            $contentType,
            200,
            new \DateTimeImmutable('2026-06-10 08:00:00'),
        );
    }
}
