<?php

declare(strict_types=1);

namespace App\Core\Config;

final readonly class ConfigurationProblem
{
    public function __construct(
        public Severity $severity,
        public string $key,
        public string $message,
    ) {
    }
}
