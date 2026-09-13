<?php

declare(strict_types=1);

namespace App\Content\Card;

use App\Content\Entity\Card;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads and writes one card file, `changes/<year>/<change-id>/<lang>.md` (SPEC.md § 5.4).
 *
 * The heading of a section is translated, its key is not: the key lives in the anchor
 * `## Что меняется {#what_changes}`. That is what lets a Russian and a Turkish card be compared
 * section by section, and what makes an editor free to improve a heading without breaking the site.
 *
 * Nothing here evaluates the content. Placeholders stay as they are; resolving them is the job of
 * the renderer, never of a template engine (SPEC.md § 24.13).
 */
final readonly class CardFile
{
    private const string ANCHOR_PATTERN = '/^##\s+(?<heading>.*?)\s*\{#(?<key>[a-z_]+)\}\s*$/u';

    public function parse(string $markdown): ParsedCard
    {
        [$frontMatter, $body] = $this->splitFrontMatter($markdown);

        $sections = [];
        $headings = [];
        $unknown = [];
        $currentKey = null;
        /** @var list<string> $buffer */
        $buffer = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (1 === preg_match(self::ANCHOR_PATTERN, $line, $matches)) {
                if (null !== $currentKey) {
                    $sections[$currentKey] = trim(implode("\n", $buffer));
                }

                $currentKey = $matches['key'];
                $headings[$currentKey] = $matches['heading'];
                $buffer = [];

                if (!\in_array($currentKey, Card::SECTION_KEYS, true)) {
                    $unknown[] = $currentKey;
                }

                continue;
            }

            // Text before the first anchored heading is not part of any section and is dropped on
            // purpose: a card is its sections, and silently keeping stray text would let content
            // appear on the site that no check ever looked at.
            if (null !== $currentKey) {
                $buffer[] = $line;
            }
        }

        if (null !== $currentKey) {
            $sections[$currentKey] = trim(implode("\n", $buffer));
        }

        return new ParsedCard($frontMatter, $sections, $headings, $unknown);
    }

    /**
     * @param array<string, mixed>  $frontMatter
     * @param array<string, string> $sections    keyed by section key
     * @param array<string, string> $headings    localized headings, keyed by section key
     */
    public function render(array $frontMatter, array $sections, array $headings = []): string
    {
        $out = "---\n".rtrim($this->yaml($frontMatter))."\n---\n";

        foreach (Card::SECTION_KEYS as $key) {
            $body = trim($sections[$key] ?? '');

            // An empty section is allowed everywhere but in the summary (SPEC.md § 5.4); it is kept
            // as a heading so a translator sees that the section exists.
            $out .= "\n## ".($headings[$key] ?? $this->fallbackHeading($key)).' {#'.$key."}\n";

            if ('' !== $body) {
                $out .= "\n".$body."\n";
            }
        }

        return $out;
    }

    /**
     * The hash that tells a translation it is out of date (SPEC.md § 5.4): the section bodies of
     * the master plus the placeholders they use. Headings are excluded — improving the German
     * wording of a heading must not invalidate four translations.
     *
     * @param array<string, string> $sections
     * @param list<string>          $placeholders canonical placeholder strings
     */
    public function masterHash(array $sections, array $placeholders): string
    {
        $parts = [];
        foreach (Card::SECTION_KEYS as $key) {
            $parts[] = $key.':'.trim($sections[$key] ?? '');
        }

        sort($placeholders);

        return hash('sha256', implode("\0", [...$parts, ...$placeholders]));
    }

    /**
     * @return array{array<string, mixed>, string}
     */
    private function splitFrontMatter(string $markdown): array
    {
        $markdown = str_replace("\r\n", "\n", $markdown);

        if (!str_starts_with($markdown, "---\n")) {
            return [[], $markdown];
        }

        $end = strpos($markdown, "\n---", 4);

        if (false === $end) {
            return [[], $markdown];
        }

        $raw = substr($markdown, 4, $end - 4);
        $body = ltrim(substr($markdown, $end + 4), "\n");

        try {
            /** @var array<string, mixed>|null $parsed */
            $parsed = Yaml::parse($raw);
        } catch (ParseException) {
            return [[], $body];
        }

        return [\is_array($parsed) ? $parsed : [], $body];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function yaml(array $data): string
    {
        return Yaml::dump($data, 2, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    private function fallbackHeading(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }
}
