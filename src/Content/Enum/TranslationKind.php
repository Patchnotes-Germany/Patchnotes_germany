<?php

declare(strict_types=1);

namespace App\Content\Enum;

/**
 * How a card or norm translation came to be (SPEC.md § 5.4). Machine translations carry a badge on
 * the website; only the German text is legally binding.
 */
enum TranslationKind: string
{
    case Machine = 'machine';
    case Reviewed = 'reviewed';
    case Human = 'human';
}
