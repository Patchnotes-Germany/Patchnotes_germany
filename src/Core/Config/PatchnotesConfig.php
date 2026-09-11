<?php

declare(strict_types=1);

namespace App\Core\Config;

/**
 * Typed read access to the "patchnotes" configuration tree (SPEC.md § 24.19).
 *
 * Everything that varies between installations — languages, repositories, sources, AI routing,
 * review and notification policy — is reached through this service, never through hardcoded values.
 */
final readonly class PatchnotesConfig
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private array $config)
    {
    }

    /**
     * @return list<string>
     */
    public function languages(): array
    {
        /* @var list<string> */
        return $this->config['languages'];
    }

    public function masterLanguage(): string
    {
        /* @var string */
        return $this->config['master_language'];
    }

    /**
     * Languages that are translated from the master language.
     *
     * @return list<string>
     */
    public function translationLanguages(): array
    {
        return array_values(array_filter(
            $this->languages(),
            fn (string $language): bool => $language !== $this->masterLanguage(),
        ));
    }

    public function supportsLanguage(string $language): bool
    {
        return \in_array($language, $this->languages(), true);
    }

    public function localeFor(string $language): string
    {
        /** @var array<string, array{locale: string, dir: string}> $settings */
        $settings = $this->config['language_settings'];

        return $settings[$language]['locale'] ?? $language;
    }

    public function directionFor(string $language): string
    {
        /** @var array<string, array{locale: string, dir: string}> $settings */
        $settings = $this->config['language_settings'];

        return $settings[$language]['dir'] ?? 'ltr';
    }

    public function timezone(): \DateTimeZone
    {
        /** @var string $timezone */
        $timezone = $this->config['timezone'];

        return new \DateTimeZone($timezone);
    }

    /**
     * @return array<string, mixed>
     */
    public function repository(string $name): array
    {
        /** @var array<string, array<string, mixed>> $repositories */
        $repositories = $this->config['repositories'];

        return $repositories[$name] ?? throw new \InvalidArgumentException(\sprintf('Unknown repository "%s".', $name));
    }

    /**
     * @return array<string, mixed>
     */
    public function git(): array
    {
        /* @var array<string, mixed> */
        return $this->config['git'];
    }

    /**
     * @return array<string, mixed>
     */
    public function sources(): array
    {
        /* @var array<string, mixed> */
        return $this->config['sources'];
    }

    /**
     * Federal states with an enabled adapter (SPEC.md § 6.3).
     *
     * @return list<string>
     */
    public function enabledLaender(): array
    {
        /** @var array{laender: array{enabled: list<string>}} $sources */
        $sources = $this->config['sources'];

        return $sources['laender']['enabled'];
    }

    /**
     * @return array<string, mixed>
     */
    public function ai(): array
    {
        /* @var array<string, mixed> */
        return $this->config['ai'];
    }

    /**
     * @return array<string, mixed>
     */
    public function review(): array
    {
        /* @var array<string, mixed> */
        return $this->config['review'];
    }

    /**
     * @return array<string, mixed>
     */
    public function notifications(): array
    {
        /* @var array<string, mixed> */
        return $this->config['notifications'];
    }

    /**
     * @return array<string, mixed>
     */
    public function retention(): array
    {
        /* @var array<string, mixed> */
        return $this->config['retention'];
    }

    /**
     * @return array<string, mixed>
     */
    public function legalOperator(): array
    {
        /** @var array{operator: array<string, mixed>} $legal */
        $legal = $this->config['legal'];

        return $legal['operator'];
    }

    public function isFeatureEnabled(string $path): bool
    {
        /** @var array<string, mixed> $node */
        $node = $this->config['features'];

        foreach (explode('.', $path) as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                return false;
            }
            /** @var array<string, mixed>|bool|string $node */
            $node = $node[$segment];
        }

        return filter_var($node, \FILTER_VALIDATE_BOOL);
    }

    public function billingEnabled(): bool
    {
        /** @var array{enabled: bool|string} $billing */
        $billing = $this->config['billing'];

        return filter_var($billing['enabled'], \FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->config;
    }
}
