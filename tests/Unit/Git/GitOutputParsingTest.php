<?php

declare(strict_types=1);

namespace App\Tests\Unit\Git;

use App\Git\GitRepository;
use App\Git\RepositoryReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Parsing of git's machine-readable output. These parsers feed the diff, history and blame views
 * of the website (SPEC.md § 13.2) and the safeguards of § 4.6, so they are covered separately from
 * the process handling.
 */
#[CoversClass(GitRepository::class)]
#[CoversClass(RepositoryReader::class)]
final class GitOutputParsingTest extends TestCase
{
    public function testNumstatIsSummedPerFile(): void
    {
        $stat = GitRepository::parseNumstat([
            "12\t3\tbund/aufenthg_2004/p18g.md",
            "0\t40\tbund/aufenthg_2004/p19c.md",
        ]);

        self::assertSame(2, $stat->fileCount());
        self::assertSame(12, $stat->insertions);
        self::assertSame(43, $stat->deletions);
        self::assertSame(['bund/aufenthg_2004/p18g.md', 'bund/aufenthg_2004/p19c.md'], $stat->paths());
        self::assertSame(['added' => 0, 'removed' => 40], $stat->files['bund/aufenthg_2004/p19c.md']);
    }

    public function testBinaryFilesDoNotBreakTheStatistics(): void
    {
        $stat = GitRepository::parseNumstat(["-\t-\tbund/aufenthg_2004/anlage.pdf"]);

        self::assertSame(1, $stat->fileCount());
        self::assertSame(0, $stat->insertions);
        self::assertSame(0, $stat->deletions);
    }

    public function testLogRecordsAreSplitOnTheRecordSeparator(): void
    {
        $output = implode('', [
            "abc123\x1fPatchnotes Bot\x1fbot@example.org\x1f2026-06-10T04:12:00+00:00\x1fAktualisierung: AufenthG\x1fChange-Id: 2026-bund-bgbl-i-123\x1e",
            "def456\x1fPatchnotes Bot\x1fbot@example.org\x1f2026-05-02T03:00:00+00:00\x1fInitial import: bund\x1f\x1e",
        ]);

        $entries = RepositoryReader::parseLog($output);

        self::assertCount(2, $entries);
        self::assertSame('abc123', $entries[0]->commit);
        self::assertSame('Aktualisierung: AufenthG', $entries[0]->subject);
        self::assertSame('2026-bund-bgbl-i-123', $entries[0]->changeId());
        self::assertSame('2026-06-10', $entries[0]->date->format('Y-m-d'));
        self::assertNull($entries[1]->changeId());
    }

    public function testBlameMapsEverySentenceToTheCommitThatIntroducedIt(): void
    {
        $porcelain = <<<'BLAME'
            1111111111111111111111111111111111111111 1 1 2
            author Patchnotes Bot
            author-time 1780000000
            author-tz +0000
            summary Initial import: bund
            filename bund/aufenthg_2004/p18g.md
            	Einer Ausländerin oder einem Ausländer wird eine Blaue Karte EU erteilt.
            2222222222222222222222222222222222222222 2 2
            author Patchnotes Bot
            author-time 1781000000
            author-tz +0000
            summary Aktualisierung: AufenthG
            filename bund/aufenthg_2004/p18g.md
            	Das Gehalt muss mindestens 48 300 Euro betragen.
            BLAME;

        $lines = RepositoryReader::parsePorcelainBlame($porcelain);

        self::assertCount(2, $lines);
        self::assertSame(1, $lines[0]->lineNumber);
        self::assertSame('Initial import: bund', $lines[0]->summary);
        self::assertStringContainsString('Blaue Karte EU', $lines[0]->content);
        self::assertSame(2, $lines[1]->lineNumber);
        self::assertSame('2222222222222222222222222222222222222222', $lines[1]->commit);
        self::assertStringContainsString('48 300 Euro', $lines[1]->content);
    }

    public function testBranchNamesBecomeSafeDirectoryNames(): void
    {
        self::assertSame(
            'sync-bund-2026-06-10-2026-bund-bgbl-i-123',
            GitRepository::sanitiseBranchForPath('sync/bund/2026-06-10/2026-bund-bgbl-i-123'),
        );
        self::assertSame('a-b', GitRepository::sanitiseBranchForPath('../a/../b/'));
    }
}
