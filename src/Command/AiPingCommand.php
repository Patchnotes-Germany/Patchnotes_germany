<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\Client\LlmClientRegistry;
use App\Ai\Enum\AiTask;
use App\Ai\Exception\LlmException;
use App\Ai\Routing\ModelRouter;
use App\Ai\Value\LlmRequest;
use App\Ai\Value\ModelReference;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows which models a task would actually use, and proves they answer (SPEC.md § 8.1, § 8.2).
 *
 * The routing depends on configuration, environment and the state of the budget, so "which model
 * will write this card?" is a question worth being able to ask directly — and with --say the answer
 * is proof that the provider really responds, which is the contract test of § 20 M4.
 */
#[AsCommand(
    name: 'patchnotes:ai:ping',
    description: 'Show the model chain of every AI task and check that the providers answer',
)]
final class AiPingCommand extends Command
{
    public function __construct(
        private readonly ModelRouter $router,
        private readonly LlmClientRegistry $clients,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('task', 't', InputOption::VALUE_REQUIRED, 'Only this task (e.g. change_analyze)')
            ->addOption('say', null, InputOption::VALUE_NONE, 'Send a real one-line request to the first model of each chain')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $only = $input->getOption('task');
        $tasks = \is_string($only) ? [AiTask::from($only)] : AiTask::cases();

        $rows = [];
        $tested = [];

        foreach ($tasks as $task) {
            $route = $this->router->route($task);
            $chain = array_map(strval(...), $route->chain);

            $rows[] = [
                $task->value,
                [] === $chain ? '<error>no usable model</error>' : implode(' → ', $chain),
                $route->pausedByBudget ? 'paused (budget)' : 'ok',
            ];

            $first = $route->first();

            if ($input->getOption('say') && $first instanceof ModelReference && !isset($tested[(string) $first])) {
                $tested[(string) $first] = $this->say($first);
            }
        }

        $io->table(['Task', 'Chain', 'State'], $rows);

        if ([] !== $tested) {
            $io->section('Live check');
            foreach ($tested as $model => $answer) {
                $io->writeln(\sprintf('%s: %s', $model, $answer));
            }
        }

        return \in_array('<error>no usable model</error>', array_column($rows, 1), true)
            ? Command::FAILURE
            : Command::SUCCESS;
    }

    private function say(ModelReference $model): string
    {
        $client = $this->clients->get($model->provider);

        $request = new LlmRequest(
            task: AiTask::LawTopics,
            systemPrompt: 'You answer with exactly one word.',
            userPrompt: 'Reply with the single word: ready',
            maxTokens: 2048,
            model: $model,
        );

        try {
            $response = $client->complete($request);
        } catch (LlmException $exception) {
            return '<error>'.$exception->getMessage().'</error>';
        }

        return \sprintf(
            '<info>%s</info> (%d ms, %d tokens in / %d out)',
            trim(preg_replace('/\s+/', ' ', $response->content) ?? ''),
            $response->durationMs,
            $response->usage->inputTokens,
            $response->usage->outputTokens,
        );
    }
}
