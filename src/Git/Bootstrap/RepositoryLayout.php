<?php

declare(strict_types=1);

namespace App\Git\Bootstrap;

use App\Git\Enum\RepositoryName;

/**
 * The initial structure of a content repository (SPEC.md § 3.1: "if the remote repository is empty,
 * bootstrap initialises its structure").
 */
interface RepositoryLayout
{
    public function repository(): RepositoryName;

    /**
     * Relative path => file contents. Existing files are never overwritten by the bootstrap.
     *
     * @return array<string, string>
     */
    public function files(): array;
}
