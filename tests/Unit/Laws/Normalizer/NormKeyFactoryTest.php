<?php

declare(strict_types=1);

namespace App\Tests\Unit\Laws\Normalizer;

use App\Laws\Normalizer\NormKeyFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Norm keys are file names in git and part of every reference `{jurisdiction}/{law}/{norm}`
 * (SPEC.md § 24.1), so they must be stable and unambiguous.
 */
#[CoversClass(NormKeyFactory::class)]
final class NormKeyFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function designations(): iterable
    {
        yield 'paragraph' => ['§ 1', 'p1'];
        yield 'paragraph with letter' => ['§ 18g', 'p18g'];
        yield 'paragraph with letter suffix' => ['§ 35a', 'p35a'];
        yield 'joint paragraphs' => ['§§ 5 bis 7', 'p5-7'];
        yield 'joint paragraphs with und' => ['§§ 5 und 6', 'p5-6'];
        yield 'article short' => ['Art 3', 'art3'];
        yield 'article long' => ['Artikel 12a', 'art12a'];
        yield 'annex with number' => ['Anlage 1', 'anl1'];
        yield 'annex without number' => ['Anlage', 'anl'];
        yield 'appendix' => ['Anhang 2', 'anl2'];
        yield 'preamble' => ['Eingangsformel', 'eingangsformel'];
        yield 'closing formula' => ['Schlussformel', 'schlussformel'];
        yield 'umlauts are transliterated' => ['Inhaltsübersicht', 'inhaltsuebersicht'];
        yield 'non-breaking space' => ["§\u{00a0}18g", 'p18g'];
    }

    #[DataProvider('designations')]
    public function testKeyFromDesignation(?string $designation, string $expected): void
    {
        self::assertSame($expected, new NormKeyFactory()->fromDesignation($designation));
    }

    public function testANormWithoutDesignationFallsBackToTheSourceDocumentNumber(): void
    {
        self::assertSame(
            'n-bjnr195010004bjne002901000',
            new NormKeyFactory()->fromDesignation(null, 'BJNR195010004BJNE002901000'),
        );
    }

    public function testDuplicateDesignationsInsideOneLawArePrefixedByTheirParent(): void
    {
        $factory = new NormKeyFactory();

        // "§ 1" inside Anlage 2 must not collide with the law's own "§ 1" (SPEC.md § 24.1).
        self::assertSame('anl2-p1', $factory->fromDesignation('§ 1', null, 'anl2'));
    }

    public function testCollidingKeysGetASuffixInsteadOfOverwritingAFile(): void
    {
        $factory = new NormKeyFactory();

        self::assertSame('p1', $factory->unique('p1', []));
        self::assertSame('p1-2', $factory->unique('p1', ['p1']));
        self::assertSame('p1-3', $factory->unique('p1', ['p1', 'p1-2']));
    }

    public function testKeysAreLowercaseAndUrlSafe(): void
    {
        $factory = new NormKeyFactory();

        foreach (['§ 18G', 'Art 3', 'Anlage IV', 'Schlußformel'] as $designation) {
            self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $factory->fromDesignation($designation));
        }
    }
}
