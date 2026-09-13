<?php

declare(strict_types=1);

namespace App\Git;

use App\Git\Enum\RepositoryName;
use App\Git\Process\GitCommandRunner;
use App\Git\Value\CommitRequest;
use App\Git\Value\DiffStat;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

/**
 * Write access to one content repository (SPEC.md § 3.2).
 *
 * Every mutating operation runs inside a repository lock, and the only consumer of the `git` queue
 * is a single worker, so the working clone is never touched concurrently. After each change the
 * bare mirror is refreshed, because that is what the website reads from (SPEC.md § 24.10).
 */
final readonly class GitRepository
{
    private const float LOCK_TTL_SECONDS = 900.0;

    private Filesystem $filesystem;

    public function __construct(
        private RepositoryConfig $config,
        private GitCommandRunner $runner,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
        $this->filesystem = new Filesystem();
    }

    public function name(): RepositoryName
    {
        return $this->config->name;
    }

    public function config(): RepositoryConfig
    {
        return $this->config;
    }

    public function path(): string
    {
        return $this->config->localPath;
    }

    public function isInitialised(): bool
    {
        return is_dir($this->config->localPath.'/.git');
    }

    /**
     * Clones the remote, or creates an empty local repository when no URL is configured
     * (development and tests run entirely locally, SPEC.md § 3.1 "forge: none").
     */
    public function initialise(): void
    {
        $this->withLock(function (): void {
            if (!$this->isInitialised()) {
                $this->filesystem->mkdir($this->config->localPath);

                if ($this->config->hasRemote()) {
                    $this->logger->info('Cloning repository', ['repository' => $this->config->name->value]);
                    $this->git(['clone', '--origin', 'origin', $this->config->url, $this->config->localPath], null);
                } else {
                    $this->logger->info('Initialising local repository', ['repository' => $this->config->name->value]);
                    $this->git(['init', '--initial-branch='.$this->config->defaultBranch, $this->config->localPath], null);
                }
            }

            $this->ensureMirror();
        });
    }

    /**
     * Fetches the remote and refreshes the mirror. Returns true when the default branch moved,
     * which is the fallback for missed webhooks (SPEC.md § 3.2, every 10 minutes).
     */
    public function fetch(): bool
    {
        return (bool) $this->withLock(function (): bool {
            if (!$this->isInitialised()) {
                return false;
            }

            $before = $this->resolve($this->config->defaultBranch);

            if ($this->config->hasRemote()) {
                $this->git(['fetch', '--prune', 'origin'], $this->config->localPath);
                $this->git(['checkout', $this->config->defaultBranch], $this->config->localPath);
                $this->git(['merge', '--ff-only', 'origin/'.$this->config->defaultBranch], $this->config->localPath);
            }

            $this->syncMirror();

            return $before !== $this->resolve($this->config->defaultBranch);
        });
    }

    public function resolve(string $ref): ?string
    {
        $result = $this->runner->attempt(['rev-parse', '--verify', $ref.'^{commit}'], $this->config->localPath, $this->env());

        return $result->isSuccessful() ? trim($result->stdout) : null;
    }

    public function branchExists(string $branch): bool
    {
        return $this->runner
            ->attempt(['show-ref', '--verify', '--quiet', 'refs/heads/'.$branch], $this->config->localPath, $this->env())
            ->isSuccessful();
    }

    /**
     * Runs $work against a worktree of $branch (created from the default branch if it is new) and
     * commits whatever it produced. Returns the commit hash, or null when nothing changed — which
     * is what makes a repeated synchronisation a no-op (SPEC.md § 1.1 idempotency).
     *
     * @param callable(Worktree): void $work
     */
    public function commitOnBranch(string $branch, CommitRequest $commit, callable $work, ?string $base = null): ?string
    {
        return $this->withLock(function () use ($branch, $commit, $work, $base): ?string {
            $worktree = $this->openWorktree($branch, $base);

            try {
                $work($worktree);
                $hash = $this->commitWorktree($worktree, $commit);
            } finally {
                $this->removeWorktree($worktree);
            }

            if (null !== $hash) {
                $this->syncMirror();
            }

            return $hash;
        });
    }

    /**
     * Commits directly on the default branch — used by the repository bootstrap and by the baseline
     * import, which deliberately do not go through a pull request (SPEC.md § 4.7).
     *
     * @param callable(Worktree): void $work
     */
    public function commitOnDefaultBranch(CommitRequest $commit, callable $work): ?string
    {
        return $this->withLock(function () use ($commit, $work): ?string {
            $this->checkoutDefaultBranch();
            $worktree = new Worktree($this->config->defaultBranch, $this->config->localPath);

            $work($worktree);
            $hash = $this->commitWorktree($worktree, $commit);

            if (null !== $hash) {
                $this->syncMirror();
            }

            return $hash;
        });
    }

    /**
     * Merges a branch into the default branch with a merge commit, keeping "one amending act = one
     * merge" visible in the history (SPEC.md § 4.5).
     */
    public function merge(string $branch, string $message): string
    {
        $merged = $this->withLock(function () use ($branch, $message): string {
            $this->checkoutDefaultBranch();
            // A merge creates a commit, so it needs the bot identity as well: the containers
            // deliberately have no global git configuration.
            $this->git([...$this->identity(), 'merge', '--no-ff', '-m', $message, $branch], $this->config->localPath);
            $head = $this->resolve('HEAD') ?? throw new \RuntimeException('Merge produced no commit.');
            $this->syncMirror();

            return $head;
        });

        \assert(\is_string($merged));

        return $merged;
    }

    public function deleteBranch(string $branch): void
    {
        $this->withLock(function () use ($branch): void {
            if ($this->branchExists($branch)) {
                $this->checkoutDefaultBranch();
                $this->git(['branch', '-D', $branch], $this->config->localPath);
                $this->syncMirror();
            }
        });
    }

    /**
     * Pushes a branch to the remote. In development GIT_PUSH_ENABLED is false: everything stays in
     * the local clone (SPEC.md § 3.1).
     */
    public function push(string $branch): bool
    {
        return (bool) $this->withLock(function () use ($branch): bool {
            if (!$this->config->pushEnabled || !$this->config->hasRemote()) {
                $this->logger->info('Push skipped', [
                    'repository' => $this->config->name->value,
                    'branch' => $branch,
                    'reason' => $this->config->hasRemote() ? 'push disabled' : 'no remote configured',
                ]);

                return false;
            }

            $this->git(['push', 'origin', $branch.':'.$branch], $this->config->localPath);

            return true;
        });
    }

    public function diffStat(string $fromRef, string $toRef): DiffStat
    {
        $lines = $this->runner->runLines(
            ['diff', '--numstat', $fromRef, $toRef],
            $this->config->localPath,
            $this->env(),
        );

        return self::parseNumstat($lines);
    }

    /**
     * @param list<string> $lines output of `git diff --numstat`
     */
    public static function parseNumstat(array $lines): DiffStat
    {
        $files = [];
        $insertions = 0;
        $deletions = 0;

        foreach ($lines as $line) {
            $parts = explode("\t", $line, 3);
            if (3 !== \count($parts)) {
                continue;
            }
            [$added, $removed, $path] = $parts;
            // Binary files are reported as "-": they must never appear in our repositories.
            $addedCount = ctype_digit($added) ? (int) $added : 0;
            $removedCount = ctype_digit($removed) ? (int) $removed : 0;

            $files[$path] = ['added' => $addedCount, 'removed' => $removedCount];
            $insertions += $addedCount;
            $deletions += $removedCount;
        }

        return new DiffStat($files, $insertions, $deletions);
    }

    /**
     * Worktree directory name for a branch. Dots are dropped as well, so that a branch called
     * "../escape" cannot lead outside the worktree root.
     */
    public static function sanitiseBranchForPath(string $branch): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $branch), '-');
    }

    private function checkoutDefaultBranch(): void
    {
        // A freshly initialised repository sits on an unborn default branch: there is nothing to
        // check out yet, the first commit creates it.
        if (null === $this->resolve('HEAD') && !$this->branchExists($this->config->defaultBranch)) {
            return;
        }

        $this->git(['checkout', $this->config->defaultBranch], $this->config->localPath);
    }

    private function openWorktree(string $branch, ?string $base): Worktree
    {
        $directory = $this->config->worktreeRoot().'/'.self::sanitiseBranchForPath($branch);
        $this->filesystem->mkdir($this->config->worktreeRoot());

        // A leftover worktree from a crashed job must not block the new one.
        if (is_dir($directory)) {
            $this->runner->attempt(['worktree', 'remove', '--force', $directory], $this->config->localPath, $this->env());
            $this->filesystem->remove($directory);
        }
        $this->runner->attempt(['worktree', 'prune'], $this->config->localPath, $this->env());

        if ($this->branchExists($branch)) {
            $this->git(['worktree', 'add', $directory, $branch], $this->config->localPath);
        } else {
            $this->git(['worktree', 'add', '-b', $branch, $directory, $base ?? $this->config->defaultBranch], $this->config->localPath);
        }

        return new Worktree($branch, $directory);
    }

    private function removeWorktree(Worktree $worktree): void
    {
        $this->runner->attempt(['worktree', 'remove', '--force', $worktree->path], $this->config->localPath, $this->env());
        $this->filesystem->remove($worktree->path);
        $this->runner->attempt(['worktree', 'prune'], $this->config->localPath, $this->env());
    }

    private function commitWorktree(Worktree $worktree, CommitRequest $commit): ?string
    {
        $this->git(['add', '--all', '.'], $worktree->path);

        if ($this->runner->attempt(['diff', '--cached', '--quiet'], $worktree->path, $this->env())->isSuccessful()) {
            $this->logger->info('Nothing to commit', [
                'repository' => $this->config->name->value,
                'branch' => $worktree->branch,
            ]);

            return null;
        }

        $author = $commit->author;
        $date = ($commit->date ?? $author?->date)?->format(\DATE_RFC2822);

        $arguments = [
            ...$this->identity(),
            'commit',
            '--no-verify',
            '-m', $commit->fullMessage(),
        ];
        if ($author instanceof Value\Signature) {
            $arguments[] = '--author='.$author->toString();
        }

        $env = $this->env();
        if (null !== $date) {
            $env['GIT_AUTHOR_DATE'] = $date;
            $env['GIT_COMMITTER_DATE'] = $date;
        }

        $this->runner->run($arguments, $worktree->path, $env);

        return trim($this->runner->run(['rev-parse', 'HEAD'], $worktree->path, $this->env()));
    }

    private function ensureMirror(): void
    {
        if (is_dir($this->config->mirrorPath())) {
            $this->syncMirror();

            return;
        }

        $this->git(['clone', '--mirror', $this->config->localPath, $this->config->mirrorPath()], null);
    }

    /**
     * The mirror is the read-only view every web request uses; it is refreshed after each write.
     */
    private function syncMirror(): void
    {
        if (!is_dir($this->config->mirrorPath())) {
            $this->ensureMirror();

            return;
        }

        $this->runner->attempt([
            '--git-dir', $this->config->mirrorPath(),
            'fetch', '--prune', 'origin', '+refs/heads/*:refs/heads/*',
        ], null, $this->env());
    }

    /**
     * The bot identity, passed per command instead of being written into the repository config.
     *
     * @return list<string>
     */
    private function identity(): array
    {
        return [
            '-c', 'user.name='.$this->config->botName,
            '-c', 'user.email='.$this->config->botEmail,
        ];
    }

    /**
     * @param list<string> $arguments
     */
    private function git(array $arguments, ?string $cwd): string
    {
        return $this->runner->run($arguments, $cwd, $this->env());
    }

    /**
     * @return array<string, string>
     */
    private function env(): array
    {
        $env = [];

        if (null !== $this->config->sshKeyPath) {
            $env['GIT_SSH_COMMAND'] = \sprintf(
                'ssh -i %s -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes',
                escapeshellarg($this->config->sshKeyPath),
            );
        }

        return $env;
    }

    private function withLock(callable $operation): mixed
    {
        $lock = $this->lockFactory->createLock(
            'git.repository.'.$this->config->name->value,
            self::LOCK_TTL_SECONDS,
            autoRelease: true,
        );
        $lock->acquire(true);

        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }
}
