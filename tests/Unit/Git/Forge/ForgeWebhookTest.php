<?php

declare(strict_types=1);

namespace App\Tests\Unit\Git\Forge;

use App\Git\Enum\ChangeRequestStatus;
use App\Git\Enum\ForgeType;
use App\Git\Enum\RepositoryName;
use App\Git\Forge\GiteaForgeClient;
use App\Git\Forge\GitHubForgeClient;
use App\Git\Forge\GitLabForgeClient;
use App\Git\Forge\NoneForgeClient;
use App\Git\RepositoryConfig;
use App\Git\Webhook\WebhookEventType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * A webhook endpoint is an unauthenticated door into the system, so signature verification is
 * covered for every forge (SPEC.md § 3.2, § 16.1).
 */
#[CoversClass(GitHubForgeClient::class)]
#[CoversClass(GitLabForgeClient::class)]
#[CoversClass(GiteaForgeClient::class)]
#[CoversClass(NoneForgeClient::class)]
final class ForgeWebhookTest extends TestCase
{
    private const string SECRET = 'top-secret-webhook-value';

    public function testGitHubAcceptsOnlyACorrectHmacSignature(): void
    {
        $client = new GitHubForgeClient(new MockHttpClient(), new NullLogger());
        $body = '{"ref":"refs/heads/main"}';
        $signature = 'sha256='.hash_hmac('sha256', $body, self::SECRET);

        self::assertTrue($client->verifyWebhook($this->config(ForgeType::GitHub), $body, ['x-hub-signature-256' => $signature]));
        self::assertFalse($client->verifyWebhook($this->config(ForgeType::GitHub), $body, ['x-hub-signature-256' => 'sha256=deadbeef']));
        self::assertFalse($client->verifyWebhook($this->config(ForgeType::GitHub), $body, []));
        // Same signature, tampered payload.
        self::assertFalse($client->verifyWebhook($this->config(ForgeType::GitHub), $body.' ', ['x-hub-signature-256' => $signature]));
    }

    public function testGitLabComparesTheSecretToken(): void
    {
        $client = new GitLabForgeClient(new MockHttpClient(), new NullLogger());

        self::assertTrue($client->verifyWebhook($this->config(ForgeType::GitLab), '{}', ['x-gitlab-token' => self::SECRET]));
        self::assertFalse($client->verifyWebhook($this->config(ForgeType::GitLab), '{}', ['x-gitlab-token' => 'wrong']));
        self::assertFalse($client->verifyWebhook($this->config(ForgeType::GitLab), '{}', []));
    }

    public function testGiteaAcceptsOnlyACorrectHmacSignature(): void
    {
        $client = new GiteaForgeClient(new MockHttpClient(), new NullLogger());
        $body = '{"ref":"refs/heads/main"}';

        self::assertTrue($client->verifyWebhook(
            $this->config(ForgeType::Gitea),
            $body,
            ['x-gitea-signature' => hash_hmac('sha256', $body, self::SECRET)],
        ));
        self::assertFalse($client->verifyWebhook($this->config(ForgeType::Gitea), $body, ['x-gitea-signature' => 'nope']));
    }

    public function testWithoutASecretNothingIsAccepted(): void
    {
        $config = new RepositoryConfig(
            RepositoryName::Laws,
            '',
            'main',
            ForgeType::GitHub,
            null,
            'org/laws',
            null,
            null,
            null,
            '/tmp/laws',
            'Bot',
            'bot@example.org',
            false,
        );
        $client = new GitHubForgeClient(new MockHttpClient(), new NullLogger());

        $body = '{}';
        self::assertFalse($client->verifyWebhook($config, $body, [
            'x-hub-signature-256' => 'sha256='.hash_hmac('sha256', $body, ''),
        ]));
    }

