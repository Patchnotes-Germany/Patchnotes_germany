<?php

declare(strict_types=1);

namespace App\Command;

use App\Git\Bootstrap\RepositoryBootstrapper;
use App\Git\Enum\RepositoryName;
use App\Laws\Import\JurisdictionSeeder;
use App\Laws\Sync\BaselineImporter;
use App\Source\Registry\SourceRegistrar;
use App\Source\Value\SyncContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * First-time setup of the content repositories (SPEC.md § 3.1, § 4.7).
 *
 * M3 extends this command with the initial import of the federal laws; the repository structure is
 * created here and re-running the command is a no-op.
 */
#[AsCommand(
    name: 'patchnotes:bootstrap',
    description: 'Initialise the laws and content repositories',
)]
final class BootstrapCommand extends Command
{
    public function __construct(
        private readonly RepositoryBootstrapper $bootstrapper,
        private readonly JurisdictionSeeder $jurisdictions,
        private readonly SourceRegistrar $sources,
        private readonly BaselineImporter $baseline,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('repository', 'r', InputOption::VALUE_REQUIRED, 'Only bootstrap one repository (laws|content)')
            ->addOption('no-import', null, InputOption::VALUE_NONE, 'Skip the initial import of the federal laws')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Import at most this many laws (for a quick trial)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getOption('repository');

        // The federation and the 16 states are a fixed vocabulary (SPEC.md § 4.4).
        $created = $this->jurisdictions->seed();
        $io->writeln(\sprintf('Jurisdictions: %d created, %d already present.', $created, 17 - $created));

        // Sources must exist as rows before anything is fetched: raw documents hang off them.
        $registered = $this->sources->register();
        $io->writeln(\sprintf('Sources registered: %d new.', $registered));

        try {
            if (\is_string($only)) {
                $name = RepositoryName::tryFrom($only);
                if (null === $name) {
                    $io->error(\sprintf('Unknown repository "%s". Use "laws" or "content".', $only));

                    return Command::INVALID;
                }

                $results = [$name->value => $this->bootstrapper->bootstrap($name)];
            } else {
                $results = $this->bootstrapper->bootstrapAll();
            }
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $rows = [];
        foreach ($results as $repository => $commit) {
            $rows[] = [$repository, null !== $commit ? 'initialised ('.substr($commit, 0, 8).')' : 'already up to date'];
        }
        $io->table(['Repository', 'Result'], $rows);

        if ((bool) $input->getOption('no-import')) {
            $io->success('Repositories are ready. The initial import was skipped.');

            return Command::SUCCESS;
        }

        // The baseline: the current edition of all federal laws in one commit (SPEC.md § 4.7).
        $limit = $input->getOption('limit');
        $io->section('Initial import of the federal laws');
        $io->comment('This downloads every law from gesetze-im-internet at one request per second.');

        $baseline = $this->baseline->import(new SyncContext(
            correlationId: bin2hex(random_bytes(8)),
            limit: \is_string($limit) ? (int) $limit : null,
        ));

        if ($baseline->skipped) {
            $io->note('The baseline import already happened; nothing to do.');
        } elseif (0 === $baseline->laws) {
            $io->warning('No law could be imported — see the errors below.');
        } else {
            $io->writeln(\sprintf(
                'Imported %d laws with %d norms in commit %s.',
                $baseline->laws,
                $baseline->norms,
                substr((string) $baseline->commit, 0, 8),
            ));
        }

        foreach ($baseline->errors as $error) {
            $io->warning($error);
        }

        $io->success('Repositories are ready.');

        return $baseline->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }
}
