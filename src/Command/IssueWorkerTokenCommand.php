<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\Entity\WorkerToken;
use App\Ai\Worker\WorkerTokenIssuer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Issues, lists and revokes credentials for remote AI workers (SPEC.md § 8.3).
 *
 * The admin UI does the same from the browser (M8); this command exists because the first worker
 * has to be set up before there is anyone to log in as.
 */
#[AsCommand(
    name: 'patchnotes:ai:worker-token',
    description: 'Issue, list or revoke a token for a remote AI worker',
)]
final class IssueWorkerTokenCommand extends Command
{
    public function __construct(private readonly WorkerTokenIssuer $issuer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'A name for the machine, e.g. "office-imac"')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List the existing tokens')
            ->addOption('revoke', null, InputOption::VALUE_REQUIRED, 'Revoke the token with this id')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('list')) {
            return $this->list($io);
        }

        $revoke = $input->getOption('revoke');

        if (\is_string($revoke)) {
            return $this->revoke($io, (int) $revoke);
        }

        $name = $input->getArgument('name');

        if (!\is_string($name) || '' === trim($name)) {
            $io->error('A name for the worker is required, for example: patchnotes:ai:worker-token office-imac');

            return Command::INVALID;
        }

        [$token, $plain] = $this->issuer->issue(trim($name));

        $io->success(\sprintf('Token #%d issued for "%s".', $token->id() ?? 0, $token->name()));
        $io->writeln('Store it now — only its hash is kept, so it cannot be shown again:');
        $io->newLine();
        $io->writeln('  AI_WORKER_TOKEN='.$plain);
        $io->newLine();

        return Command::SUCCESS;
    }

    private function list(SymfonyStyle $io): int
    {
        $rows = array_map(static fn (WorkerToken $token): array => [
            $token->id(),
            $token->name(),
            $token->createdAt()->format('Y-m-d H:i'),
            $token->lastSeenAt()?->format('Y-m-d H:i') ?? 'never',
            $token->isActive() ? 'active' : 'revoked',
        ], $this->issuer->all());

        if ([] === $rows) {
            $io->writeln('No worker tokens have been issued yet.');

            return Command::SUCCESS;
        }

        $io->table(['#', 'Name', 'Created', 'Last seen', 'State'], $rows);

        return Command::SUCCESS;
    }

    private function revoke(SymfonyStyle $io, int $id): int
    {
        foreach ($this->issuer->all() as $token) {
            if ($token->id() === $id) {
                $this->issuer->revoke($token);
                $io->success(\sprintf('Token #%d ("%s") revoked.', $id, $token->name()));

                return Command::SUCCESS;
            }
        }

        $io->error(\sprintf('There is no worker token #%d.', $id));

        return Command::FAILURE;
    }
}
