<?php

declare(strict_types=1);

namespace App\Pipeline\Message;

/**
 * Write the change into the `content` repository and open the pull request (SPEC.md § 5.6, § 7.1).
 *
 * Whether it is merged automatically is the review policy's decision, not this stage's: auto-publish
 * only when the checks passed, the verification score is high enough, and the impact does not
 * require a human.
 */
final readonly class PublishChange
{
    public function __construct(public string $changeId)
    {
    }
}
