<?php

declare(strict_types=1);

namespace App\Pipeline\Handler;

use App\Content\Entity\Change;
use App\Core\Config\PatchnotesConfig;
use App\Pipeline\Enum\PipelineState;
use App\Pipeline\Message\AdvanceSettledChanges;
use App\Pipeline\Message\AnalyzeChange;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Starts the analysis of changes that have stopped moving (SPEC.md § 24.4).
 *
 * One amending act reaches the consolidated text of different laws on different days. Analysing the
 * first arrival would describe half the change and then have to correct itself in public, so a
 * detected change waits out `review.settling_window_hours` before anything is written about it.
 *
 * Waiting is a property of time rather than of an event, which is why this runs on the scheduler and
 * not at the end of the detector.
 */
#[AsMessageHandler]
final readonly class AdvanceSettledChangesHandler
{
    /** A safety valve: a backlog is worked off over several runs instead of in one long job. */
    private const int BATCH = 50;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private PatchnotesConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AdvanceSettledChanges $message): void
    {
        $threshold = new \DateTimeImmutable(\sprintf('-%d hours', $this->windowHours()));

        /** @var list<Change> $settled */
        $settled = $this->entityManager->createQuery(
            'SELECT c FROM '.Change::class.' c'
            .' WHERE c.pipelineState = :detected'
            .' AND c.settlingStartedAt IS NOT NULL'
            .' AND c.settlingStartedAt <= :threshold'
            .' ORDER BY c.settlingStartedAt ASC',
        )
            ->setParameter('detected', PipelineState::Detected)
            ->setParameter('threshold', $threshold)
            ->setMaxResults(self::BATCH)
            ->getResult();

        foreach ($settled as $change) {
            $this->bus->dispatch(new AnalyzeChange($change->id()));

            $this->logger->info('Change settled, analysis queued', [
                'change' => $change->id(),
                'settling_started_at' => $change->settlingStartedAt()?->format(\DATE_ATOM),
            ]);
        }
    }

    private function windowHours(): int
    {
        $hours = $this->config->review()['settling_window_hours'] ?? 72;

        return is_numeric($hours) ? max(0, (int) $hours) : 72;
    }
}
