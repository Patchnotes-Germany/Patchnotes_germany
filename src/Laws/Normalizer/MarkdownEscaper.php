<?php

declare(strict_types=1);

namespace App\Laws\Normalizer;

/**
 * Makes sure a law text is never accidentally interpreted as Markdown (SPEC.md § 24.2).
 *
 * Two things must not happen: a line of the law turning into a heading, quote or list because it
 * happens to start with `#`, `>` or `1.`, and a renderer *renumbering* an enumeration — the printed
 * numbers of a law are part of its text ("Nummer 3" must stay 3, even if the list starts at 3).
 */
final class MarkdownEscaper
{
    /** Characters that would start a block construct at the beginning of a line. */
    private const string LEADING_BLOCK_CHARACTERS = '#>|+-*=~`';

    /**
     * Escapes one line of plain law text.
     */
    public function escapeLine(string $line): string
    {
        $line = $this->escapeInline($line);

        return $this->escapeLeading($line);
    }

    /**
     * Escapes the original enumeration marker of a list item ("1.", "a)", "aa)") so the renderer
     * keeps it verbatim instead of generating its own numbering.
     */
    public function escapeListMarker(string $marker): string
    {
        $marker = $this->escapeInline(trim($marker));

        // "1." → "1\." and "1)" → "1\)"; letters behave the same way.
        return (string) preg_replace('/^([\p{L}\d]{1,6})([.)])/u', '$1\\\\$2', $marker);
    }

    /**
     * Inline characters that could turn into emphasis, code, links or raw HTML.
     */
    public function escapeInline(string $text): string
    {
        return str_replace(
            ['\\', '`', '*', '_', '[', ']', '<'],
            ['\\\\', '\\`', '\\*', '\\_', '\\[', '\\]', '\\<'],
            $text,
        );
    }

    private function escapeLeading(string $line): string
    {
        if ('' === $line) {
            return $line;
        }

        $trimmed = ltrim($line);
        if ('' === $trimmed) {
            return $line;
        }

        $indentation = substr($line, 0, \strlen($line) - \strlen($trimmed));
        $first = $trimmed[0];

        if (str_contains(self::LEADING_BLOCK_CHARACTERS, $first)) {
            return $indentation.'\\'.$trimmed;
        }

        // "1." / "1)" at the start of a line would become an ordered list.
        if (1 === preg_match('/^(\d{1,9})([.)])(\s|$)/', $trimmed, $matches)) {
            return $indentation.$matches[1].'\\'.$matches[2].substr($trimmed, \strlen($matches[1]) + 1);
        }

        return $line;
    }
}
