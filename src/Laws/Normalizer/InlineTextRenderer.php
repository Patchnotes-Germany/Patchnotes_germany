<?php

declare(strict_types=1);

namespace App\Laws\Normalizer;

/**
 * Renders the inline content of a GII element into plain, escaped text (SPEC.md § 4.2, § 24.2).
 *
 * Typographic markup (bold, italics, letter spacing) is dropped on purpose: it carries no legal
 * meaning and would add noise to every diff. What *is* preserved is anything that changes the
 * meaning of the text — superscripts and subscripts, footnote references, quotation marks and
 * links to images that stay on the source server (we never copy binaries into the repository).
 */
final readonly class InlineTextRenderer
{
    public function __construct(private MarkdownEscaper $escaper)
    {
    }

    /**
     * @param \DOMNode $node      element whose children are rendered
     * @param string   $assetBase absolute URL of the law directory at the source, used for images
     */
    public function render(\DOMNode $node, string $assetBase = ''): string
    {
        return $this->collapse($this->renderChildren($node, $assetBase));
    }

    /**
     * Unescaped variant for values that never pass through a Markdown renderer: YAML front matter,
     * `_law.yml` titles and the structure tree.
     */
    public function renderPlain(\DOMNode $node, string $assetBase = ''): string
    {
        return $this->collapse($this->renderChildren($node, $assetBase, ' ', escape: false));
    }

    /**
     * Same as render(), but `BR` becomes a real line break: used where the source marks separate
     * lines that are not sentences (addresses, formulas, table cells).
     *
     * @return list<string>
     */
    public function renderLines(\DOMNode $node, string $assetBase = ''): array
    {
        $text = $this->renderChildren($node, $assetBase, "\n");

        $lines = array_map(
            fn (string $line): string => $this->collapse($line),
            preg_split('/\n/', $text) ?: [],
        );

        return array_values(array_filter($lines, static fn (string $line): bool => '' !== $line));
    }

    /**
     * Footnote ids of the source are long and document-specific; a short, deterministic label keeps
     * the Markdown readable while staying unique inside the file.
     */
    public static function footnoteLabel(string $sourceId): string
    {
        $label = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $sourceId));

        return trim($label, '-');
    }

    private function renderChildren(\DOMNode $node, string $assetBase, string $breakMarker = ' ', bool $escape = true): string
    {
        $output = '';

        foreach ($node->childNodes as $child) {
            $output .= $this->renderNode($child, $assetBase, $breakMarker, $escape);
        }

        return $output;
    }

    private function renderNode(\DOMNode $node, string $assetBase, string $breakMarker, bool $escape): string
    {
        if ($node instanceof \DOMText) {
            $text = $node->nodeValue ?? '';

            return $escape ? $this->escaper->escapeInline($text) : $text;
        }

        if (!$node instanceof \DOMElement) {
            return '';
        }

        return match ($node->nodeName) {
            'BR' => $breakMarker,
            'SUP' => '<sup>'.$this->renderChildren($node, $assetBase, $breakMarker, $escape).'</sup>',
            'SUB' => '<sub>'.$this->renderChildren($node, $assetBase, $breakMarker, $escape).'</sub>',
            'QuoteL' => '„',
            'QuoteR' => '“',
            'FnR' => $this->footnoteReference($node),
            'IMG' => $this->image($node, $assetBase, $escape),
            'FILE' => $this->file($node, $assetBase, $escape),
            // A list inside a table cell or a title is flattened, but its items must stay apart:
            // otherwise "Ge- oder Verbot" and "1. Wer ein Fahrzeug führt" run into each other.
            'DT' => ' '.$this->renderChildren($node, $assetBase, $breakMarker, $escape).' ',
            'DD', 'LA' => $this->renderChildren($node, $assetBase, $breakMarker, $escape).' ',
            // Layout-only elements of the print edition.
            'Split', 'Accolade', 'ABWFORMAT', 'FnArea', 'AttArea', 'AttR' => '',
            default => $this->renderChildren($node, $assetBase, $breakMarker, $escape),
        };
    }

    private function footnoteReference(\DOMElement $node): string
    {
        $id = $node->getAttribute('ID');

        return '' !== $id ? '[^'.self::footnoteLabel($id).']' : '';
    }

    private function image(\DOMElement $node, string $assetBase, bool $escape = true): string
    {
        $source = $node->getAttribute('SRC');
        if ('' === $source) {
            return '';
        }

        $alt = $node->getAttribute('alt');
        $alt = '' !== $alt ? ($escape ? $this->escaper->escapeInline($alt) : $alt) : 'Abbildung';

        // Images stay on the source server (SPEC.md § 4.2: link, never copy binaries).
        return \sprintf('![%s](%s)', $alt, $this->absoluteUrl($source, $assetBase));
    }

    private function file(\DOMElement $node, string $assetBase, bool $escape = true): string
    {
        $source = $node->getAttribute('SRC');
        if ('' === $source) {
            return '';
        }

        $title = $node->getAttribute('title');
        $title = '' !== $title ? ($escape ? $this->escaper->escapeInline($title) : $title) : $source;

        return \sprintf('[%s](%s)', $title, $this->absoluteUrl($source, $assetBase));
    }

    private function absoluteUrl(string $source, string $assetBase): string
    {
        if (1 === preg_match('#^https?://#i', $source)) {
            return $source;
        }

        return rtrim($assetBase, '/').'/'.ltrim($source, '/');
    }

    private function collapse(string $text): string
    {
        // Non-breaking and narrow spaces of the print edition become ordinary spaces.
        $text = str_replace(["\u{00a0}", "\u{202f}", "\u{2009}"], ' ', $text);

        return trim((string) preg_replace('/[ \t\r\n]+/u', ' ', $text));
    }
}
