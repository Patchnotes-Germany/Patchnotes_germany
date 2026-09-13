<?php

declare(strict_types=1);

namespace App\Git\Value;

/**
 * Author or committer of a commit. The bot signs with the configured identity; community edits made
 * through the admin keep their author and add the editor as Co-authored-by (SPEC.md § 5.6).
 */
final readonly class Signature
{
    public function __construct(
        public string $name,
        public string $email,
        public ?\DateTimeImmutable $date = null,
    ) {
    }

    public function toString(): string
    {
        return \sprintf('%s <%s>', $this->name, $this->email);
    }
}
