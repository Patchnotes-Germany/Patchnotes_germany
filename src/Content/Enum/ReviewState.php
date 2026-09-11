<?php

declare(strict_types=1);

namespace App\Content\Enum;

/**
 * Editorial state of a card (SPEC.md § 5.3, § 5.6). Anything but auto_published/reviewed/corrected
 * stays off the website and triggers no notifications.
 */
enum ReviewState: string
{
    case Draft = 'draft';
    case NeedsReview = 'needs_review';
    case AutoPublished = 'auto_published';
    case Reviewed = 'reviewed';
    case Corrected = 'corrected';

    public function isPublishable(): bool
    {
        return \in_array($this, [self::AutoPublished, self::Reviewed, self::Corrected], true);
    }
}
