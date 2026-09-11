<?php

declare(strict_types=1);

namespace App\Laws\Entity;

use App\Content\Enum\TranslationKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Machine translation of one norm version (SPEC.md § 8.6).
 *
 * Regenerable cache: the key is (norm version, language, prompt version), so a changed norm keeps
 * the old translation attached to the old version instead of silently mixing texts.
 */
#[ORM\Entity]
#[ORM\Table(name: 'norm_translation')]
#[ORM\UniqueConstraint(name: 'uniq_norm_translation', columns: ['norm_version_id', 'lang', 'prompt_version'])]
class NormTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: NormVersion::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private NormVersion $normVersion;

    #[ORM\Column(length: 8, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $lang;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    /** Model identifier in "provider:model-id" form, for transparency on the website. */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $promptVersion;

    #[ORM\Column(length: 16, enumType: TranslationKind::class)]
    private TranslationKind $kind = TranslationKind::Machine;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(NormVersion $normVersion, string $lang, string $content, int $promptVersion = 1)
    {
        $this->normVersion = $normVersion;
        $this->lang = $lang;
        $this->content = $content;
        $this->promptVersion = $promptVersion;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function normVersion(): NormVersion
    {
        return $this->normVersion;
    }

    public function lang(): string
    {
        return $this->lang;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): void
    {
        $this->model = $model;
    }

    public function promptVersion(): int
    {
        return $this->promptVersion;
    }

    public function kind(): TranslationKind
    {
        return $this->kind;
    }

    public function setKind(TranslationKind $kind): void
    {
        $this->kind = $kind;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
