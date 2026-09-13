<?php

declare(strict_types=1);

namespace App\Git\Value;

/**
 * Everything a commit needs beyond the files themselves.
 *
 * The git author date is the date of the synchronisation run, never a legal date: entry into force
 * lives in the facts of the content repository (SPEC.md § 4.5).
 */
final readonly class CommitRequest
{
    public function __construct(
        public string $message,
        public CommitTrailers $trailers,
        public ?Signature $author = null,
        public ?\DateTimeImmutable $date = null,
        /** @var list<string> Co-authored-by lines for edits made on behalf of a human editor. */
        public array $coAuthors = [],
    ) {
    }

    public function fullMessage(): string
    {
        $trailers = $this->trailers;
        foreach ($this->coAuthors as $coAuthor) {
            $trailers = $trailers->with('Co-authored-by', $coAuthor);
        }

        return $trailers->applyTo($this->message);
    }
}
