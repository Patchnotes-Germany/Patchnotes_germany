<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Git\Message\RefreshChangeRequestStatus;
use App\Git\Message\SynchroniseRepository;
use App\Web\Controller\ForgeWebhookController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The webhook endpoint is the only unauthenticated entry point of the application, so the tests
 * focus on what must never happen: acting on an unsigned or tampered payload (SPEC.md § 3.2, § 16.1).
 */
#[CoversClass(ForgeWebhookController::class)]
final class ForgeWebhookControllerTest extends WebTestCase
{
    private const string SECRET = 'test-webhook-secret';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAPushToTheDefaultBranchSchedulesASynchronisation(): void
    {
        $payload = (string) json_encode(['ref' => 'refs/heads/main', 'after' => 'abc123']);

        $this->post('laws', $payload, [
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, self::SECRET),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);

        $messages = $this->dispatchedOnGitQueue();
        self::assertCount(1, $messages);
        self::assertInstanceOf(SynchroniseRepository::class, $messages[0]);
        self::assertSame('abc123', $messages[0]->expectedCommit);
    }

    public function testATamperedPayloadIsRejectedAndNothingIsDispatched(): void
    {
        $payload = (string) json_encode(['ref' => 'refs/heads/main', 'after' => 'abc123']);
        $signature = 'sha256='.hash_hmac('sha256', $payload, self::SECRET);

        $this->post('laws', $payload.' ', [
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame([], $this->dispatchedOnGitQueue());
    }

    public function testAnUnsignedRequestIsRejected(): void
    {
        $this->post('laws', '{"ref":"refs/heads/main"}', ['HTTP_X_GITHUB_EVENT' => 'push']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame([], $this->dispatchedOnGitQueue());
    }

    public function testAPushToAnotherBranchIsIgnored(): void
    {
        $payload = (string) json_encode(['ref' => 'refs/heads/sync/bund/2026-06-10/change', 'after' => 'abc']);

        $this->post('laws', $payload, [
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, self::SECRET),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->dispatchedOnGitQueue());
    }

    public function testAMergedPullRequestUpdatesTheChangeRequestAndSynchronises(): void
    {
        $payload = (string) json_encode([
            'action' => 'closed',
            'number' => 456,
            'pull_request' => [
                'merged' => true,
                'state' => 'closed',
                'merge_commit_sha' => 'merge-sha',
                'head' => ['ref' => 'sync/bund/2026-06-10/2026-bund-bgbl-i-123'],
            ],
        ]);

        $this->post('laws', $payload, [
            'HTTP_X_GITHUB_EVENT' => 'pull_request',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, self::SECRET),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);

        $messages = $this->dispatchedOnGitQueue();
        self::assertCount(2, $messages);
        self::assertInstanceOf(RefreshChangeRequestStatus::class, $messages[0]);
        self::assertSame('456', $messages[0]->forgeId);
        self::assertInstanceOf(SynchroniseRepository::class, $messages[1]);
    }

    public function testTheContentRepositoryUsesItsOwnForgeSignature(): void
    {
        $payload = (string) json_encode(['ref' => 'refs/heads/main', 'after' => 'content-sha']);

        // Gitea signs without the "sha256=" prefix; a GitHub-style signature must not be accepted.
        $this->post('content', $payload, [
            'HTTP_X_GITEA_EVENT' => 'push',
            'HTTP_X_GITEA_SIGNATURE' => 'sha256='.hash_hmac('sha256', $payload, self::SECRET),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->post('content', $payload, [
            'HTTP_X_GITEA_EVENT' => 'push',
            'HTTP_X_GITEA_SIGNATURE' => hash_hmac('sha256', $payload, self::SECRET),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
    }

    public function testAnUnknownRepositoryIsNotRouted(): void
    {
        $this->post('secrets', '{}', []);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * @param array<string, string> $server
     */
    private function post(string $repository, string $payload, array $server): void
    {
        $this->client->request(
            'POST',
            '/webhooks/forge/'.$repository,
            server: ['CONTENT_TYPE' => 'application/json'] + $server,
            content: $payload,
        );
    }

    /**
     * @return list<object>
     */
    private function dispatchedOnGitQueue(): array
    {
        $transport = self::getContainer()->get('messenger.transport.git');
        \assert($transport instanceof InMemoryTransport);

        return array_values(array_map(
            static fn (\Symfony\Component\Messenger\Envelope $envelope): object => $envelope->getMessage(),
            $transport->getSent(),
        ));
    }
}
