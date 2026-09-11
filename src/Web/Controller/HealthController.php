<?php

declare(strict_types=1);

namespace App\Web\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Liveness and readiness probes (SPEC.md § 19).
 *
 * /healthz answers as long as the PHP process serves requests; container orchestrators use it to
 * decide whether to restart the container. /readyz additionally verifies the backing services and
 * therefore must never be used as a liveness probe.
 */
final readonly class HealthController
{
    public function __construct(
        private Connection $connection,
        private HttpClientInterface $httpClient,
        private string $meiliUrl,
        private string $meiliMasterKey,
    ) {
    }

    #[Route('/healthz', name: 'app_healthz', methods: ['GET', 'HEAD'])]
    public function liveness(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok'], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    #[Route('/readyz', name: 'app_readyz', methods: ['GET', 'HEAD'])]
    public function readiness(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'search' => $this->checkSearch(),
            'queues' => $this->checkQueues(),
        ];

        $ready = !\in_array(false, array_column($checks, 'ok'), true);

        return new JsonResponse(
            ['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks],
            $ready ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * @return array{ok: bool, detail?: string}
     */
    private function checkDatabase(): array
    {
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();

            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, detail?: string}
     */
    private function checkSearch(): array
    {
        try {
            $response = $this->httpClient->request('GET', rtrim($this->meiliUrl, '/').'/health', [
                'headers' => '' !== $this->meiliMasterKey ? ['Authorization' => 'Bearer '.$this->meiliMasterKey] : [],
                'timeout' => 3,
            ]);

            $status = $response->toArray(false)['status'] ?? 'unknown';

            return 'available' === $status ? ['ok' => true] : ['ok' => false, 'detail' => 'status: '.$status];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /**
     * The Doctrine Messenger transport table must exist and be readable; a growing failed queue is
     * reported but does not make the application unready (alerting handles that, SPEC.md § 19).
     *
     * @return array{ok: bool, detail?: string, failed?: int}
     */
    private function checkQueues(): array
    {
        try {
            $failed = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue',
                ['queue' => 'failed'],
            );

            return ['ok' => true, 'failed' => $failed];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }
}
