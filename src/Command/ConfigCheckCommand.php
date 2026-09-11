<?php

declare(strict_types=1);

namespace App\Command;

use App\Core\Config\ConfigurationChecker;
use App\Core\Config\Severity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'patchnotes:config:check',
    description: 'Validate the environment-driven configuration (SPEC.md § 24.18)',
)]
final class ConfigCheckCommand extends Command
{
    public function __construct(private readonly ConfigurationChecker $checker)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $problems = $this->checker->check();

        if ([] === $problems) {
            $io->success('Configuration is valid.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Severity', 'Setting', 'Problem'],
            array_map(
                static fn ($problem): array => [$problem->severity->value, $problem->key, $problem->message],
                $problems,
            ),
        );

        $errors = array_filter($problems, static fn ($problem): bool => Severity::Error === $problem->severity);

        if ([] !== $errors) {
            $io->error(\sprintf('%d configuration error(s) must be fixed.', \count($errors)));

            return Command::FAILURE;
        }

        $io->warning('The configuration is usable, but some features are disabled (see above).');

        return Command::SUCCESS;
    }
}
