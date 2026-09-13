<?php

declare(strict_types=1);

namespace App\Git;

use App\Core\Config\PatchnotesConfig;
use App\Git\Enum\RepositoryName;
use App\Git\Process\GitCommandRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Builds the git services from configuration (SPEC.md § 3.1): repository URLs, forges and paths are
 * configuration, never constants in the code.
 */
final class RepositoryRegistry
{
    /** @var array<string, GitRepository> */
    private array $repositories = [];

    /** @var array<string, RepositoryReader> */
    private array $readers = [];

    public function __construct(
        private readonly PatchnotesConfig $config,
        private readonly GitCommandRunner $runner,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function configFor(RepositoryName $name): RepositoryConfig
    {
        return RepositoryConfig::fromArray($name, $this->config->repository($name->value), $this->config->git());
    }

    public function get(RepositoryName $name): GitRepository
    {
        return $this->repositories[$name->value] ??= new GitRepository(
            $this->configFor($name),
            $this->runner,
            $this->lockFactory,
            $this->logger,
        );
    }

    /** Read-only view over the bare mirror; this is what web requests use (SPEC.md § 24.10). */
    public function reader(RepositoryName $name): RepositoryReader
    {
        return $this->readers[$name->value] ??= new RepositoryReader($this->configFor($name), $this->runner);
    }

    /**
     * @return list<GitRepository>
     */
    public function all(): array
    {
        return array_map($this->get(...), RepositoryName::cases());
    }
}
