<?php

declare(strict_types=1);

namespace App\Ai\Enum;

/**
 * Where the month stands against the AI budget (SPEC.md § 8.2).
 */
enum BudgetState: string
{
    case Ok = 'ok';
    /** An alert threshold (80 % by default) has been crossed; everything still runs. */
    case Warning = 'warning';
    /** The limit is reached: depending on the policy, degrade to free models or pause. */
    case Exceeded = 'exceeded';
}
