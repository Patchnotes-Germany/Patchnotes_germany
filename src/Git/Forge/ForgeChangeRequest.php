<?php

declare(strict_types=1);

namespace App\Git\Forge;

use App\Git\Enum\ChangeRequestStatus;

/**
 * A pull/merge request as the forge sees it.
 */
final readonly class ForgeChangeRequest
{
    public function __construct(
        /** Number or id at the forge; the branch name when running without a forge. */
        public string $id,
        public ?string $url,
        public ChangeRequestStatus $status,
    ) {
    }
}
