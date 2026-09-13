<?php

declare(strict_types=1);

namespace App\Git\Message;

/**
 * The fallback for missed webhooks: every ten minutes all repositories are fetched (SPEC.md § 3.2).
 */
final readonly class SynchroniseAllRepositories
{
}
