<?php

declare(strict_types=1);

namespace App\Git\Handler;

use App\Git\Message\RepositoryUpdated;
use App\Git\Message\SynchroniseRepository;
use App\Git\RepositoryRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class SynchroniseRepositoryHandler
{
    public function __construct(
        private RepositoryRegistry $repositories,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SynchroniseRepository $message): void
    {
        $repository = $this->repositories->get($message->repository);

        if (!$repository->isInitialised()) {
            $repository->initialise();
        }

        $before = $repository->resolve($repository->config()->defaultBranch);
        $changed = $repository->fetch();
        $after = $repository->resolve($repository->config()->defaultBranch);

        if (!$changed || null === $after) {
            $this->logger->debug('Repository already up to date', ['repository' => $message->repository->value]);

            return;
        }

        $this->logger->info('Repository updated', [
            'repository' => $message->repository->value,
            'from' => $before,
            'to' => $after,
        ]);

        $this->bus->dispatch(new RepositoryUpdated($message->repository, $after, $before));
    }
}