    public function testWithoutAForgeWebhooksAreNeverAccepted(): void
    {
        $client = new NoneForgeClient();

        self::assertFalse($client->verifyWebhook($this->config(ForgeType::None), '{}', ['x-hub-signature-256' => 'sha256=x']));
        self::assertNull($client->parseWebhook([], []));
    }

    public function testGitHubPushAndPullRequestEventsAreUnderstood(): void
    {
        $client = new GitHubForgeClient(new MockHttpClient(), new NullLogger());

        $push = $client->parseWebhook(
            ['ref' => 'refs/heads/main', 'after' => 'abc123'],
            ['x-github-event' => 'push'],
        );
        self::assertNotNull($push);
        self::assertSame(WebhookEventType::Push, $push->type);
        self::assertTrue($push->isPushTo('main'));
        self::assertFalse($push->isPushTo('other'));
        self::assertSame('abc123', $push->commit);

        $merged = $client->parseWebhook([
            'action' => 'closed',
            'number' => 456,
            'pull_request' => [
                'merged' => true,
                'state' => 'closed',
                'merge_commit_sha' => 'merge-sha',
                'head' => ['ref' => 'sync/bund/2026-06-10/2026-bund-bgbl-i-123'],
            ],
        ], ['x-github-event' => 'pull_request']);

        self::assertNotNull($merged);
        self::assertSame(WebhookEventType::ChangeRequest, $merged->type);
        self::assertSame('456', $merged->changeRequestId);
        self::assertSame(ChangeRequestStatus::Merged, $merged->status);
        self::assertSame('merge-sha', $merged->mergeCommit);
    }

    public function testGitLabPushAndMergeRequestEventsAreUnderstood(): void
    {
        $client = new GitLabForgeClient(new MockHttpClient(), new NullLogger());

        $push = $client->parseWebhook(
            ['ref' => 'refs/heads/main', 'checkout_sha' => 'abc'],
            ['x-gitlab-event' => 'Push Hook'],
        );
        self::assertNotNull($push);
        self::assertTrue($push->isPushTo('main'));

        $mergeRequest = $client->parseWebhook([
            'object_attributes' => [
                'iid' => 7,
                'action' => 'merge',
                'state' => 'merged',
                'source_branch' => 'change/2026-bund-bgbl-i-123',
                'merge_commit_sha' => 'sha-7',
            ],
        ], ['x-gitlab-event' => 'Merge Request Hook']);

        self::assertNotNull($mergeRequest);
        self::assertSame('7', $mergeRequest->changeRequestId);
        self::assertSame(ChangeRequestStatus::Merged, $mergeRequest->status);
    }

    public function testGiteaPullRequestEventsAreUnderstood(): void
    {
        $client = new GiteaForgeClient(new MockHttpClient(), new NullLogger());

        $event = $client->parseWebhook([
            'action' => 'closed',
            'number' => 12,
            'pull_request' => ['state' => 'closed', 'merged' => false, 'head' => ['ref' => 'preview/x']],
        ], ['x-gitea-event' => 'pull_request']);

        self::assertNotNull($event);
        self::assertSame(ChangeRequestStatus::Closed, $event->status);
        self::assertSame('preview/x', $event->branch);
    }

    public function testUnknownEventsAreIgnored(): void
    {
        $clients = [
            new GitHubForgeClient(new MockHttpClient(), new NullLogger()),
            new GitLabForgeClient(new MockHttpClient(), new NullLogger()),
            new GiteaForgeClient(new MockHttpClient(), new NullLogger()),
        ];

        foreach ($clients as $client) {
            self::assertNull($client->parseWebhook(['anything' => true], ['x-github-event' => 'issues']));
        }
    }

    private function config(ForgeType $forge): RepositoryConfig
    {
        return new RepositoryConfig(
            RepositoryName::Laws,
            'git@example.org:org/laws.git',
            'main',
            $forge,
            null,
            'org/laws',
            null,
            'token',
            self::SECRET,
            '/tmp/laws',
            'Patchnotes Bot',
            'bot@example.org',
            false,
        );
    }
}
