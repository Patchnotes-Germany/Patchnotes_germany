<?php

declare(strict_types=1);

namespace App\Laws\Normalizer;

/**
 * Converts the CALS tables of the source into Markdown (SPEC.md § 4.2).
 *
 * Simple tables become GFM tables, because those diff and read well. As soon as a table uses
 * merged cells — which happens in the Anlagen of tax and social law — GFM cannot express it, so the
 * table is emitted as plain HTML restricted to the allowed element set (table, thead, tbody, tr,
 * th, td with colspan/rowspan) and sanitised again at render time.
 */
final readonly class CalsTableRenderer
{
    public function __construct(private InlineTextRenderer $inline)
    {
    }

    public function render(\DOMElement $table, string $assetBase = ''): string
    {
        $groups = [];
        foreach ($table->getElementsByTagName('tgroup') as $group) {
            $groups[] = $this->renderGroup($group, $assetBase);
        }

        $title = $this->title($table, $assetBase);
        $body = implode("\n\n", array_filter($groups, static fn (string $g): bool => '' !== $g));

        if ('' === $body) {
            return '';
        }

        return '' !== $title ? $title."\n\n".$body : $body;
    }

    private function title(\DOMElement $table, string $assetBase): string
    {
        foreach ($table->childNodes as $child) {
            if ($child instanceof \DOMElement && 'Title' === $child->nodeName) {
                $text = $this->inline->render($child, $assetBase);

                return '' !== $text ? '**'.$text.'**' : '';
            }
        }

        return '';
    }

    private function renderGroup(\DOMElement $group, string $assetBase): string
    {
        $head = $this->rowsOf($group, 'thead', $assetBase);
        $body = $this->rowsOf($group, 'tbody', $assetBase);
        $foot = $this->rowsOf($group, 'tfoot', $assetBase);

        if ([] === $head && [] === $body && [] === $foot) {
            return '';
        }

        $needsHtml = $this->hasSpans($group) || $this->isRagged([...$head, ...$body, ...$foot]);

        return $needsHtml
            ? $this->renderHtml($group, $assetBase)
            : $this->renderGfm($head, [...$body, ...$foot]);
    }

    /**
     * @return list<list<string>>
     */
    private function rowsOf(\DOMElement $group, string $section, string $assetBase): array
    {
        $rows = [];
        foreach ($group->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->nodeName !== $section) {
                continue;
            }
            foreach ($child->getElementsByTagName('row') as $row) {
                $cells = [];
                foreach ($row->getElementsByTagName('entry') as $entry) {
                    $cells[] = str_replace('|', '\\|', $this->inline->render($entry, $assetBase));
                }
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    private function hasSpans(\DOMElement $group): bool
    {
        foreach ($group->getElementsByTagName('entry') as $entry) {
            if ('' !== $entry->getAttribute('morerows')
                || ('' !== $entry->getAttribute('namest') && $entry->getAttribute('namest') !== $entry->getAttribute('nameend'))
                || '' !== $entry->getAttribute('spanname')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<list<string>> $rows
     */
    private function isRagged(array $rows): bool
    {
        $widths = array_unique(array_map(\count(...), $rows));

        return \count($widths) > 1;
    }

    /**
     * @param list<list<string>> $head
     * @param list<list<string>> $body
     */
    private function renderGfm(array $head, array $body): string
    {
        $columns = max(array_map(\count(...), [...$head, ...$body]) ?: [0]);
        if (0 === $columns) {
            return '';
        }

        $header = $head[0] ?? array_fill(0, $columns, '');
        $rest = [...\array_slice($head, 1), ...$body];

        $lines = [
            $this->gfmRow($header, $columns),
            '|'.str_repeat(' --- |', $columns),
        ];
        foreach ($rest as $row) {
            $lines[] = $this->gfmRow($row, $columns);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $cells
     */
    private function gfmRow(array $cells, int $columns): string
    {
        $cells = array_pad($cells, $columns, '');

        return '| '.implode(' | ', $cells).' |';
    }

    private function renderHtml(\DOMElement $group, string $assetBase): string
    {
        $html = "<table>\n";

        foreach (['thead', 'tbody', 'tfoot'] as $section) {
            $sectionHtml = '';
            foreach ($group->childNodes as $child) {
                if (!$child instanceof \DOMElement || $child->nodeName !== $section) {
                    continue;
                }
                foreach ($child->getElementsByTagName('row') as $row) {
                    $sectionHtml .= $this->htmlRow($row, 'thead' === $section, $assetBase);
                }
            }

            if ('' !== $sectionHtml) {
                // tfoot rows are appended to the body: the distinction carries no legal meaning.
                $tag = 'tfoot' === $section ? 'tbody' : $section;
                $html .= "<$tag>\n".$sectionHtml."</$tag>\n";
            }
        }

        return $html.'</table>';
    }

    private function htmlRow(\DOMElement $row, bool $isHeader, string $assetBase): string
    {
        $cellTag = $isHeader ? 'th' : 'td';
        $html = "<tr>\n";

        foreach ($row->getElementsByTagName('entry') as $entry) {
            $attributes = '';

            $morerows = $entry->getAttribute('morerows');
            if (ctype_digit($morerows) && (int) $morerows > 0) {
                $attributes .= \sprintf(' rowspan="%d"', (int) $morerows + 1);
            }

            $colspan = $this->colspan($entry);
            if ($colspan > 1) {
                $attributes .= \sprintf(' colspan="%d"', $colspan);
            }

            $content = htmlspecialchars(
                $this->stripEscaping($this->inline->render($entry, $assetBase)),
                \ENT_QUOTES | \ENT_SUBSTITUTE,
                'UTF-8',
            );

            $html .= \sprintf("<%s%s>%s</%s>\n", $cellTag, $attributes, $content, $cellTag);
        }

        return $html."</tr>\n";
    }

    /**
     * Column names are of the form "col1", "col2"; a span goes from namest to nameend.
     */
    private function colspan(\DOMElement $entry): int
    {
        $start = $entry->getAttribute('namest');
        $end = $entry->getAttribute('nameend');

        if ('' === $start || '' === $end || $start === $end) {
            return 1;
        }

        $startNumber = (int) preg_replace('/\D+/', '', $start);
        $endNumber = (int) preg_replace('/\D+/', '', $end);

        return $endNumber > $startNumber ? $endNumber - $startNumber + 1 : 1;
    }

    /**
     * Inside HTML the Markdown escaping is not only unnecessary, it would be visible.
     */
    private function stripEscaping(string $text): string
    {
        return (string) preg_replace('/\\\\([\\\\`*_\[\]<])/', '$1', $text);
    }
}
