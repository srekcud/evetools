<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CachedCharacterSkillRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CachedCharacterSkillRepository::class)]
#[ORM\Table(name: 'cached_character_skills')]
#[ORM\UniqueConstraint(columns: ['character_id', 'skill_id'])]
#[ORM\Index(columns: ['character_id'])]
class CachedCharacterSkill
{
    /** Industry skill type IDs */
    public const SKILL_INDUSTRY = 3380;
    public const SKILL_ADVANCED_INDUSTRY = 3388;
    public const SKILL_REACTIONS = 45746;

    public const INDUSTRY_SKILL_IDS = [
        self::SKILL_INDUSTRY,
        self::SKILL_ADVANCED_INDUSTRY,
        self::SKILL_REACTIONS,
    ];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Character $character;

    #[ORM\Column(type: 'integer')]
    private int $skillId;

    #[ORM\Column(type: 'integer')]
    private int $level = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $isManual = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $cachedAt;

    public function __construct()
    {
        $this->cachedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function setCharacter(Character $character): static
    {
        $this->character = $character;
        return $this;
    }

    public function getSkillId(): int
    {
        return $this->skillId;
    }

    public function setSkillId(int $skillId): static
    {
        $this->skillId = $skillId;
        return $this;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): static
    {
        $this->level = max(0, min(5, $level));
        return $this;
    }

    public function isManual(): bool
    {
        return $this->isManual;
    }

    public function setIsManual(bool $isManual): static
    {
        $this->isManual = $isManual;
        return $this;
    }

    public function getCachedAt(): \DateTimeImmutable
    {
        return $this->cachedAt;
    }

    public function setCachedAt(\DateTimeImmutable $cachedAt): static
    {
        $this->cachedAt = $cachedAt;
        return $this;
    }
}
