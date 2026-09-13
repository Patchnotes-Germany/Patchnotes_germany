<?php

declare(strict_types=1);

namespace App\Git;

use Symfony\Component\Filesystem\Filesystem;

/**
 * A checked-out branch in its own directory (SPEC.md § 24.10): pull request branches are prepared
 * in separate worktrees so that concurrent jobs never fight over one working tree.
 *
 * Only the git queue writes here; web processes never touch a working tree at all.
 */
final readonly class Worktree
{
    private Filesystem $filesystem;

    public function __construct(
        public string $branch,
        public string $path,
    ) {
        $this->filesystem = new Filesystem();
    }

    public function writeFile(string $relativePath, string $contents): void
    {
        $this->filesystem->dumpFile($this->absolute($relativePath), $contents);
    }

    public function readFile(string $relativePath): ?string
    {
        $absolute = $this->absolute($relativePath);
        if (!is_file($absolute)) {
            return null;
        }

        $contents = file_get_contents($absolute);

        return false === $contents ? null : $contents;
    }

    public function fileExists(string $relativePath): bool
    {
        return is_file($this->absolute($relativePath));
    }

    public function deleteFile(string $relativePath): void
    {
        $this->filesystem->remove($this->absolute($relativePath));
    }

    /**
     * Used when a law is repealed: files move to {jurisdiction}/_repealed/{slug}/ instead of being
     * deleted, so the history stays reachable (SPEC.md § 24.3).
     */
    public function moveDirectory(string $from, string $to): void
    {
        $this->filesystem->mkdir(\dirname($this->absolute($to)));
        $this->filesystem->rename($this->absolute($from), $this->absolute($to), true);
    }

    public function directoryExists(string $relativePath): bool
    {
        return is_dir($this->absolute($relativePath));
    }

    private function absolute(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');
        if (str_contains($relativePath, '..')) {
            throw new \InvalidArgumentException(\sprintf('Path "%s" must stay inside the worktree.', $relativePath));
        }

        return $this->path.'/'.$relativePath;
    }
}
