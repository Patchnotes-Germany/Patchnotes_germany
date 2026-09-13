<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Check\LanguageDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Catching a translation that came back in the wrong language (SPEC.md § 7.4, check 5).
 *
 * The detector only has to be right about the languages this project publishes in — the failure it
 * exists for is a model returning Russian for Ukrainian, or handing back the English master
 * untranslated.
 */
#[CoversClass(LanguageDetector::class)]
final class LanguageDetectorTest extends TestCase
{
    private LanguageDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new LanguageDetector();
    }

    #[DataProvider('texts')]
    public function testItRecognisesTheLanguagesOfTheProject(string $expected, string $text): void
    {
        self::assertSame($expected, $this->detector->detect($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function texts(): iterable
    {
        yield 'russian' => ['ru', 'Для владельцев Blue Card повышается минимальная зарплата, которая нужна для получения разрешения.'];
        yield 'ukrainian' => ['uk', 'Для власників Blue Card підвищується мінімальна зарплата, яка потрібна для отримання дозволу.'];
        yield 'english' => ['en', 'The law provides that the minimum salary for the Blue Card rises, and this concerns people who work here.'];
        yield 'turkish' => ['tr', 'Bu yasa, Mavi Kart için gereken asgari maaşın artacağını öngörüyor ve bir çalışan için geçerlidir.'];
        yield 'german' => ['de', 'Das Gesetz sieht vor, dass die Mindestgehaltsgrenze für die Blaue Karte steigt und nicht mehr gilt.'];
    }

    /**
     * The pair this check really exists for: the two Cyrillic languages.
     */
    public function testItTellsUkrainianFromRussian(): void
    {
        $ukrainian = 'Ці зміни стосуються іноземців, які працюють у Німеччині та мають дозвіл на проживання.';
        $russian = 'Эти изменения касаются иностранцев, которые работают в Германии и имеют разрешение на пребывание.';

        self::assertSame('uk', $this->detector->detect($ukrainian));
        self::assertSame('ru', $this->detector->detect($russian));
    }

    /**
     * A confident wrong answer would be worse than no answer, so short text yields nothing.
     */
    public function testItStaysSilentOnTextThatIsTooShort(): void
    {
        self::assertNull($this->detector->detect('Kurz.'));
        self::assertNull($this->detector->detect(''));
    }

    public function testAnExpectedLanguageIsAccepted(): void
    {
        self::assertTrue($this->detector->looksLike(
            'Для владельцев Blue Card повышается минимальная зарплата, которая нужна для разрешения.',
            'ru',
        ));
    }

    public function testAWrongLanguageIsRejected(): void
    {
        self::assertFalse($this->detector->looksLike(
            'The law provides that the minimum salary for the Blue Card rises for everyone here.',
            'ru',
        ));
    }

    /**
     * When the detector cannot tell, the check must not block the card.
     */
    public function testUncertainTextIsNotTreatedAsWrong(): void
    {
        self::assertTrue($this->detector->looksLike('Blue Card 2026', 'tr'));
    }
}
