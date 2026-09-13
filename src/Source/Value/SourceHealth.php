<?php

declare(strict_types=1);

namespace App\Source\Value;

use App\Source\Enum\SourceHealthState;

/**
 * Health of one adapter, shown in the admin and on the public /status page (SPEC.md § 6.1).
 */
final readonly class SourceHealth
{
    public function __construct(
        public SourceHealthState $state,
        public ?string $note = null,
        public ?\DateTimeImmutable $lastSuccessAt = null,
        public ?\DateTimeImmutable $lastChangeAt = null,
    ) {
    }

    public static function healthy(?\DateTimeImmutable $lastSuccessAt = null, ?\DateTimeImmutable $lastChangeAt = null): self
    {
        return new self(SourceHealthState::Healthy, null, $lastSuccessAt, $lastChangeAt);
    }

    public static function down(string $note): self
    {
        return new self(SourceHealthState::Down, $note);
    }

    /**
     * The source forbids automated access; we never work around such a protection
     * (SPEC.md § 0.3.4, § 24.17).
     */
    public static function blocked(string $note): self
    {
        return new self(SourceHealthState::Blocked, $note);
    }

    public static function disabled(): self
    {
        return new self(SourceHealthState::Disabled, 'Disabled in the configuration.');
    }
}
