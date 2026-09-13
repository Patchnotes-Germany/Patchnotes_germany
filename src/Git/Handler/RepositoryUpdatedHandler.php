<?php

declare(strict_types=1);

namespace App\Git\Handler;

use App\Git\Enum\RepositoryName;
use App\Git\Message\RepositoryUpdated;
use App\Laws\Import\LawImporter;
use App\Pipeline\ChangeDetector;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The hand-over from git to the database (SPEC.md § 17, § 24.11) and into the pipeline (§ 7.1).
 *
 * When the default branch of the `laws` repository moves, the new texts are imported into
 * Law/Norm/NormVersion — the cache the website and the search index read from — and the commits are
 * then read back for their change ids, which is how a merged pull request becomes a `Change`.
 *
 * The order matters: the detector links a change to the norms it touched, and those rows have to
 * exist first. The `content` repository is imported later in M5, with the cards.
 */
#[AsMessageHandler]
final readonly class RepositoryUpdatedHandler
{
    public function __construct(
        private LawImporter $laws,
        private ChangeDetector $changes,
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

        $detected = $this->changes->detect($message->commit, $message->previousCommit);

        $this->logger->info('Scanned the new commits for changes of the law', [
            'commit' => $message->commit,
            ...$detected->toArray(),
        ]);
    }
}
