<?php

declare(strict_types=1);

namespace App\Git\Message;

use App\Git\Enum\ChangeRequestStatus;
use App\Git\Enum\RepositoryName;

/**
 * A pull request changed at the forge (webhook) — bring our copy in line.
 */
final readonly class RefreshChangeRequestStatus
{
    public function __construct(
        public RepositoryName $repository,
        public string $forgeId,
        public ?ChangeRequestStatus $reportedStatus = null,
        public ?string $mergeCommit = null,
    ) {
    }
}
