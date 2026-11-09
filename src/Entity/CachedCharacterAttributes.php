<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CachedCharacterAttributesRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CachedCharacterAttributesRepository::class)]
#[ORM\Table(name: 'cached_character_attributes')]
#[ORM\UniqueConstraint(columns: ['character_id'])]
class CachedCharacterAttributes
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Character $character;

    #[ORM\Column(type: 'integer')]
    private int $intelligence;

    #[ORM\Column(type: 'integer')]
    private int $memory;

    #[ORM\Column(type: 'integer')]
    private int $perception;

    #[ORM\Column(type: 'integer')]
    private int $willpower;

    #[ORM\Column(type: 'integer')]
    private int $charisma;

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

    public function getIntelligence(): int
    {
        return $this->intelligence;
    }

    public function getMemory(): int
    {
        return $this->memory;
    }

    public function getPerception(): int
    {
        return $this->perception;
    }

    public function getWillpower(): int
    {
        return $this->willpower;
    }

    public function getCharisma(): int
    {
        return $this->charisma;
    }

    public function setAttributes(int $intelligence, int $memory, int $perception, int $willpower, int $charisma): static
    {
        $this->intelligence = $intelligence;
        $this->memory = $memory;
        $this->perception = $perception;
        $this->willpower = $willpower;
        $this->charisma = $charisma;

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
