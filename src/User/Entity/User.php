<?php

declare(strict_types=1);

namespace App\User\Entity;

use App\User\Enum\UserStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An account (SPEC.md § 10). Data minimisation: no name is required, the e-mail address and the
 * chosen language are all we need to deliver notifications.
 *
 * User data never enters git and is never sent to AI providers (SPEC.md § 1.1, § 16.2).
 * Security interfaces are added with the authentication system in M8; the mapping stays the same.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
#[ORM\Index(name: 'idx_user_status', columns: ['status'])]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    /** Null for accounts that only sign in with a magic link. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $password = null;

    /**
     * ROLE_USER, ROLE_EDITOR, ROLE_TRANSLATOR, ROLE_ADMIN. Translators are bound to languages
     * through UserTranslatorLanguage rather than per-language roles (SPEC.md § 24.15).
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column(length: 8, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $lang;

    #[ORM\Column(length: 16, enumType: UserStatus::class)]
    private UserStatus $status = UserStatus::Pending;

    /** Double opt-in is mandatory for mailings in Germany (SPEC.md § 10). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\OneToOne(targetEntity: UserProfile::class, mappedBy: 'user')]
    private ?UserProfile $profile = null;

    /** @var Collection<int, UserTranslatorLanguage> */
    #[ORM\OneToMany(targetEntity: UserTranslatorLanguage::class, mappedBy: 'user')]
    private Collection $translatorLanguages;

    public function __construct(string $email, string $lang)
    {
        $this->email = $email;
        $this->lang = $lang;
        $this->createdAt = new \DateTimeImmutable();
        $this->translatorLanguages = new ArrayCollection();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function password(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    public function lang(): string
    {
        return $this->lang;
    }

    public function setLang(string $lang): void
    {
        $this->lang = $lang;
    }

    public function status(): UserStatus
    {
        return $this->status;
    }

    public function setStatus(UserStatus $status): void
    {
        $this->status = $status;
    }

    public function emailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function confirmEmail(\DateTimeImmutable $at): void
    {
        $this->emailVerifiedAt = $at;
        if (UserStatus::Pending === $this->status) {
            $this->status = UserStatus::Active;
        }
    }

    public function isVerified(): bool
    {
        return $this->emailVerifiedAt instanceof \DateTimeImmutable;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function recordLogin(\DateTimeImmutable $at): void
    {
        $this->lastLoginAt = $at;
    }

    public function profile(): ?UserProfile
    {
        return $this->profile;
    }

    public function setProfile(?UserProfile $profile): void
    {
        $this->profile = $profile;
    }

    /**
     * @return Collection<int, UserTranslatorLanguage>
     */
    public function translatorLanguages(): Collection
    {
        return $this->translatorLanguages;
    }
}
