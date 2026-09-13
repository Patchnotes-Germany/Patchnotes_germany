<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Facts\FactsFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Reading and writing facts.yml (SPEC.md § 5.3).
 *
 * Determinism is the property under test: the pipeline reruns after every merged law change, and if
 * the same facts produced a different file each time, every rerun would open a pull request and
 * notify people about a change that did not happen.
 */
#[CoversClass(FactsFile::class)]
final class FactsFileTest extends TestCase
{
    private FactsFile $facts;

    protected function setUp(): void
    {
        $this->facts = new FactsFile();
    }

    public function testKeysAreWrittenInTheOrderOfTheSpecification(): void
    {
        $yaml = $this->facts->render([
            'impact' => 3,
            'kind' => 'amendment',
            'id' => '2026-bund-bgbl-i-123',
            'topics' => ['migration'],
            'jurisdiction' => 'bund',
        ]);

        self::assertSame(
            ['id', 'kind', 'jurisdiction', 'topics', 'impact'],
            $this->topLevelKeys($yaml),
        );
    }

    public function testTheSameFactsAlwaysProduceTheSameFile(): void
    {
        $facts = $this->example();

        self::assertSame($this->facts->render($facts), $this->facts->render($facts));
    }

    /**
     * The order amounts arrive in depends on the model; the file must not.
     */
    public function testAmountsAreSortedByTheirKey(): void
    {
        $facts = ['amounts' => [
            ['key' => 'second_threshold', 'new' => 2],
            ['key' => 'first_threshold', 'new' => 1],
        ]];

        $ordered = $this->facts->order($facts);

        self::assertSame(['first_threshold', 'second_threshold'], array_column($ordered['amounts'], 'key'));
    }

    public function testReorderingAmountsDoesNotChangeTheFile(): void
    {
        $one = ['amounts' => [['key' => 'a', 'new' => 1], ['key' => 'b', 'new' => 2]]];
        $other = ['amounts' => [['key' => 'b', 'new' => 2], ['key' => 'a', 'new' => 1]]];

        self::assertSame($this->facts->render($one), $this->facts->render($other));
    }

    /**
     * A field a person added by hand must survive the next machine write.
     */
    public function testUnknownKeysAreKept(): void
    {
        $yaml = $this->facts->render(['id' => 'x', 'editorial_note' => 'checked by hand']);

        self::assertStringContainsString('editorial_note', $yaml);
        self::assertSame(['id', 'editorial_note'], $this->topLevelKeys($yaml));
    }

    public function testWritingAndReadingAreInverse(): void
    {
        $facts = $this->example();

        self::assertSame($this->facts->order($facts), $this->facts->parse($this->facts->render($facts)));
    }

    public function testAnEmptyFileParsesToNoFacts(): void
    {
        self::assertSame([], $this->facts->parse(''));
    }

    public function testBrokenYamlIsRejectedWithAClearMessage(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/facts\.yml is not valid YAML/');

        $this->facts->parse("id: x\n  broken: [unclosed\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function example(): array
    {
        return [
            'id' => '2026-bund-bgbl-i-123',
            'kind' => 'amendment',
            'jurisdiction' => 'bund',
            'lands' => [],
            'title_de' => 'Gesetz zur Weiterentwicklung der Fachkräfteeinwanderung',
            'stage' => 'promulgated',
            'dates' => [
                'promulgated' => '2026-06-10',
                'effective' => [['date' => '2026-09-01', 'scope' => 'Artikel 1']],
            ],
            'amounts' => [[
                'key' => 'blue_card_salary_threshold',
                'old' => 45300,
                'new' => 48300,
                'unit' => 'EUR',
                'source_quote' => 'die Wörter "48 300 Euro"',
            ]],
            'audience' => ['general' => false, 'any_of' => ['blue_card']],
            'topics' => ['migration', 'labor'],
            'impact' => 3,
        ];
    }

    /**
     * @return list<string>
     */
    private function topLevelKeys(string $yaml): array
    {
        preg_match_all('/^([a-z_]+):/m', $yaml, $matches);

        return $matches[1];
    }
}
