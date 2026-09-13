<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Card\PlaceholderParser;
use App\Content\Check\ForbiddenWording;
use App\Content\Check\LanguageDetector;
use App\Content\Check\QualityIssue;
use App\Content\Check\TranslationChecker;
use App\Content\Glossary\Glossary;
use App\Content\Glossary\GlossaryEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The quality gate a card passes before publication (SPEC.md § 7.4, checks 3–7).
 *
 * These checks are the reason a translation cannot change a figure and a card cannot start giving
 * legal advice — neither depends on a model being well behaved.
 */
#[CoversClass(TranslationChecker::class)]
#[CoversClass(ForbiddenWording::class)]
final class TranslationCheckerTest extends TestCase
{
    private TranslationChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new TranslationChecker(
            new PlaceholderParser(),
            new LanguageDetector(),
            new ForbiddenWording(\dirname(__DIR__, 3).'/config/content/forbidden-wording.yml'),
        );
    }

    public function testAFaithfulTranslationPasses(): void
    {
        $report = $this->checker->check($this->master(), $this->translation(), 'ru');

        self::assertTrue($report->passed(), $report->toMarkdown());
    }

    public function testADroppedPlaceholderIsCaught(): void
    {
        $translation = $this->translation();
        $translation['summary'] = 'Минимальная зарплата для Blue Card повышается с сентября текущего года.';

        $report = $this->checker->check($this->master(), $translation, 'ru');

        self::assertFalse($report->passed());
        self::assertContains('translation.placeholder_missing', $this->codes($report->errors()));
    }

    public function testAnInventedPlaceholderIsCaught(): void
    {
        $translation = $this->translation();
        $translation['details'] .= ' Также {{ amount:invented.new }}.';

        $report = $this->checker->check($this->master(), $translation, 'ru');

        self::assertContains('translation.placeholder_added', $this->codes($report->errors()));
    }

    /**
     * The failure the placeholder rule exists to prevent: a number written into the translation.
     */
    public function testAFigureTypedIntoTheTranslationIsCaught(): void
    {
        $translation = $this->translation();
        $translation['summary'] = 'Минимальная зарплата для Blue Card повышается до 48.300 евро в год.';

        $report = $this->checker->check($this->master(), $translation, 'ru');

        self::assertContains('translation.bare_value', $this->codes($report->errors()));
    }

    public function testASectionLeftEmptyIsCaught(): void
    {
        $translation = $this->translation();
        $translation['who'] = '';

        $report = $this->checker->check($this->master(), $translation, 'ru');

        self::assertContains('translation.section_missing', $this->codes($report->errors()));
    }

    public function testAnUnknownSectionKeyIsCaught(): void
    {
        $translation = $this->translation();
        $translation['extra'] = 'Что-то ещё.';

        $report = $this->checker->check($this->master(), $translation, 'ru');

        self::assertContains('translation.unknown_section', $this->codes($report->errors()));
    }

    public function testASummaryOverTheLimitIsCaught(): void
    {
        $master = $this->master();
        $translation = $this->translation();
        $translation['summary'] = str_repeat('очень длинное предложение, ', 20);
        $master['summary'] = 'Short.';

        $report = $this->checker->check($master, $translation, 'ru');

        self::assertContains('card.summary_too_long', $this->codes($report->errors()));
    }

    public function testACardReturnedInTheWrongLanguageIsCaught(): void
    {
        $translation = $this->translation();
        $translation['details'] = 'The law provides that the minimum salary for the Blue Card rises for everyone concerned.';
        $translation['summary'] = 'The minimum salary rises from {{ date:effective.0 }} to {{ amount:threshold.new }}.';

        $report = $this->checker->check($this->master(), $translation, 'ru');

        self::assertContains('translation.wrong_language', $this->codes($report->errors()));
    }

    public function testLegalAdviceIsCaught(): void
    {
        $translation = $this->translation();
        $translation['what_to_do'] = 'Вы должны подать заявление в ведомство до конца месяца.';

        $report = $this->checker->check($this->master(), $translation, 'ru');

        self::assertContains('card.forbidden_wording', $this->codes($report->errors()));
    }

    public function testAPoliticalJudgementIsCaught(): void
    {
        $master = $this->master();
        $master['details'] = 'This scandalous change was long overdue.';

        $report = $this->checker->checkMaster($master, 'en');

        self::assertContains('card.forbidden_wording', $this->codes($report->errors()));
    }

    /**
     * Russian, Ukrainian and Turkish decline these words, so a missing glossary rendering is worth
     * an editor's attention but must not block a good translation.
     */
    public function testAMissingGlossaryTermIsOnlyAWarning(): void
    {
        $master = $this->master();
        $master['details'] = 'The Aufenthaltstitel stays valid.';
        $translation = $this->translation();
        $translation['details'] = 'Разрешение остаётся действительным.';

        $glossary = new Glossary('ru', [
            'Aufenthaltstitel' => new GlossaryEntry('Aufenthaltstitel', 'Aufenthaltstitel (вид на жительство)'),
        ]);

        $report = $this->checker->check($master, $translation, 'ru', $glossary);

        self::assertTrue($report->passed());
        self::assertContains('translation.glossary_term_missing', $this->codes($report->issues()));
    }

    public function testAKeptGermanTermSatisfiesTheGlossary(): void
    {
        $master = $this->master();
        $master['details'] = 'The Aufenthaltstitel stays valid.';
        $translation = $this->translation();
        $translation['details'] = 'Aufenthaltstitel (вид на жительство) остаётся действительным.';

        $glossary = new Glossary('ru', [
            'Aufenthaltstitel' => new GlossaryEntry('Aufenthaltstitel', 'Aufenthaltstitel (вид на жительство)'),
        ]);

        $report = $this->checker->check($master, $translation, 'ru', $glossary);

        self::assertTrue($report->isEmpty(), $report->toMarkdown());
    }

    /**
     * The master is checked too — it is written by a model as well.
     */
    public function testTheMasterIsCheckedForBareValues(): void
    {
        $master = $this->master();
        $master['what_changes'] = 'The threshold rises to 48.300 Euro.';

        $report = $this->checker->checkMaster($master, 'en');

        self::assertContains('translation.bare_value', $this->codes($report->errors()));
    }

    /**
     * @return array<string, string>
     */
    private function master(): array
    {
        return [
            'summary' => 'From {{ date:effective.0 }} the Blue Card threshold rises to {{ amount:threshold.new }}.',
            'what_changes' => 'It was {{ amount:threshold.old }} before.',
            'who' => 'People who hold a Blue Card or want to apply for one.',
            'when' => '{{ date:effective.0 }}',
            'what_to_do' => 'The Ausländerbehörde and Migrationsberatung can explain what this means.',
            'details' => 'See {{ norm:bund/aufenthg_2004/p18g }}.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function translation(): array
    {
        return [
            'summary' => 'С {{ date:effective.0 }} порог зарплаты для Blue Card повышается до {{ amount:threshold.new }}.',
            'what_changes' => 'Раньше он составлял {{ amount:threshold.old }}.',
            'who' => 'Люди, у которых есть Blue Card или которые хотят её получить.',
            'when' => '{{ date:effective.0 }}',
            'what_to_do' => 'Подробности объясняют в Ausländerbehörde и Migrationsberatung.',
            'details' => 'Смотрите {{ norm:bund/aufenthg_2004/p18g }}.',
        ];
    }

    /**
     * @param list<QualityIssue> $issues
     *
     * @return list<string>
     */
    private function codes(array $issues): array
    {
        return array_values(array_unique(array_map(static fn (QualityIssue $i): string => $i->code, $issues)));
    }
}
