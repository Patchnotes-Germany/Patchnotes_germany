<?php

declare(strict_types=1);

namespace App\Content\Check;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * The phrases a card must never contain, per language (SPEC.md § 7.4, check 7).
 *
 * Prompts already ask for this, but a prompt is a request and a check is a guarantee: giving legal
 * instructions ("you must apply by…") or a political judgement is the failure mode with real
 * consequences for a reader, so it is caught mechanically before anything is published.
 */
final class ForbiddenWording
{
    /** @var array<string, array<string, list<string>>>|null language => category => phrases */
    private ?array $phrases = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/content/forbidden-wording.yml')]
        private readonly string $file,
    ) {
    }

    /**
     * Every hit in the text, as "category: phrase".
     *
     * @return list<array{category: string, phrase: string}>
     */
    public function findIn(string $text, string $language): array
    {
        $haystack = mb_strtolower((string) preg_replace('/\s+/u', ' ', $text));
        $found = [];

        foreach ($this->forLanguage($language) as $category => $phrases) {
            foreach ($phrases as $phrase) {
                if (str_contains($haystack, mb_strtolower($phrase))) {
                    $found[] = ['category' => $category, 'phrase' => $phrase];
                }
            }
        }

        return $found;
    }

    /**
     * @return array<string, list<string>> category => phrases
     */
    public function forLanguage(string $language): array
    {
        return $this->all()[$language] ?? [];
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    private function all(): array
    {
        if (null !== $this->phrases) {
            return $this->phrases;
        }

        if (!is_file($this->file)) {
            return $this->phrases = [];
        }

        /** @var array<string, mixed>|null $data */
        $data = Yaml::parseFile($this->file);
        $parsed = [];

        foreach (\is_array($data) ? $data : [] as $language => $categories) {
            if (!\is_array($categories)) {
                continue;
            }

            foreach ($categories as $category => $phrases) {
                if (!\is_array($phrases)) {
                    continue;
                }

                $parsed[(string) $language][(string) $category] = array_values(array_filter(
                    array_map(static fn (mixed $p): string => \is_scalar($p) ? trim((string) $p) : '', $phrases),
                    static fn (string $p): bool => '' !== $p,
                ));
            }
        }

        return $this->phrases = $parsed;
    }
}
