<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Config;

use App\Core\Config\PatchnotesConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PatchnotesConfig::class)]
final class PatchnotesConfigTest extends TestCase
{
    public function testTranslationLanguagesExcludeTheMasterLanguage(): void
    {
        $config = $this->config();

        self::assertSame(['ru', 'uk', 'en', 'tr'], $config->languages());
        self::assertSame('en', $config->masterLanguage());
        self::assertSame(['ru', 'uk', 'tr'], $config->translationLanguages());
    }

    public function testLanguageSupportIsDrivenByConfigurationOnly(): void
    {
        $config = $this->config();

        self::assertTrue($config->supportsLanguage('uk'));
        self::assertFalse($config->supportsLanguage('ar'));
    }

    public function testLocaleAndDirectionFallBackToSaneDefaults(): void
    {
        $config = $this->config();

        self::assertSame('tr_TR', $config->localeFor('tr'));
        self::assertSame('ltr', $config->directionFor('tr'));
        // An unknown language must not explode: RTL support is a matter of adding configuration.
        self::assertSame('fa', $config->localeFor('fa'));
        self::assertSame('ltr', $config->directionFor('fa'));
    }

    public function testTimezoneIsTheLegalTimezoneOfGermany(): void
    {
        self::assertSame('Europe/Berlin', $this->config()->timezone()->getName());
    }

    public function testNestedFeatureFlagsAreResolvedByPath(): void
    {
        $config = $this->config();

        self::assertTrue($config->isFeatureEnabled('preview_prs.bund'));
        self::assertFalse($config->isFeatureEnabled('preview_prs.laender'));
        self::assertFalse($config->isFeatureEnabled('translate_impact_zero'));
        self::assertFalse($config->isFeatureEnabled('does.not.exist'));
    }

    public function testEnabledLaenderComeFromConfiguration(): void
    {
        self::assertSame(['be', 'nw'], $this->config()->enabledLaender());
    }

    public function testUnknownRepositoryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->config()->repository('unknown');
    }

    public function testBillingIsDisabledByDefault(): void
    {
        self::assertFalse($this->config()->billingEnabled());
    }

    private function config(): PatchnotesConfig
    {
        return new PatchnotesConfig([
            'languages' => ['ru', 'uk', 'en', 'tr'],
            'master_language' => 'en',
            'language_settings' => [
                'ru' => ['locale' => 'ru_RU', 'dir' => 'ltr'],
                'uk' => ['locale' => 'uk_UA', 'dir' => 'ltr'],
                'en' => ['locale' => 'en_GB', 'dir' => 'ltr'],
                'tr' => ['locale' => 'tr_TR', 'dir' => 'ltr'],
            ],
            'timezone' => 'Europe/Berlin',
            'repositories' => [
                'laws' => ['url' => '', 'default_branch' => 'main'],
                'content' => ['url' => '', 'default_branch' => 'main'],
            ],
            'git' => ['bot_name' => 'Patchnotes Bot'],
            'sources' => ['laender' => ['enabled' => ['be', 'nw'], 'slots' => []]],
            'features' => [
                'preview_prs' => ['bund' => true, 'laender' => false],
                'translate_impact_zero' => false,
            ],
            'ai' => [],
            'review' => [],
            'notifications' => [],
            'retention' => [],
            'billing' => ['enabled' => false],
            'legal' => ['operator' => ['name' => null]],
        ]);
    }
}
