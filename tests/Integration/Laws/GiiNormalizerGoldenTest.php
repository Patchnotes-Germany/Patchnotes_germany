<?php

declare(strict_types=1);

namespace App\Tests\Integration\Laws;

use App\Laws\LawWriter;
use App\Laws\Normalizer\CalsTableRenderer;
use App\Laws\Normalizer\GiiXmlNormalizer;
use App\Laws\Normalizer\InlineTextRenderer;
use App\Laws\Normalizer\MarkdownEscaper;
use App\Laws\Normalizer\NormKeyFactory;
use App\Laws\Normalizer\SentenceSplitter;
use App\Laws\Value\NormalizedLaw;
use App\Source\Value\DocumentRef;
use App\Source\Value\RawDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests of the deterministic conversion (SPEC.md § 6.2 A, § 20 M3).
 *
 * The fixtures are real excerpts of federal laws — lists, nested lists, CALS tables, footnotes,
 * repealed norms, Anlagen, structure headings — built by `tests/Fixtures/gii/build-fixtures.py`.
 * Every byte of the expected output is under review: if a change to the normalizer moves a single
 * line, the diff shows up here instead of in thousands of law files.
 *
 * Regenerate after an intentional change:
 *   docker compose exec -T -e UPDATE_GOLDEN=1 php vendor/bin/phpunit tests/Integration/Laws
 */
#[CoversClass(GiiXmlNormalizer::class)]
#[CoversClass(LawWriter::class)]
#[CoversClass(InlineTextRenderer::class)]
#[CoversClass(CalsTableRenderer::class)]
final class GiiNormalizerGoldenTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../Fixtures/gii';

    /**
     * @return iterable<string, array{string}>
     */
    public static function laws(): iterable
    {
        foreach (glob(self::FIXTURES.'/*.xml') ?: [] as $file) {
            $slug = basename($file, '.xml');

            yield $slug => [$slug];
        }
    }

    #[DataProvider('laws')]
    public function testConversionMatchesTheGoldenFile(string $slug): void
    {
        $actual = $this->render($this->normalize($slug));
        $expectedFile = self::FIXTURES.'/expected/'.$slug.'.golden.md';

        if (false !== getenv('UPDATE_GOLDEN')) {
            if (!is_dir(\dirname($expectedFile))) {
                mkdir(\dirname($expectedFile), 0o775, true);
            }
            file_put_contents($expectedFile, $actual);
        }

        self::assertFileExists($expectedFile, 'Run the suite with UPDATE_GOLDEN=1 to create it.');
        self::assertSame(file_get_contents($expectedFile), $actual);
    }

    #[DataProvider('laws')]
    public function testConversionIsDeterministic(string $slug): void
    {
        // Determinism is a merge requirement (SPEC.md § 4.6): the same XML must always produce the
        // same Markdown, otherwise every synchronisation would create diffs out of thin air.
        self::assertSame($this->render($this->normalize($slug)), $this->render($this->normalize($slug)));
    }

    #[DataProvider('laws')]
    public function testEveryNormHasAStableKeyAndNonEmptyBody(string $slug): void
    {
        $law = $this->normalize($slug);

        self::assertNotSame([], $law->norms, $slug.' produced no norms');
        self::assertSame(
            array_unique($law->normKeys()),
            $law->normKeys(),
            'norm keys must be unique inside a law',
        );

        foreach ($law->norms as $norm) {
            self::assertMatchesRegularExpression('/^[a-z0-9][a-z0-9._-]*$/', $norm->key, 'key is a safe file name');
            self::assertNotSame('', trim($norm->markdown), $norm->key.' has an empty body');
            self::assertStringEndsNotWith("\n\n", $norm->markdown);
        }
    }

    #[DataProvider('laws')]
    public function testNoTimestampsLeakIntoTheOutput(string $slug): void
    {
        // Timestamps would produce a diff on every run even when nothing changed (SPEC.md § 4.2).
        $rendered = $this->render($this->normalize($slug));

        self::assertDoesNotMatchRegularExpression('/\b20\d{2}-\d{2}-\d{2}T\d{2}:\d{2}/', $rendered);
        self::assertStringNotContainsString(date('Y-m-d'), str_replace($this->knownDates($slug), '', $rendered));
    }

    private function normalize(string $slug): NormalizedLaw
    {
        $xml = file_get_contents(self::FIXTURES.'/'.$slug.'.xml');
        self::assertIsString($xml);

        $escaper = new MarkdownEscaper();
        $inline = new InlineTextRenderer($escaper);
        $normalizer = new GiiXmlNormalizer(
            new SentenceSplitter(),
            $escaper,
            new NormKeyFactory(),
            $inline,
            new CalsTableRenderer($inline),
        );

        $document = new RawDocument(
            new DocumentRef($slug, 'https://www.gesetze-im-internet.de/'.$slug.'/xml.zip'),
            $xml,
            hash('sha256', $xml),
            'application/xml',
            200,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
        );

        self::assertTrue($normalizer->supports($document));

        return $normalizer->normalize($document);
    }

    private function render(NormalizedLaw $law): string
    {
        $writer = new LawWriter('patchnotes.example');

        $output = "===== _law.yml =====\n".$writer->renderLawYaml($law);
        $output .= "\n===== README.md =====\n".$writer->renderReadme($law);

        foreach ($law->norms as $norm) {
            $output .= "\n===== ".$norm->fileName()." =====\n".$writer->renderNorm($law, $norm);
        }

        return $output;
    }

    /**
     * Dates that legitimately appear in a law (date of issue, citations) must not trip the
     * timestamp check on the day they happen to equal today.
     *
     * @return list<string>
     */
    private function knownDates(string $slug): array
    {
        $law = $this->normalize($slug);

        return array_values(array_filter([$law->dateOfIssue?->format('Y-m-d')]));
    }
}
