<?php

declare(strict_types=1);

namespace App\Git\Message;

use App\Git\Enum\RepositoryName;

/**
 * The default branch of a repository moved.
 *
 * This is the hand-over point from the git layer to the content pipeline: importing the new law
 * texts into the database (M3) and importing cards and translations (M5) react to it.
 */
final readonly class RepositoryUpdated
{
    public function __construct(
        public RepositoryName $repository,
        public string $commit,
        public ?string $previousCommit = null,
    ) {
    }
}
