<?php

declare(strict_types=1);

namespace App\Core\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Small pieces of mutable state that belong to the installation rather than to a deployment
 * (SPEC.md § 15): values an administrator may change at runtime, cursors of incremental imports,
 * and similar bookkeeping.
 */
#[ORM\Entity]
#[ORM\Table(name: 'setting')]
class Setting
{
    #[ORM\Id]
    #[ORM\Column(name: 'setting_key', length: 96, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $key;

    /**
     * @var array<string, mixed>|list<mixed>|string|int|float|bool|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private mixed $value;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed>|list<mixed>|string|int|float|bool|null $value
     */
    public function __construct(string $key, mixed $value = null)
    {
        $this->key = $key;
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * @return array<string, mixed>|list<mixed>|string|int|float|bool|null
     */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * @param array<string, mixed>|list<mixed>|string|int|float|bool|null $value
     */
    public function setValue(mixed $value): void
    {
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
