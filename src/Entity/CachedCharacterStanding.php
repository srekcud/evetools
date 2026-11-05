<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CachedCharacterStandingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CachedCharacterStandingRepository::class)]
#[ORM\Table(name: 'cached_character_standings')]
#[ORM\UniqueConstraint(columns: ['character_id', 'from_type', 'from_id'])]
#[ORM\Index(columns: ['character_id'])]
class CachedCharacterStanding
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
    private int $fromId;

    #[ORM\Column(type: 'string', length: 20)]
    private string $fromType;

    #[ORM\Column(type: 'float')]
    private float $standing;

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

    public function getFromId(): int
    {
        return $this->fromId;
    }

    public function setFromId(int $fromId): static
    {
        $this->fromId = $fromId;

        return $this;
    }

    public function getFromType(): string
    {
        return $this->fromType;
    }

    public function setFromType(string $fromType): static
    {
        $this->fromType = $fromType;

        return $this;
    }

    public function getStanding(): float
    {
        return $this->standing;
    }

    public function setStanding(float $standing): static
    {
        $this->standing = $standing;

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
