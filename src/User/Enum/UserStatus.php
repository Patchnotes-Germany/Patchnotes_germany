<?php

declare(strict_types=1);

namespace App\User\Enum;

/**
 * Accounts that never confirm their e-mail are deleted after retention.unconfirmed_accounts_days
 * (SPEC.md § 16.2).
 */
enum UserStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Blocked = 'blocked';
}
