<?php

declare(strict_types=1);

namespace App\Git;

use App\Content\Entity\Bill;
use App\Content\Entity\Change;
use App\Git\Entity\ChangeRequest;
use App\Git\Enum\ChangeRequestKind;
use App\Git\Enum\ChangeRequestStatus;
use App\Git\Enum\ForgeType;
use App\Git\Enum\RepositoryName;
use App\Git\Forge\ChangeRequestDraft;
use App\Git\Forge\ForgeClientLocator;
use App\Git\Value\CommitRequest;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The one place that turns "we have new content" into a pull request and, when allowed, a merge
 * (SPEC.md § 4.5, § 5.6).
 *
 * Only the single git worker calls this, so branch creation, commit, forge call and database write
 * happen in a fixed order without racing another job.
 */
final readonly class ChangeRequestManager
{
    public function __construct(
        private RepositoryRegistry $repositories,
        private ForgeClientLocator $forges,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Commits $work on a branch and opens a pull request for it.
     *
     * Returns null when the work changed nothing: a repeated synchronisation must not produce
     * commits, pull requests or notifications (SPEC.md § 1.1).
     *
     * @param callable(Worktree): void $work
     */
    public function open(
        RepositoryName $repository,
        ChangeRequestKind $kind,
        ChangeRequestDraft $draft,
        CommitRequest $commit,
        callable $work,
        ?Change $change = null,
        ?Bill $bill = null,
    ): ?ChangeRequest {
        $git = $this->repositories->get($repository);
        $config = $git->config();

        $hash = $git->commitOnBranch($draft->branch, $commit, $work, $draft->baseBranch);

        if (null === $hash) {
            $this->logger->info('No change requested: nothing to commit', [
                'repository' => $repository->value,
                'branch' => $draft->branch,
            ]);
            $git->deleteBranch($draft->branch);

            return null;
        }

        $git->push($draft->branch);

        // A rerun for the same act on the same day updates the existing pull request instead of
        // opening a second one for the same branch.
        $existing = $this->findByBranch($repository, $draft->branch);
        if ($existing instanceof ChangeRequest) {
            $existing->setLabels(array_values(array_unique([...$existing->labels(), ...$draft->labels])));
            $this->entityManager->flush();

            $this->logger->info('Change request updated', [
                'repository' => $repository->value,
                'branch' => $draft->branch,
                'commit' => $hash,
            ]);

            return $existing;
        }

        $forgeChangeRequest = $this->forges->for($config)->createChangeRequest($config, $draft);

        $changeRequest = new ChangeRequest($repository, $draft->branch, $kind);
        $changeRequest->setForgeReference($forgeChangeRequest->id, $forgeChangeRequest->url);
        $changeRequest->setStatus($forgeChangeRequest->status);
        $changeRequest->setLabels($draft->labels);
        $changeRequest->setChange($change);
        $changeRequest->setBill($bill);

        $this->entityManager->persist($changeRequest);
        $this->entityManager->flush();

        $this->logger->info('Change request opened', [
            'repository' => $repository->value,
            'branch' => $draft->branch,
            'forge' => $config->forge->value,
            'id' => $forgeChangeRequest->id,
            'commit' => $hash,
        ]);

        return $changeRequest;
    }

    /**
     * Merges with a merge commit. Without a forge the merge happens in the local clone; with a
     * forge the merge is performed there and the result is fetched back.
     */
    public function merge(ChangeRequest $changeRequest, string $title, string $message = ''): void
    {
        $git = $this->repositories->get($changeRequest->repository());
        $config = $git->config();

        if (ForgeType::None === $config->forge) {
            $mergeCommit = $git->merge($changeRequest->branch(), trim($title."\n\n".$message));
        } else {
            $mergeCommit = $this->forges->for($config)->merge(
                $config,
                $changeRequest->forgeId() ?? $changeRequest->branch(),
                $title,
                $message,
            );
            $git->fetch();
        }

        $changeRequest->markMerged(new \DateTimeImmutable(), $mergeCommit);
        $this->entityManager->flush();

        $this->logger->info('Change request merged', [
            'repository' => $changeRequest->repository()->value,
            'branch' => $changeRequest->branch(),
            'merge_commit' => $mergeCommit,
        ]);
    }

    /**
     * Bot report on a pull request (SPEC.md § 3.2: community pull requests are validated and
     * labelled, never merged automatically).
     */
    public function comment(ChangeRequest $changeRequest, string $body): void
    {
        $config = $this->repositories->configFor($changeRequest->repository());
        $this->forges->for($config)->comment($config, $changeRequest->forgeId() ?? $changeRequest->branch(), $body);
    }

    /**
     * @param list<string> $labels
     */
    public function addLabels(ChangeRequest $changeRequest, array $labels): void
    {
        $config = $this->repositories->configFor($changeRequest->repository());
        $this->forges->for($config)->addLabels($config, $changeRequest->forgeId() ?? $changeRequest->branch(), $labels);

        $changeRequest->setLabels(array_values(array_unique([...$changeRequest->labels(), ...$labels])));
        $this->entityManager->flush();
    }

    public function close(ChangeRequest $changeRequest, ?string $comment = null): void
    {
        $config = $this->repositories->configFor($changeRequest->repository());
        $forge = $this->forges->for($config);
        $id = $changeRequest->forgeId() ?? $changeRequest->branch();

        if (null !== $comment) {
            $forge->comment($config, $id, $comment);
        }
        $forge->close($config, $id);

        $changeRequest->markClosed(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    /**
     * Reconciles our copy of the state with the forge; used by the webhook handler and by the
     * ten-minute fallback poll.
     */
    public function refreshStatus(ChangeRequest $changeRequest): ChangeRequestStatus
    {
        $config = $this->repositories->configFor($changeRequest->repository());
        $status = $this->forges->for($config)->status($config, $changeRequest->forgeId() ?? $changeRequest->branch());

        $this->applyStatus($changeRequest, $status, null);

        return $status;
    }

    public function applyStatus(ChangeRequest $changeRequest, ChangeRequestStatus $status, ?string $mergeCommit): void
    {
        if ($status === $changeRequest->status()) {
            return;
        }

        match ($status) {
            ChangeRequestStatus::Merged => $changeRequest->markMerged(new \DateTimeImmutable(), $mergeCommit),
            ChangeRequestStatus::Closed => $changeRequest->markClosed(new \DateTimeImmutable()),
            default => $changeRequest->setStatus($status),
        };

        $this->entityManager->flush();
    }

    public function findByForgeId(RepositoryName $repository, string $forgeId): ?ChangeRequest
    {
        return $this->entityManager->getRepository(ChangeRequest::class)
            ->findOneBy(['repository' => $repository, 'forgeId' => $forgeId]);
    }

    public function findByBranch(RepositoryName $repository, string $branch): ?ChangeRequest
    {
        return $this->entityManager->getRepository(ChangeRequest::class)
            ->findOneBy(['repository' => $repository, 'branch' => $branch]);
    }
}
