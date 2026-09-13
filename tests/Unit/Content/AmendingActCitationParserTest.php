<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\AmendingActCitationParser;
use App\Content\Value\AmendingActKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The change id decides which changes are grouped into one pull request and one card
 * (SPEC.md § 24.1), so the parser is exercised against the real notes of the 35 fixture laws.
 */
#[CoversClass(AmendingActCitationParser::class)]
final class AmendingActCitationParserTest extends TestCase
{
    private AmendingActCitationParser $parser;

    protected function setUp(): void
    {
        $this->parser = new AmendingActCitationParser();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function realNotes(): iterable
    {
        // Collected from tests/Fixtures/gii/expected/*.golden.md.
        yield 'electronic gazette' => ['Zuletzt geändert durch Art. 1 G v. 21.7.2026 I Nr. 221', '2026-bund-bgbl-i-221'];
        yield 'lowercase prefix' => ['zuletzt geändert durch Art. 7 G v. 29.6.2026 I Nr. 197', '2026-bund-bgbl-i-197'];
        yield 'with Absatz' => ['Zuletzt geändert durch Art. 11 Abs. 17 G v. 16.4.2026 I Nr. 107', '2026-bund-bgbl-i-107'];
        yield 'lettered article' => ['Zuletzt geändert durch Art. 2c G v. 24.7.2026 I Nr. 228', '2026-bund-bgbl-i-228'];
        yield 'regulation' => ['Zuletzt geändert durch Art. 4 V v. 30.1.2026 I Nr. 32', '2026-bund-bgbl-i-32'];
        yield 'printed gazette with page' => ['Geändert durch Art. 584 V v. 31.8.2015 I 1474', '2015-bund-bgbl-i-s1474'];
        yield 'printed gazette, law' => ['Zuletzt geändert durch Art. 2 G v. 14.6.2021 I 1762', '2021-bund-bgbl-i-s1762'];
        yield 're-publication' => ['Neugefasst durch Bek. v. 25.2.2008 I 162;', '2008-bund-bgbl-i-s162'];
        yield 'announced change' => [
            'Änderung durch Art. 3 G v. 22.7.2026 I Nr. 222 textlich nachgewiesen, dokumentarisch noch nicht abschließend bearbeitet',
            '2026-bund-bgbl-i-222',
        ];
        yield 'high article number' => ['Zuletzt geändert durch Art. 52 G v. 23.10.2024 I Nr. 323', '2024-bund-bgbl-i-323'];
    }

    #[DataProvider('realNotes')]
    public function testChangeIdFollowsTheSpecification(string $note, string $expected): void
    {
        self::assertSame($expected, $this->parser->changeId($note));
    }

    public function testTheFullReferenceIsParsed(): void
    {
        $reference = $this->parser->parse('Zuletzt geändert durch Art. 11 Abs. 17 G v. 16.4.2026 I Nr. 107');

        self::assertNotNull($reference);
        self::assertSame(AmendingActKind::Gesetz, $reference->kind);
        self::assertSame('2026-04-16', $reference->date->format('Y-m-d'));
        self::assertSame('I', $reference->part);
        self::assertSame(107, $reference->number);
        self::assertNull($reference->page);
        self::assertSame('Art. 11 Abs. 17', $reference->article);
        self::assertSame('BGBl. 2026 I Nr. 107', $reference->citation());
        self::assertSame('https://www.recht.bund.de/eli/bund/BGBl-1/2026/107/', $reference->url());
    }

    public function testPrintedCitationsHaveNoEliPermalink(): void
    {
        $reference = $this->parser->parse('Zuletzt geändert durch Art. 2 G v. 14.6.2021 I 1762');

        self::assertNotNull($reference);
        self::assertSame(1762, $reference->page);
        self::assertSame('BGBl. I 2021, 1762', $reference->citation());
        self::assertNull($reference->url());
    }

    public function testARepublicationIsRecognised(): void
    {
        $reference = $this->parser->parse('Neugefasst durch Bek. v. 12.1.2021 I 34;');

        self::assertNotNull($reference);
        self::assertTrue($reference->isRepublication());
        self::assertSame(AmendingActKind::Bekanntmachung, $reference->kind);
    }

    public function testTheSameActFromDifferentNotesGetsTheSameChangeId(): void
    {
        // The consolidated text says "zuletzt geändert durch …", the gazette adapter will report the
        // very same act — one act, one change id, one pull request (SPEC.md § 7.2).
        self::assertSame(
            $this->parser->changeId('Zuletzt geändert durch Art. 1 G v. 21.7.2026 I Nr. 221'),
            $this->parser->changeId('Änderung durch Art. 3 G v. 21.7.2026 I Nr. 221 ist berücksichtigt'),
        );
    }

    public function testNotesWithoutACitationYieldNothing(): void
    {
        self::assertNull($this->parser->parse(null));
        self::assertNull($this->parser->parse('   '));
        self::assertNull($this->parser->parse('Ersetzt G 8601-8 v. 22.12.2016'));
        self::assertNull($this->parser->changeId('Konstitutive Neufassung ohne Fundstelle'));
    }

    public function testPartTwoOfTheGazetteIsKeptApart(): void
    {
        $reference = $this->parser->parse('Zuletzt geändert durch Art. 1 G v. 5.5.2026 II Nr. 12');

        self::assertNotNull($reference);
        self::assertSame('II', $reference->part);
        self::assertSame('2026-bund-bgbl-ii-12', $reference->changeId());
        self::assertSame('https://www.recht.bund.de/eli/bund/BGBl-2/2026/12/', $reference->url());
    }

    public function testAnnouncedChangesAreDistinguishedFromIncorporatedOnes(): void
    {
        self::assertTrue($this->parser->isPending(
            'Änderung durch Art. 3 G v. 22.7.2026 I Nr. 222 textlich nachgewiesen, dokumentarisch noch nicht abschließend bearbeitet',
        ));
        self::assertFalse($this->parser->isPending('Änderung durch Art. 13 G v. 22.7.2026 I Nr. 222 ist berücksichtigt'));
    }

    public function testJurisdictionIsPartOfTheChangeId(): void
    {
        // States use their own gazettes; the id must not collide with a federal one (SPEC.md § 24.1).
        self::assertSame(
            '2026-be-bgbl-i-221',
            (string) $this->parser->changeId('Zuletzt geändert durch Art. 1 G v. 21.7.2026 I Nr. 221', 'be'),
        );
    }
}
