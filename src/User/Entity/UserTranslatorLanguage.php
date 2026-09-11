<?php

declare(strict_types=1);

namespace App\User\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Binds a translator to the languages they may review (SPEC.md § 24.15).
 *
 * There is one ROLE_TRANSLATOR; languages are data, so adding a language needs no code change.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_translator_language')]
#[ORM\UniqueConstraint(name: 'uniq_user_translator_language', columns: ['user_id', 'lang'])]
class UserTranslatorLanguage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'translatorLanguages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 8, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $lang;

    public function __construct(User $user, string $lang)
    {
        $this->user = $user;
        $this->lang = $lang;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function lang(): string
    {
        return $this->lang;
    }
}
