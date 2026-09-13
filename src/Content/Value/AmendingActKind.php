<?php

declare(strict_types=1);

namespace App\Content\Value;

/**
 * How the source abbreviates the kind of act in a citation (SPEC.md § 6.2 A).
 */
enum AmendingActKind: string
{
    case Gesetz = 'G';
    case Verordnung = 'V';
    case Bekanntmachung = 'Bek.';

    public static function fromCitation(string $token): self
    {
        return match (rtrim($token, '.')) {
            'V', 'VO' => self::Verordnung,
            'Bek' => self::Bekanntmachung,
            default => self::Gesetz,
        };
    }
}
