<?php

declare(strict_types=1);

namespace App\Git\Handler;

use App\Git\Message\RepositoryUpdated;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Extension point of the git layer.
 *
 * M3 imports the new law texts (Law, Norm, NormVersion) here, M5 the cards and translations.
 * Until then the event is only recorded, so that a missed webhook is visible in the logs.
 */
#[AsMessageHandler]
final readonly class RepositoryUpdatedHandler
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(RepositoryUpdated $message): void
    {
        $this->logger->info('Repository content changed, import pending', [
            'repository' => $message->repository->value,
            'commit' => $message->commit,
            'previous_commit' => $message->previousCommit,
        ]);
    }
}
