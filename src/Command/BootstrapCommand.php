<?php

declare(strict_types=1);

namespace App\Command;

use App\Git\Bootstrap\RepositoryBootstrapper;
use App\Git\Enum\RepositoryName;
use App\Laws\Import\JurisdictionSeeder;
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'repository',
            'r',
            InputOption::VALUE_REQUIRED,
            'Only bootstrap one repository (laws|content)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getOption('repository');

        // The federation and the 16 states are a fixed vocabulary (SPEC.md § 4.4).
        $created = $this->jurisdictions->seed();
        $io->writeln(\sprintf('Jurisdictions: %d created, %d already present.', $created, 17 - $created));

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

        $io->success('Repositories are ready.');

        return Command::SUCCESS;
    }
}
