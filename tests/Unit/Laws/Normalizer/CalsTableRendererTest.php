<?php

declare(strict_types=1);

namespace App\Tests\Unit\Laws\Normalizer;

use App\Laws\Normalizer\CalsTableRenderer;
use App\Laws\Normalizer\InlineTextRenderer;
use App\Laws\Normalizer\MarkdownEscaper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tables of the source are CALS; simple ones become GFM, merged cells force safe HTML
 * (SPEC.md § 4.2).
 */
#[CoversClass(CalsTableRenderer::class)]
final class CalsTableRendererTest extends TestCase
{
    private CalsTableRenderer $renderer;

    protected function setUp(): void
    {
        $escaper = new MarkdownEscaper();
        $this->renderer = new CalsTableRenderer(new InlineTextRenderer($escaper));
    }

    public function testASimpleTableBecomesAGfmTable(): void
    {
        $markdown = $this->render(<<<'XML'
            <table>
              <tgroup cols="2">
                <thead><row><entry>Stufe</entry><entry>Betrag</entry></row></thead>
                <tbody>
                  <row><entry>1</entry><entry>500 Euro</entry></row>
                  <row><entry>2</entry><entry>750 Euro</entry></row>
                </tbody>
              </tgroup>
            </table>
            XML);

        self::assertSame(
            "| Stufe | Betrag |\n| --- | --- |\n| 1 | 500 Euro |\n| 2 | 750 Euro |",
            $markdown,
        );
    }

    public function testMergedCellsForceHtmlBecauseGfmCannotExpressThem(): void
    {
        $markdown = $this->render(<<<'XML'
            <table>
              <tgroup cols="3">
                <colspec colname="col1"/><colspec colname="col2"/><colspec colname="col3"/>
                <tbody>
                  <row><entry namest="col1" nameend="col3">Abschnitt 1</entry></row>
                  <row><entry morerows="1">Zeichen</entry><entry>a</entry><entry>b</entry></row>
                </tbody>
              </tgroup>
            </table>
            XML);

        self::assertStringStartsWith('<table>', $markdown);
        self::assertStringContainsString('<td colspan="3">Abschnitt 1</td>', $markdown);
        self::assertStringContainsString('<td rowspan="2">Zeichen</td>', $markdown);
        self::assertStringEndsWith('</table>', $markdown);
    }

    public function testRaggedRowsAlsoFallBackToHtml(): void
    {
        // A GFM table with a varying number of columns would silently lose cells.
        $markdown = $this->render(<<<'XML'
            <table>
              <tgroup cols="2">
                <tbody>
                  <row><entry>a</entry><entry>b</entry></row>
                  <row><entry>nur eine Zelle</entry></row>
                </tbody>
              </tgroup>
            </table>
            XML);

        self::assertStringStartsWith('<table>', $markdown);
    }

    public function testPipesInCellsAreEscapedInGfm(): void
    {
        $markdown = $this->render(<<<'XML'
            <table><tgroup cols="2"><tbody>
              <row><entry>a | b</entry><entry>c</entry></row>
              <row><entry>d</entry><entry>e</entry></row>
            </tbody></tgroup></table>
            XML);

        self::assertStringContainsString('| a \| b | c |', $markdown);
    }

    public function testATitleIsKeptAboveTheTable(): void
    {
        $markdown = $this->render(<<<'XML'
            <table>
              <Title>Anlage 1</Title>
              <tgroup cols="1"><tbody><row><entry>Wert</entry></row></tbody></tgroup>
            </table>
            XML);

        self::assertStringStartsWith("**Anlage 1**\n\n", $markdown);
    }

    public function testAnEmptyTableProducesNothing(): void
    {
        self::assertSame('', $this->render('<table><tgroup cols="1"><tbody/></tgroup></table>'));
    }

    private function render(string $xml): string
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);
        $table = $document->documentElement;
        self::assertInstanceOf(\DOMElement::class, $table);

        return $this->renderer->render($table, 'https://www.gesetze-im-internet.de/example');
    }
}
