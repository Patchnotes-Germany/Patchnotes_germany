<?php

declare(strict_types=1);

namespace App\Git;

use App\Git\Process\GitCommandRunner;
use App\Git\Value\BlameLine;
use App\Git\Value\CommitTrailers;
use App\Git\Value\DiffStat;
use App\Git\Value\LogEntry;

/**
 * Read-only access to a repository through its bare mirror (SPEC.md § 24.10).
 *
 * Web processes never read a working tree: history, blame and diffs are answered from
 * var/repos/<name>.mirror.git, which the git worker refreshes after every write. That keeps page
 * rendering independent of whatever the writer is doing right now.
 */
final readonly class RepositoryReader
{
    private const string LOG_FORMAT = '%H%x1f%an%x1f%ae%x1f%aI%x1f%s%x1f%b%x1e';

    public function __construct(
        private RepositoryConfig $config,
        private GitCommandRunner $runner,
    ) {
    }

    public function defaultBranch(): string
    {
        return $this->config->defaultBranch;
    }

    public function isAvailable(): bool
    {
        return is_dir($this->config->mirrorPath());
    }

    public function resolve(string $ref): ?string
    {
        $result = $this->run(['rev-parse', '--verify', $ref.'^{commit}']);

        return $result->isSuccessful() ? trim($result->stdout) : null;
    }

    /**
     * Contents of a file at a given commit or branch; null when the path does not exist there.
     */
    public function fileAt(string $path, ?string $ref = null): ?string
    {
        $result = $this->run(['show', ($ref ?? $this->config->defaultBranch).':'.$path]);

        return $result->isSuccessful() ? $result->stdout : null;
    }

    /**
     * @return list<string>
     */
    public function listFiles(string $prefix = '', ?string $ref = null): array
    {
        $arguments = ['ls-tree', '-r', '--name-only', $ref ?? $this->config->defaultBranch];
        if ('' !== $prefix) {
            $arguments[] = '--';
            $arguments[] = $prefix;
        }

        $result = $this->run($arguments);
        if (!$result->isSuccessful()) {
            return [];
        }

        return array_values(array_filter(preg_split('/\R/', $result->stdout) ?: [], static fn (string $l): bool => '' !== $l));
    }

    /**
     * @return list<LogEntry>
     */
    public function log(?string $path = null, int $limit = 50, ?string $ref = null): array
    {
        $arguments = ['log', '--format='.self::LOG_FORMAT, '--max-count='.$limit, $ref ?? $this->config->defaultBranch];
        if (null !== $path) {
            $arguments[] = '--';
            $arguments[] = $path;
        }

        $result = $this->run($arguments);
        if (!$result->isSuccessful()) {
            return [];
        }

        return self::parseLog($result->stdout);
    }

    /**
     * @return list<LogEntry>
     */
    public static function parseLog(string $output): array
    {
        $entries = [];

        foreach (explode("\x1e", $output) as $record) {
            $record = trim($record, "\n");
            if ('' === $record) {
                continue;
            }

            $fields = explode("\x1f", $record);
            if (\count($fields) < 6) {
                continue;
            }

            $entries[] = new LogEntry(
                $fields[0],
                $fields[1],
                $fields[2],
                new \DateTimeImmutable($fields[3]),
                $fields[4],
                trim($fields[5]),
            );
        }

        return $entries;
    }

    /**
     * Which commit — and therefore which change — introduced each line of a norm.
     *
     * @return list<BlameLine>
     */
    public function blame(string $path, ?string $ref = null): array
    {
        $result = $this->run(['blame', '--porcelain', $ref ?? $this->config->defaultBranch, '--', $path]);
        if (!$result->isSuccessful()) {
            return [];
        }

        return self::parsePorcelainBlame($result->stdout);
    }

    /**
     * @return list<BlameLine>
     */
    public static function parsePorcelainBlame(string $output): array
    {
        $lines = [];
        // git repeats the metadata only for the first line of each commit block.
        /** @var array<string, string> $summaries */
        $summaries = [];
        /** @var array<string, int> $times */
        $times = [];

        $currentCommit = null;
        $currentLineNumber = 0;

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (1 === preg_match('/^([0-9a-f]{40}) \d+ (\d+)/', $line, $matches)) {
                $currentCommit = $matches[1];
                $currentLineNumber = (int) $matches[2];
                $summaries[$currentCommit] ??= '';
                $times[$currentCommit] ??= 0;
                continue;
            }

            if (null === $currentCommit) {
                continue;
            }

            if (str_starts_with($line, 'summary ')) {
                $summaries[$currentCommit] = substr($line, 8);
                continue;
            }

            if (str_starts_with($line, 'author-time ')) {
                $times[$currentCommit] = (int) substr($line, 12);
                continue;
            }

            if (str_starts_with($line, "\t")) {
                $lines[] = new BlameLine(
                    $currentLineNumber,
                    $currentCommit,
                    new \DateTimeImmutable('@'.$times[$currentCommit]),
                    $summaries[$currentCommit],
                    substr($line, 1),
                );
            }
        }

        return $lines;
    }

    /**
     * Unified diff between two commits, optionally restricted to one path.
     */
    public function diff(string $fromRef, string $toRef, ?string $path = null, int $contextLines = 3): string
    {
        $arguments = ['diff', '--unified='.$contextLines, $fromRef, $toRef];
        if (null !== $path) {
            $arguments[] = '--';
            $arguments[] = $path;
        }

        $result = $this->run($arguments);

        return $result->isSuccessful() ? $result->stdout : '';
    }

    public function diffStat(string $fromRef, string $toRef): DiffStat
    {
        $result = $this->run(['diff', '--numstat', $fromRef, $toRef]);
        if (!$result->isSuccessful()) {
            return DiffStat::empty();
        }

        $lines = array_values(array_filter(preg_split('/\R/', $result->stdout) ?: [], static fn (string $l): bool => '' !== $l));

        return GitRepository::parseNumstat($lines);
    }

    /**
     * Commits that carry a given Change-Id trailer (SPEC.md § 3.2).
     *
     * @return list<LogEntry>
     */
    public function commitsForChange(string $changeId, int $limit = 100): array
    {
        $result = $this->run([
            'log', '--format='.self::LOG_FORMAT, '--max-count='.$limit,
            '--grep=^Change-Id: '.preg_quote($changeId, '/'), '--extended-regexp',
            $this->config->defaultBranch,
        ]);

        if (!$result->isSuccessful()) {
            return [];
        }

        return array_values(array_filter(
            self::parseLog($result->stdout),
            static fn (LogEntry $entry): bool => $changeId === $entry->trailers()->get('Change-Id'),
        ));
    }

    public function trailersOf(string $commit): CommitTrailers
    {
        $result = $this->run(['show', '--no-patch', '--format=%B', $commit]);

        return $result->isSuccessful() ? CommitTrailers::parse($result->stdout) : CommitTrailers::none();
    }

    /**
     * @param list<string> $arguments
     */
    private function run(array $arguments): Process\GitCommandResult
    {
        return $this->runner->attempt(['--git-dir', $this->config->mirrorPath(), ...$arguments]);
    }
}
