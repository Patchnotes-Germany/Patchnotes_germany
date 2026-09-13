<?php

declare(strict_types=1);

namespace App\Git\Exception;

use Symfony\Component\Process\Process;

final class GitCommandFailed extends \RuntimeException implements GitException
{
    /**
     * @param list<string> $arguments
     */
    private function __construct(
        public readonly array $arguments,
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param list<string> $arguments the command as it was run, already redacted
     */
    public static function fromProcess(array $arguments, Process $process): self
    {
        $stderr = trim($process->getErrorOutput());

        return new self(
            $arguments,
            $process->getExitCode() ?? -1,
            $process->getOutput(),
            $stderr,
            \sprintf(
                'git %s failed with exit code %d: %s',
                implode(' ', $arguments),
                $process->getExitCode() ?? -1,
                '' !== $stderr ? $stderr : trim($process->getOutput()),
            ),
        );
    }
}
