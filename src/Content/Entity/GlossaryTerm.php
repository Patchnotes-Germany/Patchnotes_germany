<?php

declare(strict_types=1);

namespace App\Content\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A German term and how it is rendered in one language (SPEC.md § 5.5).
 *
 * Rule: terms people see in letters from authorities stay German and get an explanation
 * ("Aufenthaltstitel (вид на жительство)"). The glossary is passed into every translation prompt
 * and enforced by the consistency check of § 7.4.
 *
 * German terms are compared binary: "Straße" and "Strasse" are different keys.
 */
#[ORM\Entity]
#[ORM\Table(name: 'glossary_term')]
#[ORM\UniqueConstraint(name: 'uniq_glossary_lang_term', columns: ['lang', 'term_de'])]
class GlossaryTerm
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 8, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $lang;

    #[ORM\Column(length: 128, options: ['collation' => 'utf8mb4_bin'])]
    private string $termDe;

    /** How the term appears in running text. */
    #[ORM\Column(length: 255)]
    private string $render;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $explanation = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $keepGerman = true;

    public function __construct(string $lang, string $termDe, string $render)
    {
        $this->lang = $lang;
        $this->termDe = $termDe;
        $this->render = $render;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function lang(): string
    {
        return $this->lang;
    }

    public function termDe(): string
    {
        return $this->termDe;
    }

    public function render(): string
    {
        return $this->render;
    }

    public function setRender(string $render): void
    {
        $this->render = $render;
    }

    public function explanation(): ?string
    {
        return $this->explanation;
    }

    public function setExplanation(?string $explanation): void
    {
        $this->explanation = $explanation;
    }

    public function keepsGerman(): bool
    {
        return $this->keepGerman;
    }

    public function setKeepGerman(bool $keepGerman): void
    {
        $this->keepGerman = $keepGerman;
    }
}
