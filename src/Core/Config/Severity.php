<?php

declare(strict_types=1);

namespace App\Core\Config;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
