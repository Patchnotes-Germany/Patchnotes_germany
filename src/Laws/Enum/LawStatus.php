<?php

declare(strict_types=1);

namespace App\Laws\Enum;

/**
 * A law is only marked as repealed after repeated confirmation by the source (SPEC.md § 24.3).
 */
enum LawStatus: string
{
    case InForce = 'in_force';
    case Repealed = 'repealed';
}
