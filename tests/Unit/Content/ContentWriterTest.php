<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\ContentWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The layout of a change in the `content` repository (SPEC.md § 5.1).
 *
 * The directory name is the change id, which is what lets the repository be read back into the
 * database without a mapping table (§ 24.11) — and the bytes must be stable, or every pipeline
 * rerun would open a pull request for a change that did not happen.
 */
#[CoversClass(ContentWriter::class)]
final class ContentWriterTest extends TestCase
{
    private ContentWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new ContentWriter();
    }

    public function testTheDirectoryIsTheYearAndTheChangeId(): void
    {
        self::assertSame(
            'changes/2026/2026-bund-bgbl-i-123',
            $this->writer->directoryFor('2026-bund-bgbl-i-123'),
        );
    }

    public function testTheYearComesFromTheChangeIdItself(): void
    {
        $files = $this->writer->renderFiles('2027-bund-bgbl-i-5', ['dates' => ['promulgated' => '2026-12-30']], []);

        self::assertSame(['changes/2027/2027-bund-bgbl-i-5/facts.yml'], array_keys($files));
    }

    /**
     * The per-law fallback id of § 24.1 starts with a date, so it still yields a year.
     */
    public function testAFallbackIdStillLandsInTheRightYear(): void
    {
        self::assertSame(
            'changes/2026/2026-09-13-bund-aufenthg_2004-1a2b3c4d',
            $this->writer->directoryFor('2026-09-13-bund-aufenthg_2004-1a2b3c4d'),
        );
    }

    public function testAnIdWithoutAYearFallsBackToThePromulgationDate(): void
    {
        $files = $this->writer->renderFiles('sondermeldung', ['dates' => ['promulgated' => '2024-03-01']], []);

        self::assertSame(['changes/2024/sondermeldung/facts.yml'], array_keys($files));
    }

    public function testItWritesFactsAndOneFilePerLanguage(): void
    {
        $files = $this->writer->renderFiles('2026-bund-bgbl-i-123', $this->facts(), $this->cards());

        self::assertSame([
            'changes/2026/2026-bund-bgbl-i-123/facts.yml',
            'changes/2026/2026-bund-bgbl-i-123/en.md',
            'changes/2026/2026-bund-bgbl-i-123/ru.md',
        ], array_keys($files));
    }

    public function testTheLanguageIsWrittenIntoTheFrontMatter(): void
    {
        $files = $this->writer->renderFiles('2026-bund-bgbl-i-123', $this->facts(), $this->cards());
        $russian = $files['changes/2026/2026-bund-bgbl-i-123/ru.md'];

        self::assertStringContainsString('lang: ru', $russian);
        self::assertStringContainsString('master_hash: abc123', $russian);
        self::assertStringContainsString('## Коротко {#summary}', $russian);
        self::assertStringContainsString('{{ amount:threshold.new }}', $russian);
    }

    /**
     * Translations finish in whatever order the models answer; the commit must not depend on it.
     */
    public function testTheFileOrderDoesNotDependOnTheOrderTheTranslationsArrived(): void
    {
        $cards = $this->cards();
        $reversed = array_reverse($cards, true);

        self::assertSame(
            $this->writer->renderFiles('2026-bund-bgbl-i-123', $this->facts(), $cards),
            $this->writer->renderFiles('2026-bund-bgbl-i-123', $this->facts(), $reversed),
        );
    }

    public function testRenderingIsDeterministic(): void
    {
        $first = $this->writer->renderFiles('2026-bund-bgbl-i-123', $this->facts(), $this->cards());
        $second = $this->writer->renderFiles('2026-bund-bgbl-i-123', $this->facts(), $this->cards());

        self::assertSame($first, $second);
    }

    public function testNoFileCarriesATimestamp(): void
    {
        $files = $this->writer->renderFiles('2026-bund-bgbl-i-123', $this->facts(), $this->cards());

        foreach ($files as $path => $contents) {
            self::assertDoesNotMatchRegularExpression(
                '/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/',
                $contents,
                $path.' contains a timestamp',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function facts(): array
    {
        return [
            'id' => '2026-bund-bgbl-i-123',
            'kind' => 'amendment',
            'jurisdiction' => 'bund',
            'stage' => 'promulgated',
            'dates' => ['promulgated' => '2026-06-10'],
            'topics' => ['migration'],
            'impact' => 3,
        ];
    }

    /**
     * @return array<string, array{sections: array<string, string>, front_matter?: array<string, mixed>, headings?: array<string, string>}>
     */
    private function cards(): array
    {
        return [
            'ru' => [
                'sections' => ['summary' => 'Порог повышается до {{ amount:threshold.new }}.'],
                'front_matter' => ['master_hash' => 'abc123', 'translation' => 'machine'],
                'headings' => ['summary' => 'Коротко'],
            ],
            'en' => [
                'sections' => ['summary' => 'The threshold rises to {{ amount:threshold.new }}.'],
                'headings' => ['summary' => 'In short'],
            ],
        ];
    }
}
