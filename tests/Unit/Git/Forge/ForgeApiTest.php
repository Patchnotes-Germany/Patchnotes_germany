<?php

declare(strict_types=1);

namespace App\Tests\Unit\Git\Forge;

use App\Git\Enum\ChangeRequestStatus;
use App\Git\Enum\ForgeType;
use App\Git\Enum\RepositoryName;
use App\Git\Forge\ChangeRequestDraft;
use App\Git\Forge\ForgeException;
use App\Git\Forge\GiteaForgeClient;
use App\Git\Forge\GitHubForgeClient;
use App\Git\Forge\GitLabForgeClient;
use App\Git\RepositoryConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The forge clients against recorded responses — no network in the test suite (SPEC.md § 19).
 */
#[CoversClass(GitHubForgeClient::class)]
#[CoversClass(GitLabForgeClient::class)]
#[CoversClass(GiteaForgeClient::class)]
final class ForgeApiTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];

    public function testGitHubOpensAPullRequestWithLabels(): void
    {
        $client = new GitHubForgeClient($this->http([
            new MockResponse('{"number": 456, "html_url": "https://github.com/org/laws/pull/456", "draft": false}'),
            new MockResponse('[]'),
        ]), new NullLogger());

        $changeRequest = $client->createChangeRequest($this->config(ForgeType::GitHub), new ChangeRequestDraft(
            'sync/bund/2026-06-10/2026-bund-bgbl-i-123',
            'main',
            'BGBl. 2026 I Nr. 123 — Fachkräfteeinwanderung',
            'Geänderte Normen: § 18g AufenthG',
            ['official-sync', 'jurisdiction:bund', 'auto-merge'],
        ));

        self::assertSame('456', $changeRequest->id);
        self::assertSame('https://github.com/org/laws/pull/456', $changeRequest->url);
        self::assertSame(ChangeRequestStatus::Open, $changeRequest->status);

        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://api.github.com/repos/org/laws/pulls', $this->requests[0]['url']);
        self::assertStringContainsString('"head":"sync\/bund\/2026-06-10\/2026-bund-bgbl-i-123"', $this->requests[0]['body']);
        self::assertStringContainsString('"base":"main"', $this->requests[0]['body']);

        self::assertSame('https://api.github.com/repos/org/laws/issues/456/labels', $this->requests[1]['url']);
        self::assertStringContainsString('official-sync', $this->requests[1]['body']);
    }

    public function testGitHubMergesWithAMergeCommitAndReportsTheHash(): void
    {
        $client = new GitHubForgeClient($this->http([
            new MockResponse('{"sha": "merge-sha", "merged": true}'),
        ]), new NullLogger());

        $sha = $client->merge($this->config(ForgeType::GitHub), '456', 'BGBl. 2026 I Nr. 123', 'Body');

        self::assertSame('merge-sha', $sha);
        self::assertSame('PUT', $this->requests[0]['method']);
        self::assertStringContainsString('"merge_method":"merge"', $this->requests[0]['body']);
    }

    public function testGitHubReportsTheStateOfAPullRequest(): void
    {
        $client = new GitHubForgeClient($this->http([
            new MockResponse('{"state": "closed", "merged": true}'),
            new MockResponse('{"state": "closed", "merged": false}'),
            new MockResponse('{"state": "open", "draft": true}'),
        ]), new NullLogger());

        $config = $this->config(ForgeType::GitHub);

        self::assertSame(ChangeRequestStatus::Merged, $client->status($config, '1'));
        self::assertSame(ChangeRequestStatus::Closed, $client->status($config, '2'));
        self::assertSame(ChangeRequestStatus::Draft, $client->status($config, '3'));
    }

    public function testAnApiErrorBecomesAForgeException(): void
    {
        $client = new GitHubForgeClient($this->http([
            new MockResponse('{"message": "Validation Failed"}', ['http_code' => 422]),
        ]), new NullLogger());

        $this->expectException(ForgeException::class);
        $this->expectExceptionMessageMatches('/HTTP 422/');

        $client->comment($this->config(ForgeType::GitHub), '456', 'report');
    }

    public function testGitLabOpensADraftMergeRequestByTitlePrefix(): void
    {
        $client = new GitLabForgeClient($this->http([
            new MockResponse('{"iid": 7, "web_url": "https://gitlab.com/org/laws/-/merge_requests/7"}'),
        ]), new NullLogger());

        $changeRequest = $client->createChangeRequest($this->config(ForgeType::GitLab), new ChangeRequestDraft(
            'preview/2026-bund-bgbl-i-123',
            'main',
            'Vorschau: BGBl. 2026 I Nr. 123',
            'Von der KI angewendete Änderungsbefehle.',
            ['preview', 'upcoming'],
            draft: true,
        ));

        self::assertSame('7', $changeRequest->id);
        self::assertSame(ChangeRequestStatus::Draft, $changeRequest->status);
        self::assertStringContainsString('"title":"Draft: Vorschau', $this->requests[0]['body']);
        self::assertStringContainsString('"labels":"preview,upcoming"', $this->requests[0]['body']);
        self::assertSame('https://gitlab.com/api/v4/projects/org%2Flaws/merge_requests', $this->requests[0]['url']);
    }

    public function testGitLabClosesAMergeRequest(): void
    {
        $client = new GitLabForgeClient($this->http([new MockResponse('{"state":"closed"}')]), new NullLogger());

        $client->close($this->config(ForgeType::GitLab), '7');

        self::assertSame('PUT', $this->requests[0]['method']);
        self::assertStringContainsString('"state_event":"close"', $this->requests[0]['body']);
    }

    public function testGiteaResolvesLabelNamesToIdsAndCreatesMissingOnes(): void
    {
        $client = new GiteaForgeClient($this->http([
            // create pull request
            new MockResponse('{"number": 12, "html_url": "https://codeberg.org/org/laws/pulls/12"}'),
            // existing labels
            new MockResponse('[{"id": 3, "name": "official-sync"}]'),
            // create the missing one
            new MockResponse('{"id": 9, "name": "auto-merge"}'),
            // attach labels
            new MockResponse('[]'),
        ]), new NullLogger());

        $changeRequest = $client->createChangeRequest($this->config(ForgeType::Gitea), new ChangeRequestDraft(
            'sync/bund/2026-06-10/change',
            'main',
            'Aktualisierung',
            'Body',
            ['official-sync', 'auto-merge'],
        ));

        self::assertSame('12', $changeRequest->id);
        self::assertStringContainsString('"name":"auto-merge"', $this->requests[2]['body']);
        self::assertSame('https://codeberg.org/api/v1/repos/org/laws/issues/12/labels', $this->requests[3]['url']);
        self::assertStringContainsString('"labels":[3,9]', $this->requests[3]['body']);
    }

    public function testGiteaReadsTheMergeCommitAfterMerging(): void
    {
        $client = new GiteaForgeClient($this->http([
            new MockResponse('', ['http_code' => 200]),
            new MockResponse('{"merged": true, "merged_commit_sha": "gitea-merge-sha"}'),
        ]), new NullLogger());

        self::assertSame(
            'gitea-merge-sha',
            $client->merge($this->config(ForgeType::Gitea), '12', 'Title', 'Message'),
        );
    }

    public function testSelfHostedInstancesUseTheConfiguredApiUrl(): void
    {
        $client = new GiteaForgeClient($this->http([new MockResponse('{"state":"open","merged":false}')]), new NullLogger());

        $config = new RepositoryConfig(
            RepositoryName::Content,
            'git@git.example.org:org/content.git',
            'main',
            ForgeType::Gitea,
            'https://git.example.org/api/v1',
            'org/content',
            null,
            'token',
            'secret',
            '/tmp/content',
            'Bot',
            'bot@example.org',
            true,
        );

        $client->status($config, '4');

        self::assertSame('https://git.example.org/api/v1/repos/org/content/pulls/4', $this->requests[0]['url']);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function http(array $responses): MockHttpClient
    {
        $this->requests = [];

        return new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): ResponseInterface {
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'body' => \is_string($options['body'] ?? null) ? $options['body'] : '',
            ];

            return array_shift($responses) ?? new MockResponse('{}');
        });
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
            'forge-token',
            'webhook-secret',
            '/tmp/laws',
            'Patchnotes Bot',
            'bot@example.org',
            true,
        );
    }
}
