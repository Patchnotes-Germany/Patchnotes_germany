<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Web\Controller\HealthController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(HealthController::class)]
final class HealthEndpointTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testLivenessProbeAnswersOk(): void
    {
        $this->client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['status' => 'ok'], $this->decodedResponse());
    }

    public function testLivenessProbeIsNotCached(): void
    {
        $this->client->request('GET', '/healthz');

        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
    }

    /**
     * The readiness probe talks to MySQL and Meilisearch, which may or may not be reachable from the
     * test run; what must always hold is the contract: a JSON report of every dependency.
     */
    public function testReadinessProbeReportsEveryDependency(): void
    {
        $this->client->request('GET', '/readyz');

        $response = $this->client->getResponse();
        self::assertContains(
            $response->getStatusCode(),
            [Response::HTTP_OK, Response::HTTP_SERVICE_UNAVAILABLE],
        );

        $payload = $this->decodedResponse();
        self::assertContains($payload['status'], ['ready', 'not_ready']);
        self::assertSame(['database', 'search', 'queues'], array_keys($payload['checks']));
        foreach ($payload['checks'] as $check) {
            self::assertIsBool($check['ok']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedResponse(): array
    {
        $content = (string) $this->client->getResponse()->getContent();

        return (array) json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
    }
}
