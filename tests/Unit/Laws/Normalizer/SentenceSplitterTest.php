<?php

declare(strict_types=1);

namespace App\Tests\Unit\Laws\Normalizer;

use App\Laws\Normalizer\SentenceSplitter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One sentence per line is what makes the diffs and the blame view of a law meaningful
 * (SPEC.md § 4.2), so a wrong split is a real defect, not a cosmetic one.
 */
#[CoversClass(SentenceSplitter::class)]
final class SentenceSplitterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function germanLegalProse(): iterable
    {
        yield 'plain sentences' => [
            'Die Aufenthaltserlaubnis wird befristet erteilt. Sie kann verlängert werden.',
            ['Die Aufenthaltserlaubnis wird befristet erteilt.', 'Sie kann verlängert werden.'],
        ];

        yield 'reference abbreviations do not end a sentence' => [
            'Die Voraussetzungen nach § 18 Abs. 2 Nr. 3 gelten als erfüllt. Satz 1 bleibt unberührt.',
            ['Die Voraussetzungen nach § 18 Abs. 2 Nr. 3 gelten als erfüllt.', 'Satz 1 bleibt unberührt.'],
        ];

        yield 'z. B. inside a sentence' => [
            'Dies gilt z. B. für Fachkräfte mit akademischer Ausbildung. Ausnahmen regelt die Verordnung.',
            ['Dies gilt z. B. für Fachkräfte mit akademischer Ausbildung.', 'Ausnahmen regelt die Verordnung.'],
        ];

        yield 'i. V. m. inside a sentence' => [
            '§ 4 i. V. m. § 5 findet Anwendung. Weitere Regelungen bleiben unberührt.',
            ['§ 4 i. V. m. § 5 findet Anwendung.', 'Weitere Regelungen bleiben unberührt.'],
        ];

        yield 'dates are not split' => [
            'Das Gesetz tritt am 1. Januar 2026 in Kraft. Es gilt unbefristet.',
            ['Das Gesetz tritt am 1. Januar 2026 in Kraft.', 'Es gilt unbefristet.'],
        ];

        yield 'ordinals in running text' => [
            'Die 2. Verordnung zur Änderung bleibt unberührt. Näheres regelt das Bundesministerium.',
            ['Die 2. Verordnung zur Änderung bleibt unberührt.', 'Näheres regelt das Bundesministerium.'],
        ];

        yield 'sentence ending on a paragraph reference' => [
            'Zuständig ist die Behörde nach § 71 Absatz 1. Die Entscheidung ist zu begründen.',
            ['Zuständig ist die Behörde nach § 71 Absatz 1.', 'Die Entscheidung ist zu begründen.'],
        ];

        yield 'numbers with decimal points stay together' => [
            'Das Gehalt muss mindestens 48.300 Euro betragen. Der Betrag wird jährlich angepasst.',
            ['Das Gehalt muss mindestens 48.300 Euro betragen.', 'Der Betrag wird jährlich angepasst.'],
        ];

        yield 'question and exclamation marks' => [
            'Wer ist Ausländer im Sinne dieses Gesetzes? Das regelt § 2.',
            ['Wer ist Ausländer im Sinne dieses Gesetzes?', 'Das regelt § 2.'],
        ];

        yield 'paragraph marker starts a sentence' => [
            'Dies gilt entsprechend. § 5 bleibt unberührt.',
            ['Dies gilt entsprechend.', '§ 5 bleibt unberührt.'],
        ];

        yield 'usw. and etc. do not end a sentence' => [
            'Erfasst sind Löhne, Gehälter usw. und sonstige Bezüge. Nicht erfasst sind Sachleistungen.',
            ['Erfasst sind Löhne, Gehälter usw. und sonstige Bezüge.', 'Nicht erfasst sind Sachleistungen.'],
        ];

        yield 'whitespace is normalised' => [
            "Erster Satz.\n   Zweiter    Satz.",
            ['Erster Satz.', 'Zweiter Satz.'],
        ];

        yield 'single sentence without a final stop' => [
            'Ohne abschließenden Punkt',
            ['Ohne abschließenden Punkt'],
        ];

        yield 'empty input' => ['   ', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('germanLegalProse')]
    public function testSplitting(string $paragraph, array $expected): void
    {
        self::assertSame($expected, new SentenceSplitter()->split($paragraph));
    }

    public function testAdditionalAbbreviationsFromConfigurationAreHonoured(): void
    {
        $text = 'Die Regelung gilt lt. Anlage. Weitere Einzelheiten folgen.';

        // Without the configured abbreviation the splitter breaks after "lt.".
        self::assertCount(3, new SentenceSplitter()->split($text));
        self::assertSame(
            ['Die Regelung gilt lt. Anlage.', 'Weitere Einzelheiten folgen.'],
            new SentenceSplitter(['lt.'])->split($text),
        );
    }

    public function testSplittingIsDeterministic(): void
    {
        $splitter = new SentenceSplitter();
        $text = 'Die Behörde entscheidet nach § 5 Abs. 1 Satz 2 Nr. 3. Sie hört den Betroffenen an.';

        self::assertSame($splitter->split($text), $splitter->split($text));
    }
}
