<?php

declare(strict_types=1);

namespace App\Ai\Message;

/**
 * Return expired leases to the queue and move jobs to the cloud when the local worker stays offline
 * (SPEC.md § 8.3).
 */
final readonly class FallbackStaleAiJobs
{
}
