<?php

declare(strict_types=1);

namespace App\Git\Forge;

use App\Git\Enum\ChangeRequestStatus;
use App\Git\Enum\ForgeType;
use App\Git\RepositoryConfig;
use App\Git\Webhook\WebhookEvent;

/**
 * One forge behind one interface (SPEC.md § 3.1): GitHub, GitLab, Gitea/Forgejo, or "none" for
 * purely local operation in development and tests.
 *
 * GitLab calls it a merge request; the internal model is a ChangeRequest either way.
 */
interface ForgeClientInterface
{
    public function type(): ForgeType;

    public function createChangeRequest(RepositoryConfig $repository, ChangeRequestDraft $draft): ForgeChangeRequest;

    /**
     * @param list<string> $labels
     */
    public function addLabels(RepositoryConfig $repository, string $id, array $labels): void;

    public function comment(RepositoryConfig $repository, string $id, string $body): void;

    /**
     * Merges with a merge commit, so that "one amending act = one merge" stays visible
     * (SPEC.md § 4.5). Returns the merge commit hash when the forge reports one.
     */
    public function merge(RepositoryConfig $repository, string $id, string $title, string $message): ?string;

    public function close(RepositoryConfig $repository, string $id): void;

    public function status(RepositoryConfig $repository, string $id): ChangeRequestStatus;

    /**
     * @param array<string, string> $headers lower-cased header names
     */
    public function verifyWebhook(RepositoryConfig $repository, string $rawBody, array $headers): bool;

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers lower-cased header names
     */
    public function parseWebhook(array $payload, array $headers): ?WebhookEvent;
}
