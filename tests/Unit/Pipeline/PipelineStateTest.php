<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pipeline;

use App\Pipeline\Enum\PipelineState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PipelineState::class)]
final class PipelineStateTest extends TestCase
{
    public function testStagesFollowTheOrderOfTheSpecification(): void
    {
        self::assertSame(
            [
                'detected', 'analyzed', 'facts_verified', 'master_card_written', 'card_verified',
                'cards_translated', 'translations_checked', 'content_change_request_opened',
                'published', 'indexed', 'audience_resolved', 'notifications_scheduled',
            ],
            array_map(static fn (PipelineState $state): string => $state->value, PipelineState::orderedStages()),
        );
    }

    public function testAStageKnowsWhetherItHasPassedAnother(): void
    {
        self::assertTrue(PipelineState::Published->isAtLeast(PipelineState::CardsTranslated));
        self::assertTrue(PipelineState::Published->isAtLeast(PipelineState::Published));
        self::assertFalse(PipelineState::Analyzed->isAtLeast(PipelineState::Published));
    }

    public function testNeedsReviewIsOutsideTheHappyPath(): void
    {
        // A subject parked for human review must never look "further along" than a published one.
        self::assertFalse(PipelineState::NeedsReview->isAtLeast(PipelineState::Detected));
        self::assertFalse(PipelineState::Published->isAtLeast(PipelineState::NeedsReview));
    }
}
