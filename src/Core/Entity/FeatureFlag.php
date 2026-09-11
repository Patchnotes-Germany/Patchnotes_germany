<?php

declare(strict_types=1);

namespace App\Core\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A runtime override of a configuration flag (SPEC.md § 15).
 *
 * The YAML configuration remains the default; this table only holds what an administrator switched
 * at runtime, so an installation can disable a misbehaving source or feature without a deployment.
 */
#[ORM\Entity]
#[ORM\Table(name: 'feature_flag')]
class FeatureFlag
{
    #[ORM\Id]
    #[ORM\Column(name: 'flag_key', length: 96, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $key;

    #[ORM\Column]
    private bool $enabled;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $key, bool $enabled)
    {
        $this->key = $key;
        $this->enabled = $enabled;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function key(): string
    {
        return $this->key;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled, ?string $note = null): void
    {
        $this->enabled = $enabled;
        $this->note = $note;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
