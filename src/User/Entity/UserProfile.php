<?php

declare(strict_types=1);

namespace App\User\Entity;

use App\User\Doctrine\EncryptedJsonType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a person told us about their situation (SPEC.md § 9, § 10).
 *
 * Tags — residence status above all — are sensitive, so they are encrypted at application level.
 * Land, language and topics stay readable to let SQL narrow the audience down before the encrypted
 * tags are matched in PHP in batches (SPEC.md § 24.8).
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_profile')]
#[ORM\UniqueConstraint(name: 'uniq_user_profile_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_user_profile_land', columns: ['land'])]
class UserProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'profile')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Federal state code; null means "not answered" — such a person can only be a possible match. */
    #[ORM\Column(length: 8, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $land = null;

    /**
     * Taxonomy tag keys, encrypted at rest.
     *
     * @var list<string>
     */
    #[ORM\Column(type: EncryptedJsonType::NAME, nullable: true)]
    private ?array $tags = [];

    /**
     * Topics of interest; not sensitive on their own and needed for SQL pre-filtering.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $topics = [];

    /**
     * Which onboarding groups the person actually answered. A group left unanswered can never yield
     * a direct match (SPEC.md § 24.8).
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $answeredGroups = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function land(): ?string
    {
        return $this->land;
    }

    public function setLand(?string $land): void
    {
        $this->land = $land;
        $this->touch();
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return $this->tags ?? [];
    }

    /**
     * @param list<string> $tags
     */
    public function setTags(array $tags): void
    {
        $this->tags = array_values(array_unique($tags));
        $this->touch();
    }

    public function hasTag(string $tag): bool
    {
        return \in_array($tag, $this->tags ?? [], true);
    }

    /**
     * @return list<string>
     */
    public function topics(): array
    {
        return $this->topics;
    }

    /**
     * @param list<string> $topics
     */
    public function setTopics(array $topics): void
    {
        $this->topics = array_values(array_unique($topics));
        $this->touch();
    }

    /**
     * @return list<string>
     */
    public function answeredGroups(): array
    {
        return $this->answeredGroups;
    }

    /**
     * @param list<string> $groups
     */
    public function setAnsweredGroups(array $groups): void
    {
        $this->answeredGroups = array_values(array_unique($groups));
        $this->touch();
    }

    public function hasAnswered(string $group): bool
    {
        return \in_array($group, $this->answeredGroups, true);
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Hard deletion of the personal part, used by the GDPR delete flow (SPEC.md § 10). */
    public function erase(): void
    {
        $this->tags = null;
        $this->land = null;
        $this->topics = [];
        $this->answeredGroups = [];
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
