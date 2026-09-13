<?php

declare(strict_types=1);

namespace App\Pipeline\Message;

/**
 * Write the card in the master language, directly from the German source (SPEC.md § 5.2, § 7.1).
 *
 * Every other language is translated from this card, never from another translation.
 */
final readonly class WriteMasterCard
{
    public function __construct(public string $changeId)
    {
    }
}
