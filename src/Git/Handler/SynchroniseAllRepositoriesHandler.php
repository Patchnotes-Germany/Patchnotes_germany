<?php

declare(strict_types=1);

namespace App\Git\Handler;

use App\Git\Enum\RepositoryName;
use App\Git\Message\SynchroniseAllRepositories;
use App\Git\Message\SynchroniseRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class SynchroniseAllRepositoriesHandler
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function __invoke(SynchroniseAllRepositories $message): void
    {
        foreach (RepositoryName::cases() as $repository) {
            $this->bus->dispatch(new SynchroniseRepository($repository));
        }
    }
}
