<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Source\Adapter\SourceAdapterInterface;
use App\Source\Adapter\SourceCapability;
use App\Source\Value\DocumentRef;
use App\Source\Value\RawDocument;
use App\Source\Value\SourceHealth;
use App\Source\Value\SyncContext;

/**
 * Serves fixture documents instead of talking to a real source — the test suite never uses the
 * network (SPEC.md § 19).
 */
final class FakeSourceAdapter implements SourceAdapterInterface
{
    /** @var array<string, string> slug => document content */
    private array $documents = [];

    /** @var list<string> slugs whose fetch must fail, to exercise error handling */
    private array $failing = [];

    public function __construct(
        private readonly string $key = 'bund.gii',
        private readonly string $jurisdiction = 'bund',
    ) {
    }

    public function serve(string $slug, string $content): void
    {
        $this->documents[$slug] = $content;
    }

    public function fail(string $slug): void
    {
        $this->documents[$slug] ??= '';
        $this->failing[] = $slug;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function title(): string
    {
        return 'Fake source ('.$this->jurisdiction.')';
    }

    public function jurisdiction(): string
    {
        return $this->jurisdiction;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function capabilities(): array
    {
        return [SourceCapability::ConsolidatedLaws];
    }

    public function listDocuments(SyncContext $context): iterable
    {
        foreach (array_keys($this->documents) as $slug) {
            if (!$context->wants($slug)) {
                continue;
            }

            yield new DocumentRef($slug, 'https://www.gesetze-im-internet.de/'.$slug.'/xml.zip');
        }
    }

    public function fetch(DocumentRef $ref, SyncContext $context): RawDocument
    {
        if (\in_array($ref->id, $this->failing, true)) {
            throw new \RuntimeException('the source is unreachable');
        }

        $content = $this->documents[$ref->id] ?? throw new \RuntimeException('Unknown document '.$ref->id);

        return new RawDocument(
            $ref,
            $content,
            hash('sha256', $content),
            'application/xml',
            200,
            new \DateTimeImmutable('2026-09-13 03:00:00'),
        );
    }

    public function health(): SourceHealth
    {
        return SourceHealth::healthy();
    }
}
