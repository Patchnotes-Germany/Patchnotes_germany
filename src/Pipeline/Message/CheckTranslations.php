<?php

declare(strict_types=1);

namespace App\Pipeline\Message;

/**
 * Run the deterministic checks over the finished cards (SPEC.md § 7.1, § 7.4 checks 3–7).
 *
 * Placeholders, sections, length, language, glossary and forbidden wording. An error here sends the
 * change to `needs_review` instead of opening a pull request.
 */
final readonly class CheckTranslations
{
    public function __construct(public string $changeId)
    {
    }
}
