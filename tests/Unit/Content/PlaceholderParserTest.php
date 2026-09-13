<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Card\Placeholder;
use App\Content\Card\PlaceholderParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Card placeholders (SPEC.md § 5.4, § 24.13).
 *
 * Two things are being protected here: that a translation cannot change a number, and that card
 * content — written by a model, edited by strangers through pull requests — is never executed as a
 * template.
 */
#[CoversClass(PlaceholderParser::class)]
#[CoversClass(Placeholder::class)]
final class PlaceholderParserTest extends TestCase
{
    private PlaceholderParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PlaceholderParser();
    }

    public function testItFindsEveryPlaceholderType(): void
    {
        $text = 'Ab {{ date:effective.0 }} gilt {{ amount:blue_card_salary_threshold.new }} '
            .'nach {{ norm:bund/aufenthg_2004/p18g }} für den {{ term:Aufenthaltstitel }}.';

        $found = array_map(strval(...), $this->parser->parse($text));

        self::assertSame([
            '{{ date:effective.0 }}',
            '{{ amount:blue_card_salary_threshold.new }}',
            '{{ norm:bund/aufenthg_2004/p18g }}',
            '{{ term:Aufenthaltstitel }}',
        ], $found);
    }

    public function testSpacingInsideTheBracesDoesNotMatter(): void
    {
        $placeholders = $this->parser->parse('{{amount:x.new}} und {{   amount:x.new   }}');

        self::assertCount(2, $placeholders);
        self::assertSame($placeholders[0]->canonical(), $placeholders[1]->canonical());
    }

    public function testItSplitsKeyAndField(): void
    {
        $placeholder = $this->parser->parse('{{ amount:blue_card_salary_threshold.old }}')[0];

        self::assertSame('amount', $placeholder->type);
        self::assertSame('blue_card_salary_threshold', $placeholder->key());
        self::assertSame('old', $placeholder->field());
        self::assertTrue($placeholder->isKnownType());
    }

    public function testAPlaceholderWithoutAFieldHasNone(): void
    {
        $placeholder = $this->parser->parse('{{ date:promulgated }}')[0];

        self::assertSame('promulgated', $placeholder->key());
        self::assertNull($placeholder->field());
    }

    public function testAnUnknownTypeIsParsedButFlagged(): void
    {
        $placeholder = $this->parser->parse('{{ magic:something }}')[0];

        self::assertFalse($placeholder->isKnownType());
    }

    /**
     * Anything that is not a well-formed placeholder is literal text — most importantly, Twig
     * syntax. Card content must never reach a template engine (SSTI, SPEC.md § 24.13).
     */
    #[DataProvider('nonPlaceholders')]
    public function testItIgnoresEverythingThatIsNotAPlaceholder(string $text): void
    {
        self::assertSame([], $this->parser->parse($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPlaceholders(): iterable
    {
        yield 'twig variable' => ['{{ app.request }}'];
        yield 'twig filter chain' => ['{{ user.email|upper }}'];
        yield 'twig function call' => ['{{ include("secrets.txt") }}'];
        yield 'twig statement' => ['{% for x in y %}{% endfor %}'];
        yield 'single braces' => ['{ amount:x.new }'];
        yield 'plain prose' => ['Der Betrag steigt deutlich.'];
    }

    public function testSectionsAreCollectedWithoutDuplicates(): void
    {
        $sections = [
            'summary' => 'Ab {{ date:effective.0 }} gilt {{ amount:a.new }}.',
            'details' => 'Bereits {{ amount:a.new }} statt {{ amount:a.old }}.',
        ];

        self::assertSame([
            '{{ amount:a.new }}',
            '{{ amount:a.old }}',
            '{{ date:effective.0 }}',
        ], array_keys($this->parser->parseSections($sections)));
    }

    public function testReplacementUsesTheResolver(): void
    {
        $text = 'Neu: {{ amount:a.new }} statt {{ amount:a.old }}.';

        $result = $this->parser->replace(
            $text,
            static fn (Placeholder $p): string => 'new' === $p->field() ? '48 300 €' : '45 300 €',
        );

        self::assertSame('Neu: 48 300 € statt 45 300 €.', $result);
    }

    /**
     * A value the facts do not contain must stay visible as a placeholder rather than becoming a
     * gap in a sentence about money.
     */
    public function testAnUnresolvablePlaceholderIsLeftAsItIs(): void
    {
        $result = $this->parser->replace('Betrag: {{ amount:unknown.new }}.', static fn (): ?string => null);

        self::assertSame('Betrag: {{ amount:unknown.new }}.', $result);
    }

    #[DataProvider('bareValueExamples')]
    public function testItDetectsFiguresWrittenWithoutAPlaceholder(string $text, string $expected): void
    {
        self::assertContains($expected, $this->parser->bareValues($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bareValueExamples(): iterable
    {
        yield 'thousands with a dot' => ['Die Schwelle steigt auf 48.300 Euro.', '48.300'];
        yield 'thousands with a space' => ['Die Schwelle steigt auf 48 300 Euro.', '48 300'];
        yield 'amount with a currency' => ['Es kostet 500 €.', '500 €'];
        yield 'a percentage' => ['Der Satz beträgt 19 Prozent.', '19 Prozent'];
        yield 'a German date' => ['Ab 01.09.2026 gilt die Regel.', '01.09.2026'];
        yield 'an ISO date' => ['Ab 2026-09-01 gilt die Regel.', '2026-09-01'];
    }

    public function testTextThatOnlyUsesPlaceholdersIsClean(): void
    {
        $text = 'Ab {{ date:effective.0 }} sind es {{ amount:a.new }} statt {{ amount:a.old }}.';

        self::assertSame([], $this->parser->bareValues($text));
    }

    /**
     * A year, a paragraph number or a short count is prose, not a value that belongs in facts.yml —
     * flagging those would make the check useless through noise.
     */
    #[DataProvider('harmlessNumbers')]
    public function testProseNumbersAreNotFlagged(string $text): void
    {
        self::assertSame([], $this->parser->bareValues($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function harmlessNumbers(): iterable
    {
        yield 'a year' => ['Das Gesetz von 2026 wurde geändert.'];
        yield 'a paragraph reference' => ['Siehe § 18g Absatz 2.'];
        yield 'a small count' => ['Es gibt 3 Ausnahmen.'];
    }
}
