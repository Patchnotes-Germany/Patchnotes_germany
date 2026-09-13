<?php

declare(strict_types=1);

namespace App\Ai\Prompt;

/**
 * A rendered prompt pair and the template version it came from (SPEC.md § 8.4).
 */
final readonly class RenderedPrompt
{
    public function __construct(
        public string $system,
        public string $user,
        public int $version,
    ) {
    }
}
