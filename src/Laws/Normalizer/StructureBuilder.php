<?php

declare(strict_types=1);

namespace App\Laws\Normalizer;

/**
 * Builds the Teil/Kapitel/Abschnitt tree of a law (SPEC.md § 24.1, `_law.yml: structure`).
 *
 * The source states nesting through the length of `gliederungskennzahl` (three digits per level);
 * norms belong to the last heading that was opened before them.
 *
 * Nodes are collected flat with a parent index and only assembled into a tree at the end — that
 * keeps the traversal free of references and easy to reason about.
 */
final class StructureBuilder
{
    /** @var list<array{parent: int|null, label: ?string, title: ?string, norms: list<string>}> */
    private array $nodes = [];

    /** @var list<int> indices of the currently open headings, one per level */
    private array $openPath = [];

    public function add(int $depth, ?string $label, ?string $title): void
    {
        $depth = max(1, $depth);

        // A heading of depth N closes everything from that level on.
        $this->openPath = \array_slice($this->openPath, 0, $depth - 1);

        $this->nodes[] = [
            'parent' => [] === $this->openPath ? null : $this->openPath[\count($this->openPath) - 1],
            'label' => $label,
            'title' => $title,
            'norms' => [],
        ];

        $this->openPath[] = \count($this->nodes) - 1;
    }

    public function attach(string $normKey): void
    {
        if ([] === $this->openPath) {
            return;
        }

        $this->nodes[$this->openPath[\count($this->openPath) - 1]]['norms'][] = $normKey;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tree(): array
    {
        return $this->childrenOf(null);
    }

    /**
     * Empty branches are dropped so `_law.yml` stays readable: an "Abschnitt" without children or
     * norms would otherwise appear as `children: {  }` in the YAML.
     *
     * @return list<array<string, mixed>>
     */
    private function childrenOf(?int $parent): array
    {
        $children = [];

        foreach ($this->nodes as $index => $node) {
            if ($node['parent'] !== $parent) {
                continue;
            }

            $children[] = array_filter(
                [
                    'label' => $node['label'],
                    'title' => $node['title'],
                    'children' => $this->childrenOf($index),
                    'norms' => $node['norms'],
                ],
                static fn (mixed $value): bool => null !== $value && '' !== $value && [] !== $value,
            );
        }

        return $children;
    }
}
