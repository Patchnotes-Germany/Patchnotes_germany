<?php

declare(strict_types=1);

namespace App\Laws\Enum;

/**
 * Kind of legal instrument, as stated by the source (SPEC.md § 4.3).
 */
enum LawType: string
{
    case Gesetz = 'gesetz';
    case Verordnung = 'verordnung';
    case Bekanntmachung = 'bekanntmachung';
    case Satzung = 'satzung';
    case Sonstige = 'sonstige';
}
