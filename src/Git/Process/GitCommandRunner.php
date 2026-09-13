<?php

declare(strict_types=1);

namespace App\Git\Process;

use App\Git\Exception\GitCommandFailed;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Runs the system `git` binary (SPEC.md § 2: system git through symfony/process, not a PHP git
 * implementation).
 *
 * Credentials are passed per command and never written into the repository configuration, and any
 * argument that carries a token is redacted before it reaches the log.
 */
final readonly class GitCommandRunner
{
    public function __construct(
        private LoggerInterface $logger,
        private int $timeoutSeconds = 300,
    ) {
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $env
     */
    public function run(array $arguments, ?string $cwd = null, array $env = []): string
    {
        $process = $this->process($arguments, $cwd, $env);
        $process->run();

        if (!$process->isSuccessful()) {
            throw GitCommandFailed::fromProcess($this->redact($arguments), $process);
        }

        return $process->getOutput();
    }

    /**
     * Like run(), but a non-zero exit code is a legitimate answer (e.g. "git diff --quiet").
     *
     * @param list<string>          $arguments
     * @param array<string, string> $env
     */
    public function attempt(array $arguments, ?string $cwd = null, array $env = []): GitCommandResult
    {
        $process = $this->process($arguments, $cwd, $env);
        $process->run();

        return new GitCommandResult(
            $process->getExitCode() ?? -1,
            $process->getOutput(),
            $process->getErrorOutput(),
        );
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $env
     *
     * @return list<string> output split into non-empty lines
     */
    public function runLines(array $arguments, ?string $cwd = null, array $env = []): array
    {
        $output = $this->run($arguments, $cwd, $env);

        return array_values(array_filter(
            preg_split('/\R/', $output) ?: [],
            static fn (string $line): bool => '' !== $line,
        ));
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $env
     */
    private function process(array $arguments, ?string $cwd, array $env): Process
    {
        $this->logger->debug('Running git command', [
            'arguments' => $this->redact($arguments),
            'cwd' => $cwd,
        ]);

        return new Process(
            ['git', ...$arguments],
            $cwd,
            $env + [
                // Never wait for interactive credentials in a worker container.
                'GIT_TERMINAL_PROMPT' => '0',
                'GIT_CONFIG_NOSYSTEM' => '0',
                'LC_ALL' => 'C',
            ],
            null,
            (float) $this->timeoutSeconds,
        );
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    private function redact(array $arguments): array
    {
        return array_map(
            static fn (string $argument): string => preg_replace(
                '/(Authorization: \w+ )\S+/i',
                '$1***',
                $argument,
            ) ?? $argument,
            $arguments,
        );
    }
}
