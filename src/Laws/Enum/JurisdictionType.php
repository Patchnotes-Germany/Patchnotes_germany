<?php

declare(strict_types=1);

namespace App\Laws\Enum;

/**
 * Federation or federal state (SPEC.md § 4.4). The European Union is a planned third level.
 */
enum JurisdictionType: string
{
    case Bund = 'bund';
    case Land = 'land';
}
