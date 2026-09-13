<?php

declare(strict_types=1);

namespace App\Pipeline\Message;

/**
 * Have the master card checked by a *different* model than the one that wrote it
 * (SPEC.md § 7.1, § 24.14).
 *
 * Where only one provider is configured, the verification runs on another model of the same
 * provider and the score is capped, because a model reviewing itself is not a second opinion.
 */
final readonly class VerifyMasterCard
{
    public function __construct(public string $changeId)
    {
    }
}
