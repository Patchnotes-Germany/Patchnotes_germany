<?php

declare(strict_types=1);

namespace App\Laws\Enum;

/**
 * A norm that the source marks as "weggefallen" keeps its file and history (SPEC.md § 4.2).
 */
enum NormStatus: string
{
    case InForce = 'in_force';
    case Repealed = 'repealed';
}
