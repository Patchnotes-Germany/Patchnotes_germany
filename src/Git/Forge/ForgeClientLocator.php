<?php

declare(strict_types=1);

namespace App\Git\Forge;

use App\Git\Enum\ForgeType;
use App\Git\RepositoryConfig;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Picks the forge implementation for a repository from configuration alone, so switching a
 * repository from GitHub to Gitea is an environment change, not a code change (SPEC.md § 21.3).
 */
final class ForgeClientLocator
{
    /** @var array<string, ForgeClientInterface> */
    private array $clients = [];

    /**
     * @param iterable<ForgeClientInterface> $clients
     */
    public function __construct(
        #[AutowireIterator('app.forge_client')]
        iterable $clients,
    ) {
        foreach ($clients as $client) {
            $this->clients[$client->type()->value] = $client;
        }
    }

    public function for(RepositoryConfig $repository): ForgeClientInterface
    {
        return $this->of($repository->forge);
    }

    public function of(ForgeType $type): ForgeClientInterface
    {
        return $this->clients[$type->value]
            ?? throw ForgeException::misconfigured($type->value, 'no client is registered for this forge');
    }
}
