<?php

declare(strict_types=1);

namespace App\Laws\Sync\Safeguard;

/**
 * One reason not to merge automatically (SPEC.md § 4.6).
 */
final readonly class SafeguardViolation
{
    public function __construct(
        /** Machine-readable code, e.g. "law_text_deleted", stored on the ChangeRequest. */
        public string $code,
        /** Message for the pull request comment and the admin alert. */
        public string $message,
        /** Law slug or file path the violation refers to. */
        public ?string $subject = null,
    ) {
    }
}
