<?php

declare(strict_types=1);

namespace App\Source\Entity;

use App\Source\Enum\SourceHealthState;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A source adapter and its health (SPEC.md § 6.1): "bund.gii", "bund.bgbl", "bund.dip",
 * "be.landesrecht", ….
 *
 * Adapters that may not be crawled legally are kept here with state "blocked" and an explanation,
 * so the admin and the public /status page can show why a jurisdiction is missing (SPEC.md § 24.17).
 */
#[ORM\Entity]
#[ORM\Table(name: 'source')]
class Source
{
    #[ORM\Id]
    #[ORM\Column(name: 'source_key', length: 64, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $key;

    #[ORM\Column(length: 8, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $jurisdictionCode;

    #[ORM\Column(length: 255)]
    private string $title;

    /**
     * consolidated_laws | promulgations | bills | plenary.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $capabilities = [];

    #[ORM\Column]
    private bool $enabled = false;

    #[ORM\Column(length: 16, enumType: SourceHealthState::class)]
    private SourceHealthState $healthState = SourceHealthState::Disabled;

    /** Why the source is blocked or degraded; shown in the admin and on /status. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $healthNote = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSuccessAt = null;

    /** Last run that actually found a change; a long silence is itself an alert (SPEC.md § 6.1). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastChangeAt = null;

    public function __construct(string $key, string $jurisdictionCode, string $title)
    {
        $this->key = $key;
        $this->jurisdictionCode = $jurisdictionCode;
        $this->title = $title;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function jurisdictionCode(): string
    {
        return $this->jurisdictionCode;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * @param list<string> $capabilities
     */
    public function setCapabilities(array $capabilities): void
    {
        $this->capabilities = $capabilities;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function healthState(): SourceHealthState
    {
        return $this->healthState;
    }

    public function healthNote(): ?string
    {
        return $this->healthNote;
    }

    public function setHealth(SourceHealthState $state, ?string $note = null): void
    {
        $this->healthState = $state;
        $this->healthNote = $note;
    }

    public function lastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function lastSuccessAt(): ?\DateTimeImmutable
    {
        return $this->lastSuccessAt;
    }

    public function lastChangeAt(): ?\DateTimeImmutable
    {
        return $this->lastChangeAt;
    }

    public function recordRun(\DateTimeImmutable $at, bool $successful, bool $withChanges): void
    {
        $this->lastRunAt = $at;
        if ($successful) {
            $this->lastSuccessAt = $at;
        }
        if ($withChanges) {
            $this->lastChangeAt = $at;
        }
    }
}
