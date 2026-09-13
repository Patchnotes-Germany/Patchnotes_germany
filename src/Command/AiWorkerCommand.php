<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\Client\LlmClientFactory;
use App\Ai\Enum\AiProviderType;
use App\Ai\Exception\LlmException;
use App\Ai\Value\LlmRequest;
use App\Ai\Value\ModelReference;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The AI worker that runs on the owner's computer (SPEC.md § 8.3).
 *
 * It is the same application in CLI mode, but it needs nothing from the deployment: no database, no
 * repositories, no queue — only an HTTPS connection to the server and a local model server. It
 * claims a lease on a few jobs, runs them against the local model, posts the results back, and
 * stops cleanly on Ctrl+C or SIGTERM so no job is left leased longer than necessary.
 *
 * See docs/local-ai.md and compose.ai-worker.yaml.
 */
#[AsCommand(
    name: 'patchnotes:ai-worker',
    description: 'Run AI jobs from a Patchnotes server on a local model',
)]
final class AiWorkerCommand extends Command implements SignalableCommandInterface
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LlmClientFactory $clients,
        // "string:" turns an unset variable into "" instead of null, which a string parameter
        // would reject at container compile time.
        #[Autowire('%env(string:default::AI_WORKER_SERVER_URL)%')]
        private readonly string $defaultServer = '',
        #[Autowire('%env(string:default::AI_WORKER_TOKEN)%')]
        private readonly string $defaultToken = '',
        #[Autowire('%env(string:default::LOCAL_LLM_BASE_URL)%')]
        private readonly string $defaultBaseUrl = '',
    ) {
        parent::__construct();
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return \defined('SIGTERM') ? [\SIGTERM, \SIGINT] : [];
    }

    public function handleSignal(int $signal, false|int $previousExitCode = 0): false|int
    {
        // Finish the job in flight, then stop: an abandoned lease costs the server ten minutes.
        $this->shouldStop = true;

        return false;
    }

    protected function configure(): void
    {
        $this
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Base URL of the Patchnotes server')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Worker token issued in the admin')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'OpenAI-compatible URL of the local model server')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'API key of the local model server, if it needs one')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider alias to claim jobs for', 'local')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Model to use when a job does not name one')
            ->addOption('max', null, InputOption::VALUE_REQUIRED, 'How many jobs to claim at a time', '2')
            ->addOption('lease', null, InputOption::VALUE_REQUIRED, 'Lease per job, in seconds', '600')
            ->addOption('poll', null, InputOption::VALUE_REQUIRED, 'Seconds to wait when there is no work', '10')
            ->addOption('no-json-schema', null, InputOption::VALUE_NONE, 'The local server cannot enforce a JSON schema')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process one batch and exit (for tests and cron)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $server = rtrim($this->option($input, 'server') ?? $this->defaultServer, '/');
        $token = $this->option($input, 'token') ?? $this->defaultToken;
        $baseUrl = $this->option($input, 'base-url') ?? $this->defaultBaseUrl;

        if (\in_array('', [$server, $token, $baseUrl], true)) {
            $io->error('A server URL, a worker token and the URL of a local model server are required. See docs/local-ai.md.');

            return Command::INVALID;
        }

        $provider = $this->option($input, 'provider') ?? 'local';
        $client = $this->clients->create($provider, [
            'type' => AiProviderType::OpenAiCompatible->value,
            'base_url' => $baseUrl,
            'api_key' => $this->option($input, 'api-key'),
            'supports_json_schema' => !$input->getOption('no-json-schema'),
            // A local model may think for a long time; the lease is extended while it does.
            'timeout' => 900,
        ]);

        $max = max(1, (int) ($this->option($input, 'max') ?? '2'));
        $lease = max(60, (int) ($this->option($input, 'lease') ?? '600'));
        $poll = max(1, (int) ($this->option($input, 'poll') ?? '10'));
        $fallbackModel = $this->option($input, 'model');
        $once = (bool) $input->getOption('once');

        $io->title('Patchnotes AI worker');
        $io->writeln(\sprintf('Server:      %s', $server));
        $io->writeln(\sprintf('Local model: %s', $baseUrl));
        $io->writeln(\sprintf('Provider:    %s', $provider));
        $io->newLine();

        $processed = 0;

        while (!$this->shouldStop) {
            try {
                $jobs = $this->claim($server, $token, $provider, $max, $lease);
            } catch (HttpExceptionInterface $exception) {
                $io->warning('The server is unreachable: '.$exception->getMessage());
                $jobs = [];

                if ($once) {
                    return Command::FAILURE;
                }
            }

            foreach ($jobs as $job) {
                // Static analysis cannot see it, but the signal handler flips this while the batch
                // is running; without the check a Ctrl+C would still work through every claimed job.
                // @phpstan-ignore if.alwaysFalse
                if ($this->shouldStop) {
                    break;
                }

                $processed += $this->runJob($io, $client, $server, $token, $job, $fallbackModel) ? 1 : 0;
            }

            if ($once) {
                break;
            }

            if ([] === $jobs) {
                $this->heartbeat($server, $token);
                $this->sleep($poll);
            }
        }

        $io->success(\sprintf('%d job(s) processed.', $processed));

        return Command::SUCCESS;
    }

    /**
     * @param array{id: int, task: string, model: ?string, payload: array<string, mixed>} $job
     */
    private function runJob(
        SymfonyStyle $io,
        \App\Ai\Client\LlmClientInterface $client,
        string $server,
        string $token,
        array $job,
        ?string $fallbackModel,
    ): bool {
        $modelValue = $job['model'] ?? $fallbackModel;

        if (!\is_string($modelValue) || '' === $modelValue) {
            $this->post($server, $token, \sprintf('/api/worker/v1/jobs/%d/fail', $job['id']), [
                'error' => 'the job names no model and the worker was started without --model',
            ]);

            return false;
        }

        $request = LlmRequest::fromArray($job['payload'])->withModel(ModelReference::parse($modelValue));
        $startedAt = microtime(true);

        try {
            $response = $client->complete($request);
        } catch (LlmException $exception) {
            $io->writeln(\sprintf('<error>job %d failed: %s</error>', $job['id'], $exception->getMessage()));
            $this->post($server, $token, \sprintf('/api/worker/v1/jobs/%d/fail', $job['id']), [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        $this->post($server, $token, \sprintf('/api/worker/v1/jobs/%d/complete', $job['id']), [
            'content' => $response->content,
            'model' => $response->model,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        $io->writeln(\sprintf(
            '<info>job %d (%s) done in %.1fs</info>',
            $job['id'],
            $job['task'],
            microtime(true) - $startedAt,
        ));

        return true;
    }

    /**
     * @return list<array{id: int, task: string, model: ?string, payload: array<string, mixed>}>
     */
    private function claim(string $server, string $token, string $provider, int $max, int $lease): array
    {
        $data = $this->post($server, $token, '/api/worker/v1/claim', [
            'provider' => $provider,
            'max' => $max,
            'lease_seconds' => $lease,
        ]);

        /** @var list<array<string, mixed>> $jobs */
        $jobs = \is_array($data['jobs'] ?? null) ? $data['jobs'] : [];
        $claimed = [];

        foreach ($jobs as $job) {
            if (!is_numeric($job['id'] ?? null) || !\is_array($job['payload'] ?? null)) {
                continue;
            }

            /** @var array<string, mixed> $payload */
            $payload = $job['payload'];

            $claimed[] = [
                'id' => (int) $job['id'],
                'task' => (string) ($job['task'] ?? ''),
                'model' => \is_string($job['model'] ?? null) ? $job['model'] : null,
                'payload' => $payload,
            ];
        }

        return $claimed;
    }

    private function heartbeat(string $server, string $token): void
    {
        try {
            $this->post($server, $token, '/api/worker/v1/heartbeat', []);
        } catch (HttpExceptionInterface) {
            // The next claim will report the outage; a missed heartbeat is not worth a message.
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(string $server, string $token, string $path, array $payload): array
    {
        $response = $this->httpClient->request('POST', $server.$path, [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
            'timeout' => 30,
        ]);

        $body = $response->getContent(false);

        /** @var array<string, mixed> $decoded */
        $decoded = (array) json_decode($body, true);

        return $decoded;
    }

    private function sleep(int $seconds): void
    {
        // Woken up by a signal, so Ctrl+C does not have to wait out the whole interval.
        for ($i = 0; $i < $seconds && !$this->shouldStop; ++$i) {
            sleep(1);
        }
    }

    private function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
