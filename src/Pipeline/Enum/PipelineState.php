<?php

declare(strict_types=1);

namespace App\Pipeline\Enum;

/**
 * Position of a subject (change or bill) in the pipeline of SPEC.md § 7.1.
 *
 * Every stage is idempotent and resumable: the state is stored on the subject, so a restarted or
 * replayed job continues where it stopped instead of duplicating work.
 */
enum PipelineState: string
{
    case Detected = 'detected';
    case Analyzed = 'analyzed';
    case FactsVerified = 'facts_verified';
    case MasterCardWritten = 'master_card_written';
    case CardVerified = 'card_verified';
    case CardsTranslated = 'cards_translated';
    case TranslationsChecked = 'translations_checked';
    case ContentChangeRequestOpened = 'content_change_request_opened';
    case Published = 'published';
    case Indexed = 'indexed';
    case AudienceResolved = 'audience_resolved';
    case NotificationsScheduled = 'notifications_scheduled';
    /** Stopped: a quality check failed and a human has to look at it (SPEC.md § 7.4). */
    case NeedsReview = 'needs_review';

    /**
     * @return list<self>
     */
    public static function orderedStages(): array
    {
        return [
            self::Detected,
            self::Analyzed,
            self::FactsVerified,
            self::MasterCardWritten,
            self::CardVerified,
            self::CardsTranslated,
            self::TranslationsChecked,
            self::ContentChangeRequestOpened,
            self::Published,
            self::Indexed,
            self::AudienceResolved,
            self::NotificationsScheduled,
        ];
    }

    public function isAtLeast(self $stage): bool
    {
        $order = self::orderedStages();
        $own = array_search($this, $order, true);
        $other = array_search($stage, $order, true);

        return false !== $own && false !== $other && $own >= $other;
    }
}
