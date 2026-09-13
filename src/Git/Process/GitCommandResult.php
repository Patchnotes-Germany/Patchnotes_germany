<?php

declare(strict_types=1);

namespace App\Git\Process;

final readonly class GitCommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {
    }

    public function isSuccessful(): bool
    {
        return 0 === $this->exitCode;
    }
}
