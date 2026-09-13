<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Dates\EffectiveDateCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * German entry-into-force rules turned into dates (SPEC.md § 24.5).
 *
 * These sentences decide when a reminder fires and what a card tells someone about their residence
 * permit or their income, so they are arithmetic with tests rather than a model's guess.
 */
#[CoversClass(EffectiveDateCalculator::class)]
final class EffectiveDateCalculatorTest extends TestCase
{
    private EffectiveDateCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new EffectiveDateCalculator();
    }

    #[DataProvider('rules')]
    public function testItCalculatesTheDate(string $rule, string $promulgated, string $expected): void
    {
        $date = $this->calculator->calculate($rule, new \DateTimeImmutable($promulgated));

        self::assertInstanceOf(\DateTimeImmutable::class, $date);
        self::assertSame($expected, $date->format('Y-m-d'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function rules(): iterable
    {
        yield 'the day after promulgation' => [
            'Dieses Gesetz tritt am Tag nach der Verkündung in Kraft.', '2026-06-10', '2026-06-11',
        ];

        yield 'the day after promulgation, at the end of a month' => [
            'tritt am Tag nach der Verkündung in Kraft', '2026-06-30', '2026-07-01',
        ];

        yield 'the day of promulgation' => [
            'tritt am Tage der Verkündung in Kraft', '2026-06-10', '2026-06-10',
        ];

        // The day within the month of promulgation is irrelevant: the count starts from the month.
        yield 'first day of the third following calendar month' => [
            'tritt am ersten Tag des dritten auf die Verkündung folgenden Kalendermonats in Kraft',
            '2026-06-10', '2026-09-01',
        ];

        yield 'first day of the third following month, published on the first' => [
            'am ersten Tag des dritten auf die Verkündung folgenden Kalendermonats',
            '2026-06-01', '2026-09-01',
        ];

        yield 'first day of the following calendar month' => [
            'tritt am ersten Tag des folgenden Kalendermonats in Kraft', '2026-12-19', '2027-01-01',
        ];

        yield 'first day of the twelfth following month' => [
            'am ersten Tag des zwölften auf die Verkündung folgenden Kalendermonats',
            '2026-02-15', '2027-02-01',
        ];

        yield 'three months after promulgation, spelled out' => [
            'tritt drei Monate nach der Verkündung in Kraft', '2026-06-10', '2026-09-10',
        ];

        yield 'six months after promulgation, as a numeral' => [
            'tritt 6 Monate nach der Verkündung in Kraft', '2026-01-31', '2026-07-31',
        ];

        yield 'days after promulgation' => [
            'tritt drei Tage nach der Verkündung in Kraft', '2026-06-10', '2026-06-13',
        ];

        yield 'an explicit German date' => [
            'tritt am 1. Januar 2027 in Kraft', '2026-06-10', '2027-01-01',
        ];

        yield 'an explicit numeric date' => [
            'tritt am 01.09.2026 in Kraft', '2026-06-10', '2026-09-01',
        ];

        yield 'an explicit date in March, spelled with an umlaut' => [
            'tritt am 15. März 2027 in Kraft', '2026-06-10', '2027-03-15',
        ];

        yield 'non-breaking spaces do not break the pattern' => [
            "tritt am\u{00A0}Tag nach der\u{202F}Verkündung in Kraft", '2026-06-10', '2026-06-11',
        ];
    }

    /**
     * An unknown rule must produce nothing at all. The card then keeps the literal sentence and
     * goes to review — far better than a plausible date nobody verified.
     */
    #[DataProvider('unsupportedRules')]
    public function testAnUnknownRuleYieldsNoDate(string $rule): void
    {
        self::assertNull($this->calculator->calculate($rule, new \DateTimeImmutable('2026-06-10')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedRules(): iterable
    {
        yield 'conditional on another act' => ['tritt gleichzeitig mit dem Gesetz zur Änderung des SGB II in Kraft'];
        yield 'conditional on a decision' => ['tritt an dem Tag in Kraft, an dem die Europäische Kommission zustimmt'];
        yield 'empty' => [''];
        yield 'prose without a rule' => ['Die Vorschrift gilt entsprechend.'];
    }

    public function testItConfirmsADateThatMatchesTheArithmetic(): void
    {
        self::assertTrue($this->calculator->agrees(
            'tritt am Tag nach der Verkündung in Kraft',
            new \DateTimeImmutable('2026-06-10'),
            new \DateTimeImmutable('2026-06-11'),
        ));
    }

    /**
     * This is the check that catches a model inventing an entry-into-force date (SPEC.md § 24.5).
     */
    public function testItRejectsADateThatDoesNotMatch(): void
    {
        self::assertFalse($this->calculator->agrees(
            'tritt am Tag nach der Verkündung in Kraft',
            new \DateTimeImmutable('2026-06-10'),
            new \DateTimeImmutable('2026-07-01'),
        ));
    }

    public function testARuleItCannotComputeIsNeverConfirmed(): void
    {
        self::assertFalse($this->calculator->agrees(
            'tritt gleichzeitig mit einem anderen Gesetz in Kraft',
            new \DateTimeImmutable('2026-06-10'),
            new \DateTimeImmutable('2026-06-11'),
        ));
    }

    public function testTheTimeOfDayOfThePromulgationIsIgnored(): void
    {
        $date = $this->calculator->calculate(
            'tritt am Tag nach der Verkündung in Kraft',
            new \DateTimeImmutable('2026-06-10 23:45:00'),
        );

        self::assertInstanceOf(\DateTimeImmutable::class, $date);
        self::assertSame('2026-06-11 00:00:00', $date->format('Y-m-d H:i:s'));
    }
}
