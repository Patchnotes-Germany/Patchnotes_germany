<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Card\CardFile;
use App\Content\Card\ParsedCard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The card file format (SPEC.md § 5.4, § 24.13).
 *
 * The heading is translated, the anchor is not — that is what lets four languages of the same card
 * be compared section by section, and what lets an editor improve a heading without breaking
 * anything.
 */
#[CoversClass(CardFile::class)]
#[CoversClass(ParsedCard::class)]
final class CardFileTest extends TestCase
{
    private CardFile $cards;

    protected function setUp(): void
    {
        $this->cards = new CardFile();
    }

    public function testItReadsFrontMatterAndSectionsByTheirAnchor(): void
    {
        $card = $this->cards->parse($this->russianCard());

        self::assertSame('ru', $card->lang());
        self::assertSame('3f9a1c', $card->masterHash());
        self::assertSame('machine', $card->frontMatter['translation'] ?? null);

        self::assertSame(
            'Для Blue Card повышается минимальная зарплата — до {{ amount:threshold.new }} в год.',
            $card->summary(),
        );
        self::assertSame('Раньше требовалось {{ amount:threshold.old }}.', $card->section('what_changes'));
        self::assertSame('Коротко', $card->headings['summary'] ?? null);
        self::assertTrue($card->isUsable());
    }

    /**
     * The keys are fixed; the headings are not. A Turkish card with Turkish headings must parse
     * into exactly the same section keys.
     */
    public function testHeadingsMayBeInAnyLanguage(): void
    {
        $card = $this->cards->parse(<<<'MARKDOWN'
            ---
            lang: tr
            ---

            ## Kısaca {#summary}

            Mavi Kart için asgari maaş artıyor.

            ## Ne değişiyor {#what_changes}

            Önceki tutar daha düşüktü.
            MARKDOWN);

        self::assertSame(['summary', 'what_changes'], array_keys($card->sections));
        self::assertSame('Kısaca', $card->headings['summary'] ?? null);
    }

    public function testAnUnknownAnchorIsReportedAndMakesTheCardUnusable(): void
    {
        $card = $this->cards->parse(<<<'MARKDOWN'
            ---
            lang: en
            ---

            ## Summary {#summary}

            Something changed.

            ## Invented {#extra_thoughts}

            Whatever the model felt like adding.
            MARKDOWN);

        self::assertSame(['extra_thoughts'], $card->unknownKeys);
        self::assertFalse($card->isUsable());
    }

    /**
     * Text outside a section was never checked by anything, so it must not reach the website.
     */
    public function testTextBeforeTheFirstSectionIsDropped(): void
    {
        $card = $this->cards->parse(<<<'MARKDOWN'
            ---
            lang: en
            ---

            Here is my answer, I hope it helps!

            ## Summary {#summary}

            Something changed.
            MARKDOWN);

        self::assertSame(['summary' => 'Something changed.'], $card->sections);
    }

    public function testACardWithoutASummaryIsNotUsable(): void
    {
        $card = $this->cards->parse(<<<'MARKDOWN'
            ---
            lang: en
            ---

            ## Summary {#summary}

            ## Details {#details}

            Only the details were written.
            MARKDOWN);

        self::assertFalse($card->isUsable());
        self::assertContains('summary', $card->emptySections());
    }

    public function testAFileWithoutFrontMatterStillParses(): void
    {
        $card = $this->cards->parse("## Summary {#summary}\n\nSomething changed.\n");

        self::assertSame([], $card->frontMatter);
        self::assertSame('Something changed.', $card->summary());
    }

    public function testItWritesEverySectionInTheCanonicalOrder(): void
    {
        $markdown = $this->cards->render(
            ['lang' => 'en', 'translation' => 'machine'],
            ['summary' => 'Short.', 'details' => 'Long.'],
            ['summary' => 'In short', 'details' => 'Details'],
        );

        self::assertStringStartsWith("---\n", $markdown);
        self::assertStringContainsString("## In short {#summary}\n\nShort.\n", $markdown);
        self::assertStringContainsString("## Details {#details}\n\nLong.\n", $markdown);

        // Fixed order, and the sections that were not written are still offered to a translator.
        $anchors = [];
        preg_match_all('/\{#([a-z_]+)\}/', $markdown, $anchors);
        self::assertSame(['summary', 'what_changes', 'who', 'when', 'what_to_do', 'details'], $anchors[1]);
    }

    public function testWritingAndReadingAreInverse(): void
    {
        $sections = [
            'summary' => 'Ab {{ date:effective.0 }} gilt mehr.',
            'what_changes' => 'Der Betrag steigt auf {{ amount:threshold.new }}.',
            'who' => 'Menschen mit Blue Card.',
            'when' => '{{ date:effective.0 }}',
            'what_to_do' => 'Bei der Ausländerbehörde nachfragen.',
            'details' => 'Siehe {{ norm:bund/aufenthg_2004/p18g }}.',
        ];

        $parsed = $this->cards->parse($this->cards->render(['lang' => 'de'], $sections));

        self::assertSame($sections, $parsed->sections);
    }

    public function testRenderingIsDeterministic(): void
    {
        $first = $this->cards->render(['lang' => 'en'], ['summary' => 'Short.']);
        $second = $this->cards->render(['lang' => 'en'], ['summary' => 'Short.']);

        self::assertSame($first, $second);
    }

    /**
     * The hash decides when four translations are re-queued, so it must react to the text and not
     * to the order in which placeholders were collected.
     */
    public function testTheMasterHashCoversTheTextAndThePlaceholders(): void
    {
        $sections = ['summary' => 'Ab {{ date:effective.0 }} gilt {{ amount:a.new }}.'];

        $hash = $this->cards->masterHash($sections, ['{{ amount:a.new }}', '{{ date:effective.0 }}']);

        self::assertSame($hash, $this->cards->masterHash($sections, ['{{ date:effective.0 }}', '{{ amount:a.new }}']));
        self::assertNotSame($hash, $this->cards->masterHash(['summary' => 'Etwas anderes.'], ['{{ amount:a.new }}']));
    }

    /**
     * A changed heading must not invalidate the translations — only the content may.
     */
    public function testTheMasterHashIgnoresHeadings(): void
    {
        $sections = ['summary' => 'Short.'];

        $fromFirst = $this->cards->parse($this->cards->render(['lang' => 'en'], $sections, ['summary' => 'In short']));
        $fromSecond = $this->cards->parse($this->cards->render(['lang' => 'en'], $sections, ['summary' => 'Overview']));

        self::assertSame(
            $this->cards->masterHash($fromFirst->sections, []),
            $this->cards->masterHash($fromSecond->sections, []),
        );
    }

    private function russianCard(): string
    {
        return <<<'MARKDOWN'
            ---
            lang: ru
            master_hash: 3f9a1c
            translation: machine
            translated_by: 'local:qwen'
            ---

            ## Коротко {#summary}

            Для Blue Card повышается минимальная зарплата — до {{ amount:threshold.new }} в год.

            ## Что меняется {#what_changes}

            Раньше требовалось {{ amount:threshold.old }}.
            MARKDOWN;
    }
}
