<?php

declare(strict_types=1);

namespace App\Git\Bootstrap;

use App\Git\Enum\RepositoryName;
use App\Git\RepositoryRegistry;
use App\Git\Value\CommitRequest;
use App\Git\Value\CommitTrailers;
use App\Git\Worktree;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Creates the initial structure of the laws and content repositories (SPEC.md § 3.1).
 *
 * Idempotent by design: files that already exist are left alone, and if nothing is missing no
 * commit is created at all, so running "make bootstrap" twice changes nothing.
 */
final readonly class RepositoryBootstrapper
{
    /**
     * @param iterable<RepositoryLayout> $layouts
     */
    public function __construct(
        private RepositoryRegistry $repositories,
        #[AutowireIterator('app.repository_layout')]
        private iterable $layouts,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, string|null> repository name => commit hash, or null when unchanged
     */
    public function bootstrapAll(): array
    {
        $result = [];
        foreach ($this->layouts as $layout) {
            $result[$layout->repository()->value] = $this->bootstrap($layout->repository());
        }

        return $result;
    }

    public function bootstrap(RepositoryName $name): ?string
    {
        $layout = $this->layoutFor($name);
        $repository = $this->repositories->get($name);

        if (!$repository->isInitialised()) {
            $repository->initialise();
        }

        $files = $layout->files();

        $hash = $repository->commitOnDefaultBranch(
            new CommitRequest(
                'Initialise repository structure',
                CommitTrailers::create(source: 'patchnotes-bootstrap'),
            ),
            static function (Worktree $worktree) use ($files): void {
                foreach ($files as $path => $contents) {
                    if (!$worktree->fileExists($path)) {
                        $worktree->writeFile($path, $contents);
                    }
                }
            },
        );

        $this->logger->info('Repository bootstrap finished', [
            'repository' => $name->value,
            'commit' => $hash,
            'created' => null !== $hash,
        ]);

        return $hash;
    }

    private function layoutFor(RepositoryName $name): RepositoryLayout
    {
        foreach ($this->layouts as $layout) {
            if ($layout->repository() === $name) {
                return $layout;
            }
        }

        throw new \InvalidArgumentException(\sprintf('No layout is defined for repository "%s".', $name->value));
    }
}
