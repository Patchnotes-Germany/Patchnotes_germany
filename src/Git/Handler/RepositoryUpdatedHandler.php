<?php

declare(strict_types=1);

namespace App\Git\Handler;

use App\Git\Enum\RepositoryName;
use App\Git\Message\RepositoryUpdated;
use App\Laws\Import\LawImporter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The hand-over from git to the database (SPEC.md § 17, § 24.11).
 *
 * When the default branch of the `laws` repository moves, the new texts are imported into
 * Law/Norm/NormVersion — the cache the website and the search index read from. The `content`
 * repository is imported by M5, which introduces the cards.
 */
#[AsMessageHandler]
final readonly class RepositoryUpdatedHandler
{
    public function __construct(
        private LawImporter $laws,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RepositoryUpdated $message): void
    {
        if (RepositoryName::Laws !== $message->repository) {
            $this->logger->info('Content repository changed, import pending (M5)', [
                'repository' => $message->repository->value,
                'commit' => $message->commit,
            ]);

            return;
        }

        $report = $this->laws->importAll($message->commit);

        $this->logger->info('Imported the laws repository into the database', [
            'commit' => $message->commit,
            'previous_commit' => $message->previousCommit,
            ...$report->toArray(),
        ]);
    }
}
