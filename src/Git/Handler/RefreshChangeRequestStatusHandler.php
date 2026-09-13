<?php

declare(strict_types=1);

namespace App\Git\Handler;

use App\Git\ChangeRequestManager;
use App\Git\Message\RefreshChangeRequestStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RefreshChangeRequestStatusHandler
{
    public function __construct(
        private ChangeRequestManager $changeRequests,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshChangeRequestStatus $message): void
    {
        $changeRequest = $this->changeRequests->findByForgeId($message->repository, $message->forgeId);

        if (!$changeRequest instanceof \App\Git\Entity\ChangeRequest) {
            // A pull request opened by a person, not by the bot: nothing of ours to update yet.
            $this->logger->debug('Webhook for an unknown change request', [
                'repository' => $message->repository->value,
                'forge_id' => $message->forgeId,
            ]);

            return;
        }

        if ($message->reportedStatus instanceof \App\Git\Enum\ChangeRequestStatus) {
            $this->changeRequests->applyStatus($changeRequest, $message->reportedStatus, $message->mergeCommit);

            return;
        }

        $this->changeRequests->refreshStatus($changeRequest);
    }
}
