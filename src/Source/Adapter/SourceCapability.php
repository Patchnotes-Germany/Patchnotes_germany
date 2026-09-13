<?php

declare(strict_types=1);

namespace App\Source\Adapter;

/**
 * What a source can deliver (SPEC.md § 6.1).
 */
enum SourceCapability: string
{
    /** Consolidated law texts — the material for the `laws` repository. */
    case ConsolidatedLaws = 'consolidated_laws';
    /** Gazette publications (BGBl, GVBl): acts as promulgated, before consolidation. */
    case Promulgations = 'promulgations';
    /** Bills and their stages. */
    case Bills = 'bills';
    /** Plenary protocols. */
    case Plenary = 'plenary';
}
