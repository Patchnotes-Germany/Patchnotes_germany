<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Check\QualityIssue;
use App\Content\Dates\EffectiveDateCalculator;
use App\Content\Facts\FactVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The check that decides whether a card may be published (SPEC.md § 7.4, check 1).
 *
 * The acceptance criterion of M5 is exactly this: a corrupted amount in an AI answer is caught here
 * and the change goes to needs_review instead of telling someone the wrong salary threshold.
 */
#[CoversClass(FactVerifier::class)]
final class FactVerifierTest extends TestCase
{
    private const string GERMAN_TEXT = <<<'TEXT'
        Artikel 1 Änderung des Aufenthaltsgesetzes

        In § 18g Absatz 1 Satz 1 werden die Wörter "45 300 Euro" durch die Wörter
        "48 300 Euro" ersetzt.

        Artikel 5 Inkrafttreten

        Dieses Gesetz tritt am ersten Tag des dritten auf die Verkündung folgenden
        Kalendermonats in Kraft.
        TEXT;

    private FactVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new FactVerifier(new EffectiveDateCalculator());
    }

    public function testFactsBackedByTheSourcePass(): void
    {
        $report = $this->verifier->verify($this->facts(), self::GERMAN_TEXT);

        self::assertTrue($report->passed(), $report->toMarkdown());
        self::assertTrue($report->isEmpty(), $report->toMarkdown());
    }

    /**
     * The M5 acceptance case: the model changed the figure.
     */
    public function testACorruptedAmountIsCaught(): void
    {
        $facts = $this->facts();
        $facts['amounts'][0]['new'] = 84300;

        $report = $this->verifier->verify($facts, self::GERMAN_TEXT);

        self::assertFalse($report->passed());
        self::assertSame(['fact.value_not_in_quote'], $this->codes($report->errors()));
    }

    public function testAnInventedQuoteIsCaught(): void
    {
        $facts = $this->facts();
        $facts['amounts'][0]['source_quote'] = 'die Wörter "52 000 Euro" werden eingefügt';

        $report = $this->verifier->verify($facts, self::GERMAN_TEXT);

        self::assertFalse($report->passed());
        self::assertSame(['fact.quote_not_found'], $this->codes($report->errors()));
    }

    public function testAnAmountWithoutAQuoteCannotBeVerified(): void
    {
        $facts = $this->facts();
        unset($facts['amounts'][0]['source_quote']);

        $report = $this->verifier->verify($facts, self::GERMAN_TEXT);

        self::assertSame(['fact.quote_missing'], $this->codes($report->errors()));
    }

    /**
     * German writes the same number in several ways, and the gazette uses non-breaking spaces.
     * Treating those as differences would fail every real change.
     */
    #[DataProvider('numberSpellings')]
    public function testNumberFormattingIsNotAFactualDifference(string $quote, string $text): void
    {
        // Only the amount is under test here; a rule text would be checked against this short
        // excerpt and fail for an unrelated reason.
        $facts = ['amounts' => [[
            'key' => 'blue_card_salary_threshold',
            'new' => 48300,
            'unit' => 'EUR',
            'source_quote' => $quote,
        ]]];

        $report = $this->verifier->verify($facts, $text);

        self::assertTrue($report->isEmpty(), $report->toMarkdown());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function numberSpellings(): iterable
    {
        yield 'thin space in the quote, dot in the text' => ['"48 300 Euro"', 'die Wörter "48.300 Euro" ersetzt'];
        yield 'dot in the quote, plain digits in the text' => ['"48.300 Euro"', 'die Wörter "48300 Euro" ersetzt'];
        yield 'non-breaking space in the text' => ['"48 300 Euro"', "die Wörter \"48\u{00A0}300 Euro\" ersetzt"];
        yield 'different capitalisation' => ['"48 300 euro"', 'Die Wörter "48 300 Euro" ersetzt'];
    }

    /**
     * The old value often stands elsewhere in the act, so its absence from the quote is worth
     * showing an editor but must not block a correct change.
     */
    public function testAMissingOldValueIsOnlyAWarning(): void
    {
        $facts = $this->facts();
        $facts['amounts'][0]['source_quote'] = 'die Wörter "48 300 Euro" ersetzt';

        $report = $this->verifier->verify($facts, self::GERMAN_TEXT);

        self::assertTrue($report->passed());
        self::assertSame(['fact.value_not_in_quote'], $this->codes($report->issues()));
    }

    public function testADerivedDateMustFollowFromTheRule(): void
    {
        $facts = $this->facts();
        $facts['dates']['effective'][0]['date'] = '2026-08-01';

        $report = $this->verifier->verify($facts, self::GERMAN_TEXT);

        self::assertFalse($report->passed());
        self::assertSame(['fact.derived_mismatch'], $this->codes($report->errors()));
        self::assertStringContainsString('2026-09-01', $report->toMarkdown());
    }

    public function testARuleThatIsNotInTheActIsCaught(): void
    {
        $facts = $this->facts();
        $facts['dates']['effective'][0]['rule_text'] = 'tritt am Tag nach der Verkündung in Kraft';
        $facts['dates']['effective'][0]['date'] = '2026-06-11';

        $report = $this->verifier->verify($facts, self::GERMAN_TEXT);

        self::assertContains('fact.rule_not_found', $this->codes($report->errors()));
    }

    public function testADerivedDateNeedsTheDateOfPromulgation(): void
    {
        $facts = $this->facts();
        unset($facts['dates']['promulgated']);

        $report = $this->verifier->verify($facts, self::GERMAN_TEXT);

        self::assertSame(['fact.derived_incomplete'], $this->codes($report->errors()));
    }

    /**
     * A fixed date may legitimately come from the gazette metadata rather than the text, so it is
     * reported but does not stop publication.
     */
    public function testAFixedDateThatIsNotInTheTextIsOnlyAWarning(): void
    {
        $facts = $this->facts();
        $facts['dates']['effective'] = [['date' => '2027-01-01', 'scope' => 'Artikel 1']];

        $report = $this->verifier->verify($facts, self::GERMAN_TEXT);

        self::assertTrue($report->passed());
        self::assertSame(['fact.date_not_found'], $this->codes($report->issues()));
    }

    public function testAFixedDateWrittenInTheActIsAccepted(): void
    {
        $facts = $this->facts();
        $facts['dates']['effective'] = [['date' => '2027-01-01', 'scope' => 'Artikel 1']];

        $report = $this->verifier->verify($facts, self::GERMAN_TEXT."\nDieses Gesetz tritt am 1. Januar 2027 in Kraft.");

        self::assertTrue($report->isEmpty(), $report->toMarkdown());
    }

    public function testFactsWithoutAmountsOrDatesAreAccepted(): void
    {
        $report = $this->verifier->verify(['id' => '2026-bund-bgbl-i-123'], self::GERMAN_TEXT);

        self::assertTrue($report->passed());
    }

    /**
     * @return array<string, mixed>
     */
    private function facts(): array
    {
        return [
            'id' => '2026-bund-bgbl-i-123',
            'amounts' => [
                [
                    'key' => 'blue_card_salary_threshold',
                    'old' => 45300,
                    'new' => 48300,
                    'unit' => 'EUR',
                    'per' => 'year',
                    'source_quote' => 'die Wörter "45 300 Euro" durch die Wörter "48 300 Euro" ersetzt',
                ],
            ],
            'dates' => [
                'promulgated' => '2026-06-10',
                'effective' => [
                    [
                        'date' => '2026-09-01',
                        'rule_text' => 'tritt am ersten Tag des dritten auf die Verkündung folgenden Kalendermonats in Kraft',
                        'derived' => true,
                        'scope' => 'Artikel 1',
                    ],
                ],
            ],
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
