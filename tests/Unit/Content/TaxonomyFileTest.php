<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Taxonomy\Taxonomy;
use App\Content\Taxonomy\TaxonomyFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The shared vocabulary of profiles and audiences (SPEC.md § 9.1).
 */
#[CoversClass(TaxonomyFile::class)]
#[CoversClass(Taxonomy::class)]
final class TaxonomyFileTest extends TestCase
{
    private TaxonomyFile $file;

    protected function setUp(): void
    {
        $this->file = new TaxonomyFile();
    }

    public function testItReadsGroupsTagsTopicsAndLands(): void
    {
        $taxonomy = $this->file->parse($this->yaml());

        self::assertSame(['residence', 'family'], array_keys($taxonomy->groups()));
        self::assertSame(['blue_card', 'duldung'], $taxonomy->groups()['residence']);
        self::assertSame(['migration', 'family'], $taxonomy->topics());
        self::assertSame(['be', 'by'], $taxonomy->lands());
    }

    public function testItKnowsWhichGroupAllowsOneAnswerOnly(): void
    {
        $taxonomy = $this->file->parse($this->yaml());

        self::assertTrue($taxonomy->isExclusiveGroup('residence'));
        self::assertFalse($taxonomy->isExclusiveGroup('family'));
    }

    public function testItFindsTheGroupOfATag(): void
    {
        $taxonomy = $this->file->parse($this->yaml());

        self::assertSame('residence', $taxonomy->groupOf('blue_card'));
        self::assertNull($taxonomy->groupOf('nonexistent'));
    }

    public function testNamesAreReadPerLanguageAndFallBackToTheKey(): void
    {
        $taxonomy = $this->file->parse($this->yaml());

        self::assertSame('EU Blue Card', $taxonomy->nameOf('blue_card', 'en'));
        self::assertSame('blue_card', $taxonomy->nameOf('blue_card', 'ru'));
    }

    /**
     * The specification writes tags as a plain list; the generated file uses a map with names. A
     * contributor should not have to know which.
     */
    public function testBothWaysOfWritingTagsAreAccepted(): void
    {
        $taxonomy = $this->file->parse(<<<'YAML'
            groups:
              work:
                tags:
                  - employee
                  - retired
            YAML);

        self::assertSame(['employee', 'retired'], $taxonomy->groups()['work']);
    }

    /**
     * This is what keeps the AI inside the vocabulary: anything it invents is reported instead of
     * silently becoming an audience nobody matches.
     */
    public function testItReportsTagsAndTopicsItDoesNotKnow(): void
    {
        $taxonomy = $this->file->parse($this->yaml());

        self::assertSame(['freelancer'], $taxonomy->unknownTags(['blue_card', 'freelancer']));
        self::assertSame(['energy'], $taxonomy->unknownTopics(['migration', 'energy']));
        self::assertSame([], $taxonomy->unknownTags(['duldung']));
    }

    public function testAnEmptyFileYieldsAnEmptyVocabulary(): void
    {
        $taxonomy = $this->file->parse('');

        self::assertTrue($taxonomy->isEmpty());
        self::assertSame([], $taxonomy->tagKeys());
    }

    public function testBrokenYamlIsRejectedWithAClearMessage(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/taxonomy\.yml is not valid YAML/');

        $this->file->parse("groups:\n  x: [unclosed\n");
    }

    /**
     * The vocabulary shipped with the application is copied into the content repository at
     * bootstrap, so a mistake in it would reach every installation.
     */
    public function testTheShippedVocabularyIsValidAndComplete(): void
    {
        $path = \dirname(__DIR__, 3).'/config/content/taxonomy.default.yml';
        self::assertFileExists($path);

        $taxonomy = $this->file->parse((string) file_get_contents($path));

        self::assertFalse($taxonomy->isEmpty());
        self::assertCount(16, $taxonomy->lands(), 'all sixteen federal states');
        self::assertContains('migration', $taxonomy->topics());
        self::assertContains('blue_card', $taxonomy->tagKeys());
        self::assertContains('buergergeld', $taxonomy->tagKeys());

        // The two groups where more than one answer would be nonsense.
        self::assertTrue($taxonomy->isExclusiveGroup('residence'));
        self::assertTrue($taxonomy->isExclusiveGroup('age'));

        // Every tag belongs to exactly one group, or the matching of § 9.2 becomes ambiguous.
        $seen = [];
        foreach ($taxonomy->groups() as $tags) {
            foreach ($tags as $tag) {
                self::assertArrayNotHasKey($tag, $seen, \sprintf('the tag "%s" appears in two groups', $tag));
                $seen[$tag] = true;
            }
        }
    }

    private function yaml(): string
    {
        return <<<'YAML'
            groups:
              residence:
                exclusive: true
                tags:
                  blue_card:
                    names: { en: EU Blue Card }
                  duldung:
                    names: { en: Tolerated stay }
              family:
                tags:
                  married:
                    names: { en: Married }
            topics:
              - migration
              - family
            lands:
              - be
              - by
            YAML;
    }
}
