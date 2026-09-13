<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Glossary\Glossary;
use App\Content\Glossary\GlossaryEntry;
use App\Content\Glossary\GlossaryFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The per-language glossary (SPEC.md § 5.5).
 *
 * Its purpose is that a reader can still recognise the German word on the letter in their hand, so
 * the German term is what the checks look for — not the translation around it.
 */
#[CoversClass(GlossaryFile::class)]
#[CoversClass(Glossary::class)]
#[CoversClass(GlossaryEntry::class)]
final class GlossaryFileTest extends TestCase
{
    private GlossaryFile $file;

    protected function setUp(): void
    {
        $this->file = new GlossaryFile();
    }

    public function testItReadsTermsWithTheirRenderingAndExplanation(): void
    {
        $glossary = $this->file->parse('ru', $this->yaml());

        $entry = $glossary->get('Aufenthaltstitel');

        self::assertInstanceOf(GlossaryEntry::class, $entry);
        self::assertSame('Aufenthaltstitel (вид на жительство)', $entry->render);
        self::assertStringContainsString('документ', (string) $entry->explanation);
        self::assertTrue($entry->keepGerman);
        self::assertSame('ru', $glossary->language);
    }

    public function testAPlainStringIsAcceptedAsAShorthand(): void
    {
        $glossary = $this->file->parse('en', "Bürgergeld: Bürgergeld (basic income support)\n");

        self::assertSame('Bürgergeld (basic income support)', $glossary->get('Bürgergeld')?->render);
    }

    public function testAnEntryWithoutARenderingIsSkipped(): void
    {
        $glossary = $this->file->parse('ru', "Broken:\n  explanation: no rendering here\n");

        self::assertTrue($glossary->isEmpty());
    }

    public function testATermThatMayBeTranslatedIsMarkedAsSuch(): void
    {
        $glossary = $this->file->parse('ru', $this->yaml());

        $translatable = $glossary->get('Antrag');
        $kept = $glossary->get('Aufenthaltstitel');

        self::assertInstanceOf(GlossaryEntry::class, $translatable);
        self::assertInstanceOf(GlossaryEntry::class, $kept);

        self::assertFalse($translatable->keepGerman);
        self::assertSame('заявление', $translatable->expectedInTranslation());
        self::assertSame('Aufenthaltstitel', $kept->expectedInTranslation());
    }

    /**
     * A translation prompt gets the terms the text actually uses, not the whole glossary.
     */
    public function testOnlyTheTermsUsedInATextAreCollected(): void
    {
        $glossary = $this->file->parse('ru', $this->yaml());

        $mentioned = $glossary->mentionedIn('Der Aufenthaltstitel bleibt gültig.');

        self::assertCount(1, $mentioned);
        self::assertSame('Aufenthaltstitel', $mentioned[0]->germanTerm);
    }

    public function testEntriesCanBeHandedToAPrompt(): void
    {
        $glossary = $this->file->parse('ru', $this->yaml());

        $forPrompt = $glossary->forPrompt($glossary->mentionedIn('Aufenthaltstitel'));

        self::assertSame([[
            'german' => 'Aufenthaltstitel',
            'render' => 'Aufenthaltstitel (вид на жительство)',
            'explanation' => 'Общее название документа, дающего право находиться в Германии.',
        ]], $forPrompt);
    }

    public function testWritingSortsTheTermsSoDiffsStayReadable(): void
    {
        $yaml = $this->file->render([
            new GlossaryEntry('Bürgergeld', 'Bürgergeld (пособие)'),
            new GlossaryEntry('Antrag', 'заявление', null, false),
        ]);

        self::assertSame(['Antrag', 'Bürgergeld'], $this->topLevelKeys($yaml));
    }

    public function testWritingAndReadingAreInverse(): void
    {
        $original = $this->file->parse('ru', $this->yaml());

        $reparsed = $this->file->parse('ru', $this->file->render($original->entries()));

        self::assertEquals($original->entries(), $reparsed->entries());
    }

    /**
     * The file created at bootstrap contains only comments; that has to be a valid empty glossary
     * rather than an error, or the first translation run would fail on a fresh installation.
     */
    public function testTheBootstrapPlaceholderIsAnEmptyGlossary(): void
    {
        $glossary = $this->file->parse('ru', "# Glossary for \"ru\"\n#\n# Aufenthaltstitel:\n#   render: \"…\"\n");

        self::assertTrue($glossary->isEmpty());
        self::assertSame(0, $glossary->count());
    }

    public function testThePathFollowsTheRepositoryLayout(): void
    {
        self::assertSame('glossary/uk.yml', $this->file->path('uk'));
    }

    private function yaml(): string
    {
        return <<<'YAML'
            Aufenthaltstitel:
              render: "Aufenthaltstitel (вид на жительство)"
              explanation: "Общее название документа, дающего право находиться в Германии."
              keep_german: true
            Antrag:
              render: "заявление"
              keep_german: false
            YAML;
    }

    /**
     * @return list<string>
     */
    private function topLevelKeys(string $yaml): array
    {
        preg_match_all('/^(\S+):/m', $yaml, $matches);

        return $matches[1];
    }
}
