<?php

declare(strict_types=1);

namespace App\Command;

use App\Laws\Sync\BundLawSynchroniser;
use App\Laws\Sync\SyncOutcome;
use App\Source\Value\SyncContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manual run of the federal synchronisation (`make sync-bund`, SPEC.md § 18.2).
 */
#[AsCommand(
    name: 'patchnotes:sync:bund',
    description: 'Synchronise the federal consolidated laws from gesetze-im-internet',
)]
final class SyncBundCommand extends Command
{
    public function __construct(private readonly BundLawSynchroniser $synchroniser)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('law', 'l', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these law slugs')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many documents')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Ignore stored fingerprints and re-download everything')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Convert and report, but write nothing')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $laws */
        $laws = (array) $input->getOption('law');
        $limit = $input->getOption('limit');

        $context = new SyncContext(
            correlationId: bin2hex(random_bytes(8)),
            force: (bool) $input->getOption('force'),
            limit: \is_string($limit) ? (int) $limit : null,
            only: [] !== $laws ? $laws : null,
            dryRun: (bool) $input->getOption('dry-run'),
        );

        $io->title('Federal synchronisation');
        $io->comment('Correlation id: '.$context->correlationId);

        $report = $this->synchroniser->run($context);

        $io->table(
            ['Outcome', 'Laws'],
            [
                ['unchanged', (string) $report->countOf(SyncOutcome::Unchanged)],
                ['created', (string) $report->countOf(SyncOutcome::Created)],
                ['updated', (string) $report->countOf(SyncOutcome::Updated)],
                ['repealed', (string) $report->countOf(SyncOutcome::Repealed)],
                ['missing (not yet confirmed)', (string) $report->countOf(SyncOutcome::Missing)],
                ['failed', (string) $report->failures()],
            ],
        );

        foreach ($report->groups as $group) {
            $io->writeln(\sprintf(' • %s — %s', $group->changeId, implode(', ', $group->slugs())));
        }

        foreach ($report->failed() as $failure) {
            $io->warning(\sprintf('%s: %s', $failure->slug, (string) $failure->error));
        }

        if (!$report->safeguards->passed()) {
            $io->warning('Safeguards blocked the automatic merge:');
            $io->listing(array_map(
                static fn (\App\Laws\Sync\Safeguard\SafeguardViolation $violation): string => $violation->message,
                $report->safeguards->violations,
            ));

            return Command::SUCCESS;
        }

        $io->success(\sprintf(
            '%d of %d laws changed, %d pull request(s)%s.',
            $report->documentsChanged(),
            $report->documentsSeen(),
            \count($report->changeRequests),
            $report->merged ? ', merged' : '',
        ));

        return $report->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }
}
