<?php

declare(strict_types=1);

namespace App\Git;

use App\Git\Enum\ForgeType;
use App\Git\Enum\RepositoryName;

/**
 * Typed view of one entry of patchnotes.repositories (SPEC.md § 3.1).
 */
final readonly class RepositoryConfig
{
    public function __construct(
        public RepositoryName $name,
        public string $url,
        public string $defaultBranch,
        public ForgeType $forge,
        public ?string $forgeApiUrl,
        public ?string $forgeProject,
        public ?string $sshKeyPath,
        public ?string $token,
        public ?string $webhookSecret,
        public string $localPath,
        public string $botName,
        public string $botEmail,
        public bool $pushEnabled,
        public ?string $knownHosts = null,
    ) {
    }

    /**
     * @param array<string, mixed> $repository
     * @param array<string, mixed> $git
     */
    public static function fromArray(RepositoryName $name, array $repository, array $git): self
    {
        /** @var array{ssh_key_path?: string|null, token?: string|null} $auth */
        $auth = $repository['auth'] ?? [];

        return new self(
            $name,
            (string) ($repository['url'] ?? ''),
            self::nonEmptyString($repository['default_branch'] ?? null) ?? 'main',
            ForgeType::tryFrom((string) ($repository['forge'] ?? 'none')) ?? ForgeType::None,
            self::nonEmptyString($repository['forge_api_url'] ?? null),
            self::nonEmptyString($repository['forge_project'] ?? null),
            self::nonEmptyString($auth['ssh_key_path'] ?? null),
            self::nonEmptyString($auth['token'] ?? null),
            self::nonEmptyString($repository['webhook_secret'] ?? null),
            (string) $repository['local_path'],
            (string) ($git['bot_name'] ?? 'Patchnotes Bot'),
            (string) ($git['bot_email'] ?? 'bot@patchnotes.local'),
            filter_var($git['push_enabled'] ?? false, \FILTER_VALIDATE_BOOL),
            self::nonEmptyString($git['known_hosts'] ?? null),
        );
    }

    public function hasRemote(): bool
    {
        return '' !== $this->url;
    }

    public function usesForgeApi(): bool
    {
        return ForgeType::None !== $this->forge && null !== $this->forgeProject;
    }

    /** Bare mirror used for all read access (SPEC.md § 24.10). */
    public function mirrorPath(): string
    {
        return $this->localPath.'.mirror.git';
    }

    public function worktreeRoot(): string
    {
        return $this->localPath.'.worktrees';
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        return '' !== $value ? $value : null;
    }
}
