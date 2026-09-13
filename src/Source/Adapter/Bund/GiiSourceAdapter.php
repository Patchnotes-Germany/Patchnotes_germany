<?php

declare(strict_types=1);

namespace App\Source\Adapter\Bund;

use App\Core\Config\PatchnotesConfig;
use App\Source\Adapter\SourceAdapterInterface;
use App\Source\Adapter\SourceCapability;
use App\Source\Http\PoliteHttpClient;
use App\Source\Http\SourceUnavailable;
use App\Source\Storage\DocumentFingerprintStore;
use App\Source\Storage\RawDocumentStorage;
use App\Source\Value\DocumentRef;
use App\Source\Value\RawDocument;
use App\Source\Value\SourceHealth;
use App\Source\Value\SyncContext;
use Psr\Log\LoggerInterface;

/**
 * gesetze-im-internet.de — the consolidated federal law texts (SPEC.md § 6.2 A).
 *
 * See `docs/sources/bund.gii.md` for the analysed format. Two properties of the source shape this
 * adapter: the table of contents lists all ~6100 laws in one 1.3 MB XML (so it is streamed, not
 * loaded into memory), and every law is a zip that is regenerated daily even when the text did not
 * change (so the *content hash*, not the HTTP metadata alone, decides what counts as a change).
 */
final class GiiSourceAdapter implements SourceAdapterInterface
{
    public const string KEY = 'bund.gii';

    private const string BASE_URL = 'https://www.gesetze-im-internet.de';
    private const string TOC_URL = self::BASE_URL.'/gii-toc.xml';

    private ?string $lastError = null;

    public function __construct(
        private readonly PoliteHttpClient $http,
        private readonly RawDocumentStorage $storage,
        private readonly DocumentFingerprintStore $fingerprints,
        private readonly PatchnotesConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function title(): string
    {
        return 'gesetze-im-internet.de (konsolidierte Bundesgesetze)';
    }

    public function jurisdiction(): string
    {
        return 'bund';
    }

    public function capabilities(): array
    {
        return [SourceCapability::ConsolidatedLaws];
    }

    public function isEnabled(): bool
    {
        /** @var array{bund?: array{gii?: array{enabled?: bool|string}}} $sources */
        $sources = $this->config->sources();

        return filter_var($sources['bund']['gii']['enabled'] ?? false, \FILTER_VALIDATE_BOOL);
    }

    /**
     * @return iterable<DocumentRef>
     */
    public function listDocuments(SyncContext $context): iterable
    {
        $toc = $this->http->fetch(self::TOC_URL);
        $known = $this->fingerprints->forSource(self::KEY);

        $seen = 0;
        foreach ($this->parseTableOfContents($toc->content) as $slug => $title) {
            if (!$context->wants($slug)) {
                continue;
            }

            $url = self::BASE_URL.'/'.$slug.'/xml.zip';
            $fingerprint = $known[hash('sha256', $url)] ?? null;

            yield new DocumentRef(
                id: $slug,
                url: $url,
                title: $title,
                etag: $context->force ? null : $fingerprint?->etag,
                lastModified: $context->force ? null : $fingerprint?->lastModified,
                knownHash: $fingerprint?->contentHash,
                attributes: ['source_title' => $title],
            );

            if (null !== $context->limit && ++$seen >= $context->limit) {
                return;
            }
        }
    }

    public function fetch(DocumentRef $ref, SyncContext $context): RawDocument
    {
        $now = new \DateTimeImmutable();

        try {
            $result = $this->http->fetch($ref->url, $ref->etag, $ref->lastModified);
            $this->lastError = null;
        } catch (SourceUnavailable $exception) {
            $this->lastError = $exception->getMessage();

            throw $exception;
        }

        if ($result->isNotModified()) {
            return RawDocument::unchanged($ref, $now);
        }

        $hash = $result->hash();

        // The zip is rebuilt daily: identical content means nothing changed, whatever the headers say.
        if (null !== $ref->knownHash && $hash === $ref->knownHash) {
            $this->logger->debug('Document content unchanged despite a fresh download', [
                'source' => self::KEY,
                'document' => $ref->id,
            ]);

            return new RawDocument(
                $ref,
                $result->content,
                $hash,
                $result->contentType,
                $result->statusCode,
                $now,
                $result->etag,
                $result->lastModified,
                unchanged: true,
            );
        }

        $document = new RawDocument(
            $ref,
            $result->content,
            $hash,
            $result->contentType,
            $result->statusCode,
            $now,
            $result->etag,
            $result->lastModified,
        );

        $document = $this->storage->store(self::KEY, $document);

        if (!$context->dryRun) {
            $this->fingerprints->record(self::KEY, $document);
        }

        return $document;
    }

    public function health(): SourceHealth
    {
        if (!$this->isEnabled()) {
            return SourceHealth::disabled();
        }

        return null === $this->lastError
            ? SourceHealth::healthy()
            : SourceHealth::down($this->lastError);
    }

    /**
     * Streams the table of contents; loading 1.3 MB of XML into a DOM tree would be wasteful and
     * the structure is flat anyway.
     *
     * @return iterable<string, string> slug => title
     */
    public function parseTableOfContents(string $xml): iterable
    {
        $reader = new \XMLReader();
        if (!$reader->XML($xml, 'UTF-8', \LIBXML_NONET)) {
            throw new \RuntimeException('The table of contents is not readable XML.');
        }

        try {
            $title = null;

            while ($reader->read()) {
                if (\XMLReader::ELEMENT !== $reader->nodeType) {
                    continue;
                }

                if ('title' === $reader->name) {
                    $title = trim($reader->readString());
                    continue;
                }

                if ('link' !== $reader->name) {
                    continue;
                }

                $slug = self::slugFromLink(trim($reader->readString()));
                if (null !== $slug) {
                    yield $slug => $title ?? $slug;
                }
                $title = null;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * "http://www.gesetze-im-internet.de/aufenthg_2004/xml.zip" → "aufenthg_2004".
     */
    public static function slugFromLink(string $link): ?string
    {
        if (1 !== preg_match('#^https?://www\.gesetze-im-internet\.de/([^/]+)/xml\.zip$#i', $link, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
