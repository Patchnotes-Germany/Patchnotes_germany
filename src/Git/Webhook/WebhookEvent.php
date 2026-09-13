<?php

declare(strict_types=1);

namespace App\Git\Webhook;

use App\Git\Enum\ChangeRequestStatus;

/**
 * A forge webhook reduced to what the application actually reacts to (SPEC.md § 3.2):
 * a push to the default branch starts a synchronisation, and a merged or closed pull request
 * updates the corresponding ChangeRequest.
 */
final readonly class WebhookEvent
{
    private function __construct(
        public WebhookEventType $type,
        public ?string $branch = null,
        public ?string $commit = null,
        public ?string $changeRequestId = null,
        public ?string $action = null,
        public ?ChangeRequestStatus $status = null,
        public ?string $mergeCommit = null,
    ) {
    }

    public static function push(?string $branch, ?string $commit): self
    {
        return new self(WebhookEventType::Push, branch: $branch, commit: $commit);
    }

    public static function changeRequest(
        string $id,
        string $action,
        ChangeRequestStatus $status,
        ?string $branch,
        ?string $mergeCommit,
    ): self {
        return new self(
            WebhookEventType::ChangeRequest,
            branch: $branch,
            changeRequestId: $id,
            action: $action,
            status: $status,
            mergeCommit: $mergeCommit,
        );
    }

    public function isPushTo(string $branch): bool
    {
        return WebhookEventType::Push === $this->type && $branch === $this->branch;
    }
}
