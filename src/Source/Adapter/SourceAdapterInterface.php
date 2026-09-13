<?php

declare(strict_types=1);

namespace App\Source\Adapter;

use App\Source\Value\DocumentRef;
use App\Source\Value\RawDocument;
use App\Source\Value\SourceHealth;
use App\Source\Value\SyncContext;

/**
 * One data source (SPEC.md § 6.1).
 *
 * An adapter only *fetches*: it lists what a source offers and returns raw bytes together with the
 * fingerprints needed to skip unchanged documents. Turning bytes into Markdown is the job of a
 * LawNormalizerInterface, and that conversion is deterministic and AI-free (SPEC.md § 1.1).
 */
interface SourceAdapterInterface
{
    /** Stable key, e.g. "bund.gii", "bund.bgbl", "be.landesrecht". */
    public function key(): string;

    /** "bund" or a federal state code. */
    public function jurisdiction(): string;

    /**
     * @return list<SourceCapability>
     */
    public function capabilities(): array;

    /**
     * Everything the source currently offers, with the fingerprints of the last run attached so the
     * caller can skip unchanged documents.
     *
     * @return iterable<DocumentRef>
     */
    public function listDocuments(SyncContext $context): iterable;

    /**
     * Downloads one document. Implementations use conditional GET and return a RawDocument marked
     * as "not modified" instead of re-downloading unchanged content.
     */
    public function fetch(DocumentRef $ref, SyncContext $context): RawDocument;

    public function health(): SourceHealth;
}
