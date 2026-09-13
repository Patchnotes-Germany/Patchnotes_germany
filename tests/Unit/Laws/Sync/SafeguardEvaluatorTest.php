<?php

declare(strict_types=1);

namespace App\Tests\Unit\Laws\Sync;

use App\Core\Config\PatchnotesConfig;
use App\Laws\Sync\LawSyncResult;
use App\Laws\Sync\Safeguard\SafeguardEvaluator;
use App\Laws\Sync\Safeguard\SafeguardInput;
use App\Laws\Sync\SyncOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * These checks are the reason a broken parser cannot quietly delete German law texts
 * (SPEC.md § 4.6). Each one is tested on its own, because each one is a last line of defence.
 */
#[CoversClass(SafeguardEvaluator::class)]
final class SafeguardEvaluatorTest extends TestCase
{
    public function testACleanRunPasses(): void
    {
        $report = $this->evaluate(new SafeguardInput(
            laws: [$this->law('aufenthg_2004', bytesBefore: 10_000, bytesAfter: 10_400)],
            files: ['bund/aufenthg_2004/p1.md' => "# § 1\n\nDer Text ist unverändert gültig.\n"],
            lawsInJurisdiction: 6000,
        ));

        self::assertTrue($report->passed());
        self::assertStringContainsString('Alle Prüfungen bestanden', $report->toMarkdown());
    }

    public function testLosingMostOfALawBlocksTheMerge(): void
    {
        $report = $this->evaluate(new SafeguardInput(
            laws: [$this->law('estg', bytesBefore: 100_000, bytesAfter: 20_000)],
            files: [],
            lawsInJurisdiction: 6000,
        ));

        self::assertFalse($report->passed());
        self::assertSame(['law_text_deleted'], $report->codes());
        self::assertStringContainsString('80 %', $report->violations[0]->message);
    }

    public function testALawTheSourceDeclaresRepealedMayShrink(): void
    {
        $report = $this->evaluate(new SafeguardInput(
            laws: [$this->law('altes_gesetz', bytesBefore: 100_000, bytesAfter: 100)],
            files: [],
            lawsInJurisdiction: 6000,
            repealedAtSource: ['altes_gesetz'],
        ));

        self::assertTrue($report->passed());
    }

    public function testALawRepealedByThisRunMayShrink(): void
    {
        $report = $this->evaluate(new SafeguardInput(
            laws: [new LawSyncResult('altes_gesetz', SyncOutcome::Repealed, 'Altes Gesetz', bytesBefore: 50_000, bytesAfter: 0)],
            files: [],
            lawsInJurisdiction: 6000,
        ));

        self::assertTrue($report->passed());
    }

    public function testTooManyLawsChangingAtOnceLooksLikeASourceChange(): void
    {
        $laws = [];
        for ($index = 0; $index < 40; ++$index) {
            $laws[] = $this->law('law_'.$index, bytesBefore: 1000, bytesAfter: 1000);
        }

        $report = $this->evaluate(new SafeguardInput($laws, [], lawsInJurisdiction: 100));

        self::assertFalse($report->passed());
        self::assertContains('too_many_laws_changed', $report->codes());
    }

    public function testInvalidEncodingIsRejected(): void
    {
        $report = $this->evaluate(new SafeguardInput(
            laws: [],
            files: ['bund/x/p1.md' => "Gültig\xB1\xC0 kaputt"],
            lawsInJurisdiction: 10,
        ));

        self::assertContains('invalid_encoding', $report->codes());
    }

    public function testControlCharactersAreRejected(): void
    {
        $report = $this->evaluate(new SafeguardInput(
            laws: [],
            files: ['bund/x/p1.md' => "Text mit \x07 Steuerzeichen\n"],
            lawsInJurisdiction: 10,
        ));

        self::assertContains('control_characters', $report->codes());
    }

    public function testTablesAreAllowedButOtherHtmlIsNot(): void
    {
        $allowed = $this->evaluate(new SafeguardInput(
            laws: [],
            files: ['bund/x/anl1.md' => "<table>\n<tbody>\n<tr>\n<td colspan=\"2\">Wert<sup>1</sup></td>\n</tr>\n</tbody>\n</table>\n"],
            lawsInJurisdiction: 10,
        ));
        self::assertTrue($allowed->passed());

        $rejected = $this->evaluate(new SafeguardInput(
            laws: [],
            files: ['bund/x/p1.md' => '<script>alert(1)</script>'],
            lawsInJurisdiction: 10,
        ));
        self::assertContains('disallowed_html', $rejected->codes());
    }

    public function testANonDeterministicConversionBlocksTheMerge(): void
    {
        $report = $this->evaluate(new SafeguardInput([], [], lawsInJurisdiction: 10, deterministic: false));

        self::assertContains('not_deterministic', $report->codes());
        self::assertStringContainsString('nicht** automatisch gemergt', $report->toMarkdown());
    }

    public function testTheReportIsStorableOnTheChangeRequest(): void
    {
        $report = $this->evaluate(new SafeguardInput(
            laws: [$this->law('estg', bytesBefore: 1000, bytesAfter: 100)],
            files: [],
            lawsInJurisdiction: 6000,
        ));

        $array = $report->toArray();

        self::assertFalse($array['passed']);
        self::assertSame('law_text_deleted', $array['violations'][0]['code']);
        self::assertSame('estg', $array['violations'][0]['subject']);
    }

    private function evaluate(SafeguardInput $input): \App\Laws\Sync\Safeguard\SafeguardReport
    {
        $config = new PatchnotesConfig([
            'languages' => ['en'],
            'master_language' => 'en',
            'language_settings' => [],
            'timezone' => 'Europe/Berlin',
            'repositories' => [],
            'git' => [],
            'sources' => ['safeguards' => ['max_law_deletion_ratio' => 0.4, 'max_changed_laws_ratio' => 0.3]],
            'features' => [],
            'ai' => [],
            'review' => [],
            'notifications' => [],
            'retention' => [],
            'billing' => ['enabled' => false],
            'legal' => ['operator' => []],
        ]);

        return new SafeguardEvaluator($config)->evaluate($input);
    }

    private function law(string $slug, int $bytesBefore, int $bytesAfter): LawSyncResult
    {
        return new LawSyncResult(
            $slug,
            SyncOutcome::Updated,
            $slug,
            bytesBefore: $bytesBefore,
            bytesAfter: $bytesAfter,
        );
    }
}
