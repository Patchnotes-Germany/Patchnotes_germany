<?php

declare(strict_types=1);

namespace App\Laws\Entity;

use App\Laws\Enum\JurisdictionType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * "bund" plus the 16 federal states, ISO 3166-2:DE codes in lower case (SPEC.md § 4.4).
 *
 * The code is the natural primary key: it appears in every norm reference
 * ({jurisdiction}/{law-slug}/{norm-key}), in URLs and in the repository layout.
 */
#[ORM\Entity]
#[ORM\Table(name: 'jurisdiction')]
class Jurisdiction
{
    #[ORM\Id]
    #[ORM\Column(length: 8, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $code;

    #[ORM\Column(length: 16, enumType: JurisdictionType::class)]
    private JurisdictionType $type;

    /** German name, e.g. "Nordrhein-Westfalen". */
    #[ORM\Column(length: 128)]
    private string $nameDe;

    /**
     * Translated names, keyed by language ("ru", "uk", "en", "tr" — from patchnotes.languages).
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $names = [];

    #[ORM\Column]
    private bool $enabled = false;

    public function __construct(string $code, JurisdictionType $type, string $nameDe)
    {
        $this->code = $code;
        $this->type = $type;
        $this->nameDe = $nameDe;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function type(): JurisdictionType
    {
        return $this->type;
    }

    public function nameDe(): string
    {
        return $this->nameDe;
    }

    public function name(string $language): string
    {
        return $this->names[$language] ?? $this->nameDe;
    }

    /**
     * @return array<string, string>
     */
    public function names(): array
    {
        return $this->names;
    }

    /**
     * @param array<string, string> $names
     */
    public function setNames(array $names): void
    {
        $this->names = $names;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function isFederal(): bool
    {
        return JurisdictionType::Bund === $this->type;
    }
}
