<?php

declare(strict_types=1);

namespace App\Source\Handler;

use App\Laws\Sync\BundLawSynchroniser;
use App\Source\Message\SynchroniseBundLaws;
use App\Source\Value\SyncContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SynchroniseBundLawsHandler
{
    public function __construct(
        private BundLawSynchroniser $synchroniser,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SynchroniseBundLaws $message): void
    {
        $context = new SyncContext(
            correlationId: $message->correlationId ?? bin2hex(random_bytes(8)),
            force: $message->force,
            limit: $message->limit,
            only: $message->only,
            dryRun: $message->dryRun,
        );

        $report = $this->synchroniser->run($context);

        $this->logger->info('Federal synchronisation finished', [
            'correlation_id' => $context->correlationId,
            'seen' => $report->documentsSeen(),
            'changed' => $report->documentsChanged(),
            'failed' => $report->failures(),
            'change_requests' => $report->changeRequests,
            'safeguards_passed' => $report->safeguards->passed(),
        ]);
    }
}
