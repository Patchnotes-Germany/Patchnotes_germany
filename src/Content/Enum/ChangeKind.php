<?php

declare(strict_types=1);

namespace App\Content\Enum;

/**
 * What kind of legal event a change card describes (SPEC.md § 5.3, facts.yml "kind").
 */
enum ChangeKind: string
{
    case Amendment = 'amendment';
    case NewLaw = 'new_law';
    case Repeal = 'repeal';
    case Regulation = 'regulation';
    case Bill = 'bill';
}
