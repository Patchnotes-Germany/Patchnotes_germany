<?php

declare(strict_types=1);

namespace App\Git\Forge;

use App\Git\Enum\ChangeRequestStatus;
use App\Git\Enum\ForgeType;
use App\Git\RepositoryConfig;
use App\Git\Webhook\WebhookEvent;

/**
 * Gitea and Forgejo API v1 (SPEC.md § 3.1), the self-hosted option for people who do not want the
 * law texts to live on a commercial platform.
 *
 * Unlike GitHub, Gitea addresses labels by id, so names are resolved (and created when missing).
 */
final class GiteaForgeClient extends AbstractHttpForgeClient
{
    public function type(): ForgeType
    {
        return ForgeType::Gitea;
    }

    public function createChangeRequest(RepositoryConfig $repository, ChangeRequestDraft $draft): ForgeChangeRequest
    {
        $response = $this->request($repository, 'POST', \sprintf('/repos/%s/pulls', $this->project($repository)), [
            'head' => $draft->branch,
            'base' => $draft->baseBranch,
            // Forgejo/Gitea mark drafts by the title prefix as well.
            'title' => $draft->draft ? 'WIP: '.$draft->title : $draft->title,
            'body' => $draft->body,
        ], 'create pull request');

        $index = self::stringOrNull($response['number'] ?? null)
            ?? throw ForgeException::request('gitea', 'create pull request', 200, 'response without a number');

        if ([] !== $draft->labels) {
            $this->addLabels($repository, $index, $draft->labels);
        }

        return new ForgeChangeRequest(
            $index,
            self::stringOrNull($response['html_url'] ?? $response['url'] ?? null),
            $draft->draft ? ChangeRequestStatus::Draft : ChangeRequestStatus::Open,
        );
    }

    public function addLabels(RepositoryConfig $repository, string $id, array $labels): void
    {
        if ([] === $labels) {
            return;
        }

        $ids = $this->resolveLabelIds($repository, $labels);
        if ([] === $ids) {
            return;
        }

        $this->request(
            $repository,
            'POST',
            \sprintf('/repos/%s/issues/%s/labels', $this->project($repository), $id),
            ['labels' => $ids],
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
        $this->request(
            $repository,
            'POST',
            \sprintf('/repos/%s/pulls/%s/merge', $this->project($repository), $id),
            ['Do' => 'merge', 'MergeTitleField' => $title, 'MergeMessageField' => $message],
            'merge pull request',
        );

        $pullRequest = $this->request(
            $repository,
            'GET',
            \sprintf('/repos/%s/pulls/%s', $this->project($repository), $id),
            [],
            'read pull request',
        );

        return self::stringOrNull($pullRequest['merged_commit_sha'] ?? null);
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

        return 'closed' === ($response['state'] ?? null) ? ChangeRequestStatus::Closed : ChangeRequestStatus::Open;
    }

    public function verifyWebhook(RepositoryConfig $repository, string $rawBody, array $headers): bool
    {
        return $this->verifyHmacSignature(
            $repository->webhookSecret,
            $rawBody,
            $headers['x-gitea-signature'] ?? null,
        );
    }

    public function parseWebhook(array $payload, array $headers): ?WebhookEvent
    {
        return match ($headers['x-gitea-event'] ?? null) {
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
        return 'https://codeberg.org/api/v1';
    }

    protected function authHeaders(RepositoryConfig $repository): array
    {
        return null !== $repository->token ? ['Authorization' => 'token '.$repository->token] : [];
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

        /** @var array<string, mixed> $head */
        $head = \is_array($pullRequest['head'] ?? null) ? $pullRequest['head'] : [];

        return WebhookEvent::changeRequest(
            $number,
            self::stringOrNull($payload['action'] ?? null) ?? 'updated',
            match (true) {
                true === ($pullRequest['merged'] ?? false) => ChangeRequestStatus::Merged,
                'closed' === ($pullRequest['state'] ?? null) => ChangeRequestStatus::Closed,
                default => ChangeRequestStatus::Open,
            },
            self::stringOrNull($head['ref'] ?? null),
            self::stringOrNull($pullRequest['merged_commit_sha'] ?? null),
        );
    }

    /**
     * @param list<string> $labels
     *
     * @return list<int>
     */
    private function resolveLabelIds(RepositoryConfig $repository, array $labels): array
    {
        $existing = $this->request(
            $repository,
            'GET',
            \sprintf('/repos/%s/labels', $this->project($repository)),
            [],
            'list labels',
        );

        /** @var list<array<string, mixed>> $items */
        $items = \is_array($existing['items'] ?? null) ? $existing['items'] : [];

        $byName = [];
        foreach ($items as $label) {
            $name = self::stringOrNull($label['name'] ?? null);
            $id = $label['id'] ?? null;
            if (null !== $name && \is_int($id)) {
                $byName[$name] = $id;
            }
        }

        $ids = [];
        foreach ($labels as $label) {
            if (isset($byName[$label])) {
                $ids[] = $byName[$label];
                continue;
            }

            $created = $this->request(
                $repository,
                'POST',
                \sprintf('/repos/%s/labels', $this->project($repository)),
                ['name' => $label, 'color' => '#ededed'],
                'create label',
            );
            if (\is_int($created['id'] ?? null)) {
                $ids[] = $created['id'];
            }
        }

        return $ids;
    }

    private function branchFromRef(?string $ref): ?string
    {
        if (null === $ref || !str_starts_with($ref, 'refs/heads/')) {
            return null;
        }

        return substr($ref, \strlen('refs/heads/'));
    }
}
