<?php

declare(strict_types=1);

namespace App\Git\Forge;

use App\Git\Enum\ChangeRequestStatus;
use App\Git\Enum\ForgeType;
use App\Git\RepositoryConfig;
use App\Git\Webhook\WebhookEvent;

/**
 * "forge: none" — local branches and merges without any API (SPEC.md § 3.1).
 *
 * This is the default in development and in tests, and it is a legitimate production mode for an
 * operator who keeps the repositories on their own machine. The branch name stands in for the pull
 * request id; merging is done by the git layer itself.
 */
final class NoneForgeClient implements ForgeClientInterface
{
    public function type(): ForgeType
    {
        return ForgeType::None;
    }

    public function createChangeRequest(RepositoryConfig $repository, ChangeRequestDraft $draft): ForgeChangeRequest
    {
        return new ForgeChangeRequest(
            $draft->branch,
            null,
            $draft->draft ? ChangeRequestStatus::Draft : ChangeRequestStatus::Open,
        );
    }

    public function addLabels(RepositoryConfig $repository, string $id, array $labels): void
    {
        // Labels are kept on the ChangeRequest entity; there is no forge to mirror them to.
    }

    public function comment(RepositoryConfig $repository, string $id, string $body): void
    {
        // Check reports are stored on the ChangeRequest entity and shown in the admin.
    }

    public function merge(RepositoryConfig $repository, string $id, string $title, string $message): ?string
    {
        // The caller merges locally through GitRepository::merge().
        return null;
    }

    public function close(RepositoryConfig $repository, string $id): void
    {
    }

    public function status(RepositoryConfig $repository, string $id): ChangeRequestStatus
    {
        return ChangeRequestStatus::Open;
    }

    public function verifyWebhook(RepositoryConfig $repository, string $rawBody, array $headers): bool
    {
        // Without a forge there are no webhooks: never accept an unauthenticated call.
        return false;
    }

    public function parseWebhook(array $payload, array $headers): ?WebhookEvent
    {
        return null;
    }
}
