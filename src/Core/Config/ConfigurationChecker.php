<?php

declare(strict_types=1);

namespace App\Core\Config;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runtime validation of environment-driven configuration (SPEC.md § 24.18).
 *
 * Values that arrive through %env()% are declared as plain scalars in the configuration tree, so
 * enum/format checks happen here instead: at boot in prod, on demand via patchnotes:config:check.
 * Missing optional secrets are warnings — the feature degrades gracefully (SPEC.md § 22).
 *
 * Optional variables are nullable on purpose: "%env(default::FOO)%" resolves to null when FOO is
 * unset *or* empty.
 */
final readonly class ConfigurationChecker
{
    private string $encryptionKey;
    private string $legalOperatorName;
    private string $legalOperatorAddress;
    private string $legalOperatorEmail;

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private string $environment,
        #[Autowire('%env(APP_SECRET)%')]
        private string $appSecret,
        #[Autowire('%env(default::APP_ENCRYPTION_KEY)%')]
        ?string $encryptionKey,
        #[Autowire('%env(DATABASE_URL)%')]
        private string $databaseUrl,
        #[Autowire('%env(MEILI_URL)%')]
        private string $meiliUrl,
        #[Autowire('%env(SERVER_NAME)%')]
        private string $serverName,
        #[Autowire('%env(CRAWLER_CONTACT_EMAIL)%')]
        private string $crawlerContactEmail,
        #[Autowire('%env(GIT_BOT_EMAIL)%')]
        private string $gitBotEmail,
        #[Autowire('%env(default::LEGAL_OPERATOR_NAME)%')]
        ?string $legalOperatorName,
        #[Autowire('%env(default::LEGAL_OPERATOR_ADDRESS)%')]
        ?string $legalOperatorAddress,
        #[Autowire('%env(default::LEGAL_OPERATOR_EMAIL)%')]
        ?string $legalOperatorEmail,
    ) {
        $this->encryptionKey = $encryptionKey ?? '';
        $this->legalOperatorName = $legalOperatorName ?? '';
        $this->legalOperatorAddress = $legalOperatorAddress ?? '';
        $this->legalOperatorEmail = $legalOperatorEmail ?? '';
    }

    /**
     * @return list<ConfigurationProblem>
     */
    public function check(): array
    {
        $problems = [];
        $isProd = 'prod' === $this->environment;

        if ('' === $this->appSecret || str_starts_with($this->appSecret, 'ChangeThis')) {
            $problems[] = new ConfigurationProblem(
                $isProd ? Severity::Error : Severity::Warning,
                'APP_SECRET',
                'APP_SECRET still has its placeholder value. Run "make install" (patchnotes:secrets:generate).',
            );
        }

        if ('' === $this->encryptionKey) {
            $problems[] = new ConfigurationProblem(
                $isProd ? Severity::Error : Severity::Warning,
                'APP_ENCRYPTION_KEY',
                'No encryption key: user profile tags cannot be stored encrypted. Run patchnotes:secrets:generate.',
            );
        } elseif (\SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen((string) base64_decode($this->encryptionKey, true))) {
            $problems[] = new ConfigurationProblem(
                Severity::Error,
                'APP_ENCRYPTION_KEY',
                \sprintf('Must be base64 of exactly %d bytes (libsodium secretbox key).', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES),
            );
        }

        if (!str_starts_with($this->databaseUrl, 'mysql://')) {
            $problems[] = new ConfigurationProblem(
                Severity::Error,
                'DATABASE_URL',
                'Patchnotes runs on MySQL 8.4: the DSN must start with "mysql://".',
            );
        }

        if (!filter_var($this->meiliUrl, \FILTER_VALIDATE_URL)) {
            $problems[] = new ConfigurationProblem(Severity::Error, 'MEILI_URL', 'Not a valid URL.');
        }

        foreach (['CRAWLER_CONTACT_EMAIL' => $this->crawlerContactEmail, 'GIT_BOT_EMAIL' => $this->gitBotEmail] as $key => $email) {
            if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                $problems[] = new ConfigurationProblem(Severity::Error, $key, 'Not a valid e-mail address.');
            }
        }

        if ('' === $this->serverName) {
            $problems[] = new ConfigurationProblem(Severity::Error, 'SERVER_NAME', 'The public host name is required.');
        }

        // Impressum according to § 5 DDG: production must not start without operator details (SPEC.md § 16.3).
        foreach ([
            'LEGAL_OPERATOR_NAME' => $this->legalOperatorName,
            'LEGAL_OPERATOR_ADDRESS' => $this->legalOperatorAddress,
            'LEGAL_OPERATOR_EMAIL' => $this->legalOperatorEmail,
        ] as $key => $value) {
            if ('' === $value) {
                $problems[] = new ConfigurationProblem(
                    $isProd ? Severity::Error : Severity::Warning,
                    $key,
                    'Required for the Impressum (§ 5 DDG) in production.',
                );
            }
        }

        return $problems;
    }
}
