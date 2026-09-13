<?php

declare(strict_types=1);

namespace App\Laws\Normalizer;

use App\Laws\Value\NormalizedLaw;
use App\Source\Value\RawDocument;

/**
 * Turns a raw source document into our Markdown representation (SPEC.md § 4.2, § 6.1).
 *
 * **Deterministic and AI-free.** The same input must always produce byte-identical output, because
 * the diffs in the `laws` repository have to show real changes of the law — not the mood of a
 * model. Determinism is asserted by golden tests and re-checked before every auto-merge (§ 4.6).
 */
interface LawNormalizerInterface
{
    public function supports(RawDocument $document): bool;

    public function normalize(RawDocument $document): NormalizedLaw;
}
