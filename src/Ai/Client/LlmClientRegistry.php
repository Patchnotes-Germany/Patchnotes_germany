<?php

declare(strict_types=1);

namespace App\Ai\Client;

use App\Ai\Enum\AiProviderType;
use App\Ai\Enum\ExecutionMode;
use App\Ai\Exception\LlmException;
use App\Core\Config\PatchnotesConfig;

/**
 * The configured providers, built on first use (SPEC.md § 8.2).
 *
 * Clients are created lazily because a typical installation configures three providers and uses
 * one: there is no reason to build an OpenAI client for an operator who only runs a local model.
 */
final class LlmClientRegistry
{
    /** @var array<string, LlmClientInterface> */
    private array $clients = [];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $providers = null;

    public function __construct(
        private readonly PatchnotesConfig $config,
        private readonly LlmClientFactory $factory,
    ) {
    }

    /**
     * @return list<string>
     */
    public function aliases(): array
    {
        return array_keys($this->providers());
    }

    public function has(string $alias): bool
    {
        return \array_key_exists($alias, $this->providers());
    }

    /**
     * Configured *and* usable: the key or base URL it needs is actually set.
     */
    public function isUsable(string $alias): bool
    {
        $settings = $this->providers()[$alias] ?? null;

        return null !== $settings && $this->factory->isUsable($alias, $settings);
    }

    public function get(string $alias): LlmClientInterface
    {
        return $this->clients[$alias] ??= $this->factory->create($alias, $this->settings($alias));
    }

    public function type(string $alias): AiProviderType
    {
        return $this->factory->type($alias, $this->settings($alias));
    }

    /**
     * Direct providers are called by the server; remote-worker providers get a job row that the
     * owner's computer picks up over HTTPS (SPEC.md § 8.3).
     */
    public function executionMode(string $alias): ExecutionMode
    {
        $settings = $this->settings($alias);
        $mode = \is_string($settings['execution'] ?? null) ? $settings['execution'] : ExecutionMode::Direct->value;

        return ExecutionMode::tryFrom($mode)
            ?? throw LlmException::misconfigured($alias, \sprintf('unknown execution mode "%s"', $mode));
    }

    /**
     * Lets the tests and `make demo` put a scripted provider in place of a real one.
     */
    public function override(string $alias, LlmClientInterface $client): void
    {
        $this->clients[$alias] = $client;
        $this->providers ??= [];
        $this->providers[$alias] ??= ['type' => AiProviderType::Fake->value];
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(string $alias): array
    {
        return $this->providers()[$alias]
            ?? throw LlmException::misconfigured($alias, 'no such provider is configured');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function providers(): array
    {
        if (null === $this->providers) {
            /** @var array<string, array<string, mixed>> $providers */
            $providers = $this->config->ai()['providers'] ?? [];
            $this->providers = $providers;
        }

        return $this->providers;
    }
}
