<?php

declare(strict_types=1);

namespace App\Tests\Unit\Laws\Sync;

use App\Laws\Sync\AmendingActGrouper;
use App\Laws\Sync\LawChangeGroup;
use App\Laws\Sync\LawSyncResult;
use App\Laws\Sync\SyncOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * One amending act must end up in one pull request, even when it changed a dozen laws
 * (SPEC.md § 4.5) — otherwise the history and the change cards fall apart.
 */
#[CoversClass(AmendingActGrouper::class)]
#[CoversClass(LawChangeGroup::class)]
final class AmendingActGrouperTest extends TestCase
{
    private const string RUN_DATE = '2026-06-10';

    public function testLawsChangedByTheSameActShareOnePullRequest(): void
    {
        $groups = $this->group([
            $this->law('aufenthg_2004', 'Aufenthaltsgesetz', '2026-bund-bgbl-i-221'),
            $this->law('aufenthv', 'Aufenthaltsverordnung', '2026-bund-bgbl-i-221'),
            $this->law('beschv_2013', 'Beschäftigungsverordnung', '2026-bund-bgbl-i-221'),
        ]);

        self::assertCount(1, $groups);
        self::assertSame('2026-bund-bgbl-i-221', $groups[0]->changeId);
        self::assertSame(['aufenthg_2004', 'aufenthv', 'beschv_2013'], $groups[0]->slugs());
        self::assertSame('sync/bund/2026-06-10/2026-bund-bgbl-i-221', $groups[0]->branch());
    }

    public function testDifferentActsBecomeDifferentPullRequests(): void
    {
        $groups = $this->group([
            $this->law('aufenthg_2004', 'Aufenthaltsgesetz', '2026-bund-bgbl-i-221'),
            $this->law('estg', 'Einkommensteuergesetz', '2026-bund-bgbl-i-107'),
        ]);

        self::assertCount(2, $groups);
        // Stable order regardless of the order the source delivered them in.
        self::assertSame(['2026-bund-bgbl-i-107', '2026-bund-bgbl-i-221'], array_map(
            static fn (LawChangeGroup $group): string => $group->changeId,
            $groups,
        ));
    }

    public function testALawWithoutAnIdentifiableActGetsItsOwnGroupWithTheFallbackId(): void
    {
        $groups = $this->group([
            new LawSyncResult(
                'wogg',
                SyncOutcome::Updated,
                'Wohngeldgesetz',
                contentHash: str_repeat('ab', 32),
            ),
        ]);

        self::assertCount(1, $groups);
        // {YYYY-MM-DD}-{jurisdiction}-{slug}-{sha8} per SPEC.md § 24.1.
        self::assertSame('2026-06-10-bund-wogg-abababab', $groups[0]->changeId);
        self::assertStringStartsWith('Aktualisierung: ', $groups[0]->title());
    }

    public function testUnchangedLawsAreNotGroupedAtAll(): void
    {
        $groups = $this->group([
            LawSyncResult::unchanged('bgb', 'Bürgerliches Gesetzbuch'),
            LawSyncResult::failed('estg', 'Einkommensteuergesetz', 'timeout'),
        ]);

        self::assertSame([], $groups);
    }

    public function testTheTitleUsesTheCitationAndListsTheLaws(): void
    {
        $groups = $this->group([
            $this->law('aufenthg_2004', 'Aufenthaltsgesetz', '2026-bund-bgbl-i-221'),
        ]);

        self::assertSame('BGBl. 2026 I Nr. 221 — Aufenthaltsgesetz', $groups[0]->title());
        self::assertSame(['official-sync', 'jurisdiction:bund', 'amending-act'], $groups[0]->labels());
    }

    public function testALongListOfLawsIsShortenedInTheTitle(): void
    {
        $groups = $this->group([
            $this->law('a', 'Gesetz A', '2026-bund-bgbl-i-1'),
            $this->law('b', 'Gesetz B', '2026-bund-bgbl-i-1'),
            $this->law('c', 'Gesetz C', '2026-bund-bgbl-i-1'),
            $this->law('d', 'Gesetz D', '2026-bund-bgbl-i-1'),
            $this->law('e', 'Gesetz E', '2026-bund-bgbl-i-1'),
        ]);

        self::assertStringContainsString('und 2 weitere', $groups[0]->title());
    }

    public function testAGroupOfPureRepealsIsRecognised(): void
    {
        $groups = $this->group([
            new LawSyncResult('altes_gesetz', SyncOutcome::Repealed, 'Altes Gesetz', changeId: '2026-bund-bgbl-i-9'),
        ]);

        self::assertTrue($groups[0]->repealsOnly());
    }

    /**
     * @param list<LawSyncResult> $results
     *
     * @return list<LawChangeGroup>
     */
    private function group(array $results): array
    {
        return new AmendingActGrouper()->group($results, new \DateTimeImmutable(self::RUN_DATE));
    }

    private function law(string $slug, string $title, string $changeId): LawSyncResult
    {
        $number = substr($changeId, strrpos($changeId, '-') + 1);

        return new LawSyncResult(
            $slug,
            SyncOutcome::Updated,
            $title,
            changeId: $changeId,
            amendingAct: 'BGBl. 2026 I Nr. '.$number,
            amendingActNote: 'Zuletzt geändert durch Art. 1 G v. 21.7.2026 I Nr. '.$number,
        );
    }
}
