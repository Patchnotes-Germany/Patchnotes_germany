<?php

declare(strict_types=1);

namespace App\Tests\Unit\Laws\Normalizer;

use App\Laws\Normalizer\MarkdownEscaper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Law text must never be interpreted as Markdown, and enumerations must keep the numbers the
 * legislator printed (SPEC.md § 24.2).
 */
#[CoversClass(MarkdownEscaper::class)]
final class MarkdownEscaperTest extends TestCase
{
    private MarkdownEscaper $escaper;

    protected function setUp(): void
    {
        $this->escaper = new MarkdownEscaper();
    }

    public function testALineStartingWithAHashDoesNotBecomeAHeading(): void
    {
        self::assertSame('\\# 1 der Anlage', $this->escaper->escapeLine('# 1 der Anlage'));
    }

    public function testALineStartingWithADashDoesNotBecomeAList(): void
    {
        self::assertSame('\\- Buchstabe a', $this->escaper->escapeLine('- Buchstabe a'));
    }

    public function testALineStartingWithANumberKeepsItsNumber(): void
    {
        self::assertSame('1\\. Januar bis 31. Dezember', $this->escaper->escapeLine('1. Januar bis 31. Dezember'));
        self::assertSame('2\\) Die Frist beträgt einen Monat', $this->escaper->escapeLine('2) Die Frist beträgt einen Monat'));
    }

    public function testAQuotedLineDoesNotBecomeABlockquote(): void
    {
        self::assertSame('\\> 100 Euro', $this->escaper->escapeLine('> 100 Euro'));
    }

    public function testAPipeDoesNotStartATableRow(): void
    {
        self::assertSame('\\| Spalte', $this->escaper->escapeLine('| Spalte'));
    }

    public function testInlineMarkdownCharactersAreNeutralised(): void
    {
        self::assertSame(
            'Der Betrag \\*wird\\* mit \\_Zuschlag\\_ berechnet \\[siehe Anlage\\]',
            $this->escaper->escapeLine('Der Betrag *wird* mit _Zuschlag_ berechnet [siehe Anlage]'),
        );
    }

    public function testRawHtmlIsNeutralised(): void
    {
        self::assertSame('Bei Werten \\<img src=x> gilt Satz 1', $this->escaper->escapeLine('Bei Werten <img src=x> gilt Satz 1'));
    }

    public function testListMarkersKeepTheOriginalNumbering(): void
    {
        self::assertSame('1\\.', $this->escaper->escapeListMarker('1.'));
        self::assertSame('a\\)', $this->escaper->escapeListMarker('a)'));
        self::assertSame('aa\\)', $this->escaper->escapeListMarker('aa)'));
        self::assertSame('12\\.', $this->escaper->escapeListMarker(' 12. '));
    }

    public function testOrdinarySentencesAreLeftAlone(): void
    {
        $sentence = 'Die Aufenthaltserlaubnis wird nach § 18g erteilt.';

        self::assertSame($sentence, $this->escaper->escapeLine($sentence));
    }

    public function testEscapingIsIdempotentInTheSenseThatItNeverLosesText(): void
    {
        $line = '# 5 * Hinweis';
        $escaped = $this->escaper->escapeLine($line);

        self::assertSame($line, str_replace('\\', '', $escaped));
    }
}
