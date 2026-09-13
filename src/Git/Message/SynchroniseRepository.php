<?php

declare(strict_types=1);

namespace App\Git\Message;

use App\Git\Enum\RepositoryName;

/**
 * Fetch one repository and refresh its mirror (SPEC.md § 3.2). Dispatched by the forge webhook and
 * by the ten-minute fallback poll.
 */
final readonly class SynchroniseRepository
{
    public function __construct(
        public RepositoryName $repository,
        public ?string $expectedCommit = null,
    ) {
    }
}
