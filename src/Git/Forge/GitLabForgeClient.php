<?php

declare(strict_types=1);

namespace App\Git\Forge;

use App\Git\Enum\ChangeRequestStatus;
use App\Git\Enum\ForgeType;
use App\Git\RepositoryConfig;
use App\Git\Webhook\WebhookEvent;

/**
 * GitLab API v4, including self-hosted instances through forge_api_url (SPEC.md § 3.1).
 *
 * GitLab has no "draft" flag: a merge request is a draft when its title starts with "Draft:".
 */
final class GitLabForgeClient extends AbstractHttpForgeClient
{
    public function type(): ForgeType
    {
        return ForgeType::GitLab;
    }

    public function createChangeRequest(RepositoryConfig $repository, ChangeRequestDraft $draft): ForgeChangeRequest
    {
        $title = $draft->draft ? 'Draft: '.$draft->title : $draft->title;

        $response = $this->request($repository, 'POST', \sprintf('/projects/%s/merge_requests', $this->projectId($repository)), [
            'source_branch' => $draft->branch,
            'target_branch' => $draft->baseBranch,
            'title' => $title,
            'description' => $draft->body,
            'labels' => implode(',', $draft->labels),
        ], 'create merge request');

        $iid = self::stringOrNull($response['iid'] ?? null)
            ?? throw ForgeException::request('gitlab', 'create merge request', 200, 'response without an iid');

        return new ForgeChangeRequest(
            $iid,
            self::stringOrNull($response['web_url'] ?? null),
            $draft->draft ? ChangeRequestStatus::Draft : ChangeRequestStatus::Open,
        );
    }

    public function addLabels(RepositoryConfig $repository, string $id, array $labels): void
    {
        if ([] === $labels) {
            return;
        }

        $this->request(
            $repository,
            'PUT',
            \sprintf('/projects/%s/merge_requests/%s', $this->projectId($repository), $id),
            ['add_labels' => implode(',', $labels)],
            'add labels',
        );
    }

    public function comment(RepositoryConfig $repository, string $id, string $body): void
    {
        $this->request(
            $repository,
            'POST',
            \sprintf('/projects/%s/merge_requests/%s/notes', $this->projectId($repository), $id),
            ['body' => $body],
            'comment',
        );
    }

    public function merge(RepositoryConfig $repository, string $id, string $title, string $message): ?string
    {
        $response = $this->request(
            $repository,
            'PUT',
            \sprintf('/projects/%s/merge_requests/%s/merge', $this->projectId($repository), $id),
            ['merge_commit_message' => trim($title."\n\n".$message), 'squash' => false],
            'merge merge request',
        );

        return self::stringOrNull($response['merge_commit_sha'] ?? null);
    }

    public function close(RepositoryConfig $repository, string $id): void
    {
        $this->request(
            $repository,
            'PUT',
            \sprintf('/projects/%s/merge_requests/%s', $this->projectId($repository), $id),
            ['state_event' => 'close'],
            'close merge request',
        );
    }

    public function status(RepositoryConfig $repository, string $id): ChangeRequestStatus
    {
        $response = $this->request(
            $repository,
            'GET',
            \sprintf('/projects/%s/merge_requests/%s', $this->projectId($repository), $id),
            [],
            'read merge request',
        );

        return match ($response['state'] ?? null) {
            'merged' => ChangeRequestStatus::Merged,
            'closed', 'locked' => ChangeRequestStatus::Closed,
            default => true === ($response['draft'] ?? false) ? ChangeRequestStatus::Draft : ChangeRequestStatus::Open,
        };
    }

    /**
     * GitLab does not sign the payload; it echoes the configured secret token instead.
     */
    public function verifyWebhook(RepositoryConfig $repository, string $rawBody, array $headers): bool
    {
        $secret = $repository->webhookSecret;
        $token = $headers['x-gitlab-token'] ?? null;

        if (null === $secret || '' === $secret || null === $token || '' === $token) {
            return false;
        }

        return hash_equals($secret, $token);
    }

    public function parseWebhook(array $payload, array $headers): ?WebhookEvent
    {
        return match ($headers['x-gitlab-event'] ?? null) {
            'Push Hook' => WebhookEvent::push(
                $this->branchFromRef(self::stringOrNull($payload['ref'] ?? null)),
                self::stringOrNull($payload['checkout_sha'] ?? $payload['after'] ?? null),
            ),
            'Merge Request Hook' => $this->parseMergeRequestEvent($payload),
            default => null,
        };
    }

    protected function defaultApiUrl(): string
    {
        return 'https://gitlab.com/api/v4';
    }

    protected function authHeaders(RepositoryConfig $repository): array
    {
        return null !== $repository->token ? ['PRIVATE-TOKEN' => $repository->token] : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function parseMergeRequestEvent(array $payload): ?WebhookEvent
    {
        /** @var array<string, mixed> $attributes */
        $attributes = \is_array($payload['object_attributes'] ?? null) ? $payload['object_attributes'] : [];
        $iid = self::stringOrNull($attributes['iid'] ?? null);
        if (null === $iid) {
            return null;
        }

        return WebhookEvent::changeRequest(
            $iid,
            self::stringOrNull($attributes['action'] ?? null) ?? 'updated',
            match ($attributes['state'] ?? null) {
                'merged' => ChangeRequestStatus::Merged,
                'closed', 'locked' => ChangeRequestStatus::Closed,
                default => ChangeRequestStatus::Open,
            },
            self::stringOrNull($attributes['source_branch'] ?? null),
            self::stringOrNull($attributes['merge_commit_sha'] ?? null),
        );
    }

    private function projectId(RepositoryConfig $repository): string
    {
        return rawurlencode($this->project($repository));
    }

    private function branchFromRef(?string $ref): ?string
    {
        if (null === $ref || !str_starts_with($ref, 'refs/heads/')) {
            return null;
        }

        return substr($ref, \strlen('refs/heads/'));
    }
}
