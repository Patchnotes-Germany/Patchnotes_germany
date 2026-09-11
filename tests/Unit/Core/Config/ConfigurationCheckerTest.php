<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Config;

use App\Core\Config\ConfigurationChecker;
use App\Core\Config\ConfigurationProblem;
use App\Core\Config\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigurationChecker::class)]
final class ConfigurationCheckerTest extends TestCase
{
    public function testAFullyConfiguredProductionSetupHasNoProblems(): void
    {
        self::assertSame([], $this->checker()->check());
    }

    public function testPlaceholderSecretIsAnErrorInProductionAndAWarningInDevelopment(): void
    {
        $prod = $this->checker(appSecret: 'ChangeThisAppSecretInEnvLocal');
        self::assertSame(Severity::Error, $this->problemFor($prod->check(), 'APP_SECRET')->severity);

        $dev = $this->checker(environment: 'dev', appSecret: 'ChangeThisAppSecretInEnvLocal');
        self::assertSame(Severity::Warning, $this->problemFor($dev->check(), 'APP_SECRET')->severity);
    }

    public function testEncryptionKeyMustBeALibsodiumSecretboxKey(): void
    {
        $checker = $this->checker(encryptionKey: base64_encode('too-short'));

        $problem = $this->problemFor($checker->check(), 'APP_ENCRYPTION_KEY');
        self::assertSame(Severity::Error, $problem->severity);
    }

    public function testOnlyMysqlIsAccepted(): void
    {
        $checker = $this->checker(databaseUrl: 'postgresql://app:app@database:5432/app');

        self::assertSame(Severity::Error, $this->problemFor($checker->check(), 'DATABASE_URL')->severity);
    }

    public function testImpressumDetailsAreOnlyMandatoryInProduction(): void
    {
        $dev = $this->checker(environment: 'dev', legalOperatorName: '', legalOperatorAddress: '', legalOperatorEmail: '');
        foreach (['LEGAL_OPERATOR_NAME', 'LEGAL_OPERATOR_ADDRESS', 'LEGAL_OPERATOR_EMAIL'] as $key) {
            self::assertSame(Severity::Warning, $this->problemFor($dev->check(), $key)->severity);
        }

        $prod = $this->checker(legalOperatorName: '');
        self::assertSame(Severity::Error, $this->problemFor($prod->check(), 'LEGAL_OPERATOR_NAME')->severity);
    }

    public function testInvalidContactAddressesAreReported(): void
    {
        $checker = $this->checker(crawlerContactEmail: 'not-an-address');

        self::assertSame(Severity::Error, $this->problemFor($checker->check(), 'CRAWLER_CONTACT_EMAIL')->severity);
    }

    private function checker(
        string $environment = 'prod',
        string $appSecret = 'f4b1c0de0000000000000000000000000000000000000000000000000000beef',
        ?string $encryptionKey = null,
        string $databaseUrl = 'mysql://patchnotes:patchnotes@mysql:3306/patchnotes?serverVersion=8.4.11',
        string $meiliUrl = 'http://meilisearch:7700',
        string $serverName = 'patchnotes.example',
        string $crawlerContactEmail = 'bot@patchnotes.example',
        string $gitBotEmail = 'bot@patchnotes.example',
        string $legalOperatorName = 'Operator',
        string $legalOperatorAddress = 'Street 1, 10115 Berlin',
        string $legalOperatorEmail = 'legal@patchnotes.example',
    ): ConfigurationChecker {
        return new ConfigurationChecker(
            $environment,
            $appSecret,
            $encryptionKey ?? base64_encode(sodium_crypto_secretbox_keygen()),
            $databaseUrl,
            $meiliUrl,
            $serverName,
            $crawlerContactEmail,
            $gitBotEmail,
            $legalOperatorName,
            $legalOperatorAddress,
            $legalOperatorEmail,
        );
    }

    /**
     * @param list<ConfigurationProblem> $problems
     */
    private function problemFor(array $problems, string $key): ConfigurationProblem
    {
        foreach ($problems as $problem) {
            if ($key === $problem->key) {
                return $problem;
            }
        }

        self::fail(\sprintf('No problem reported for "%s".', $key));
    }
}
