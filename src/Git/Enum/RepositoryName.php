<?php

declare(strict_types=1);

namespace App\Git\Enum;

/**
 * The two content repositories the application writes to (SPEC.md § 3).
 */
enum RepositoryName: string
{
    /** German law texts in Markdown; one amending act = one Pull Request. */
    case Laws = 'laws';
    /** Facts, cards, translations, glossaries, taxonomy. */
    case Content = 'content';
}
