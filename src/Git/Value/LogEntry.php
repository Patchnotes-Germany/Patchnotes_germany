<?php

declare(strict_types=1);

namespace App\Git\Value;

/**
 * One commit as shown by `git log`, used for the version history of a norm on the website.
 */
final readonly class LogEntry
{
    public function __construct(
        public string $commit,
        public string $authorName,
        public string $authorEmail,
        public \DateTimeImmutable $date,
        public string $subject,
        public string $body,
    ) {
    }

    public function trailers(): CommitTrailers
    {
        return CommitTrailers::parse($this->body);
    }

    public function changeId(): ?string
    {
        return $this->trailers()->get('Change-Id');
    }
}
