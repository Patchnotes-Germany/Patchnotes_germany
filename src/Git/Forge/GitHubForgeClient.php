<?php

declare(strict_types=1);

namespace App\Git\Forge;

use App\Git\Enum\ChangeRequestStatus;
use App\Git\Enum\ForgeType;
use App\Git\RepositoryConfig;
use App\Git\Webhook\WebhookEvent;

/**
 * GitHub REST API v3 (SPEC.md § 3.1). Tokens need only "contents" and "pull requests" on the two
 * content repositories (SPEC.md § 16.1).
 */
final class GitHubForgeClient extends AbstractHttpForgeClient
{
    public function type(): ForgeType
    {
        return ForgeType::GitHub;
    }

    public function createChangeRequest(RepositoryConfig $repository, ChangeRequestDraft $draft): ForgeChangeRequest
    {
        $response = $this->request($repository, 'POST', \sprintf('/repos/%s/pulls', $this->project($repository)), [
            'title' => $draft->title,
            'head' => $draft->branch,
            'base' => $draft->baseBranch,
            'body' => $draft->body,
            'draft' => $draft->draft,
        ], 'create pull request');

        $number = self::stringOrNull($response['number'] ?? null)
            ?? throw ForgeException::request('github', 'create pull request', 200, 'response without a number');

        if ([] !== $draft->labels) {
            $this->addLabels($repository, $number, $draft->labels);
        }

        return new ForgeChangeRequest(
            $number,
            self::stringOrNull($response['html_url'] ?? null),
            true === ($response['draft'] ?? false) ? ChangeRequestStatus::Draft : ChangeRequestStatus::Open,
        );
    }

    public function addLabels(RepositoryConfig $repository, string $id, array $labels): void
    {
        if ([] === $labels) {
            return;
        }

        $this->request(
            $repository,
            'POST',
            \sprintf('/repos/%s/issues/%s/labels', $this->project($repository), $id),
            ['labels' => $labels],
            'add labels',
        );
    }

    public function comment(RepositoryConfig $repository, string $id, string $body): void
    {
        $this->request(
            $repository,
            'POST',
            \sprintf('/repos/%s/issues/%s/comments', $this->project($repository), $id),
            ['body' => $body],
            'comment',
        );
    }

    public function merge(RepositoryConfig $repository, string $id, string $title, string $message): ?string
    {
        $response = $this->request(
            $repository,
            'PUT',
            \sprintf('/repos/%s/pulls/%s/merge', $this->project($repository), $id),
            ['commit_title' => $title, 'commit_message' => $message, 'merge_method' => 'merge'],
            'merge pull request',
        );

        return self::stringOrNull($response['sha'] ?? null);
    }

    public function close(RepositoryConfig $repository, string $id): void
    {
        $this->request(
            $repository,
            'PATCH',
            \sprintf('/repos/%s/pulls/%s', $this->project($repository), $id),
            ['state' => 'closed'],
            'close pull request',
        );
    }

    public function status(RepositoryConfig $repository, string $id): ChangeRequestStatus
    {
        $response = $this->request(
            $repository,
            'GET',
            \sprintf('/repos/%s/pulls/%s', $this->project($repository), $id),
            [],
            'read pull request',
        );

        if (true === ($response['merged'] ?? false)) {
            return ChangeRequestStatus::Merged;
        }
        if ('closed' === ($response['state'] ?? null)) {
            return ChangeRequestStatus::Closed;
        }

        return true === ($response['draft'] ?? false) ? ChangeRequestStatus::Draft : ChangeRequestStatus::Open;
    }

    public function verifyWebhook(RepositoryConfig $repository, string $rawBody, array $headers): bool
    {
        return $this->verifyHmacSignature(
            $repository->webhookSecret,
            $rawBody,
            $headers['x-hub-signature-256'] ?? null,
            'sha256=',
        );
    }

    public function parseWebhook(array $payload, array $headers): ?WebhookEvent
    {
        return match ($headers['x-github-event'] ?? null) {
            'push' => WebhookEvent::push(
                $this->branchFromRef(self::stringOrNull($payload['ref'] ?? null)),
                self::stringOrNull($payload['after'] ?? null),
            ),
            'pull_request' => $this->parsePullRequestEvent($payload),
            default => null,
        };
    }

    protected function defaultApiUrl(): string
    {
        return 'https://api.github.com';
    }

    protected function authHeaders(RepositoryConfig $repository): array
    {
        $headers = [
            'X-GitHub-Api-Version' => '2022-11-28',
            'Accept' => 'application/vnd.github+json',
        ];

        if (null !== $repository->token) {
            $headers['Authorization'] = 'Bearer '.$repository->token;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function parsePullRequestEvent(array $payload): ?WebhookEvent
    {
        /** @var array<string, mixed> $pullRequest */
        $pullRequest = \is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];
        $number = self::stringOrNull($payload['number'] ?? $pullRequest['number'] ?? null);
        if (null === $number) {
            return null;
        }

        $merged = true === ($pullRequest['merged'] ?? false);
        /** @var array<string, mixed> $head */
        $head = \is_array($pullRequest['head'] ?? null) ? $pullRequest['head'] : [];

        return WebhookEvent::changeRequest(
            $number,
            self::stringOrNull($payload['action'] ?? null) ?? 'updated',
            match (true) {
                $merged => ChangeRequestStatus::Merged,
                'closed' === ($pullRequest['state'] ?? null) => ChangeRequestStatus::Closed,
                default => ChangeRequestStatus::Open,
            },
            self::stringOrNull($head['ref'] ?? null),
            self::stringOrNull($pullRequest['merge_commit_sha'] ?? null),
        );
    }

    private function branchFromRef(?string $ref): ?string
    {
        if (null === $ref || !str_starts_with($ref, 'refs/heads/')) {
            return null;
        }

        return substr($ref, \strlen('refs/heads/'));
    }
}
