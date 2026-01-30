<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CharacterRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CharacterRepository::class)]
#[ORM\Table(name: 'characters')]
#[ORM\UniqueConstraint(columns: ['eve_character_id'])]
class Character
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'bigint')]
    private int $eveCharacterId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'bigint')]
    private int $corporationId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $corporationName;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $allianceId = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $allianceName = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'characters')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\OneToOne(targetEntity: EveToken::class, mappedBy: 'character', cascade: ['persist', 'remove'])]
    private ?EveToken $eveToken = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSyncAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getEveCharacterId(): int
    {
        return $this->eveCharacterId;
    }

    public function setEveCharacterId(int $eveCharacterId): static
    {
        $this->eveCharacterId = $eveCharacterId;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCorporationId(): int
    {
        return $this->corporationId;
    }

    public function setCorporationId(int $corporationId): static
    {
        $this->corporationId = $corporationId;

        return $this;
    }

    public function getCorporationName(): string
    {
        return $this->corporationName;
    }

    public function setCorporationName(string $corporationName): static
    {
        $this->corporationName = $corporationName;

        return $this;
    }

    public function getAllianceId(): ?int
    {
        return $this->allianceId;
    }

    public function setAllianceId(?int $allianceId): static
    {
        $this->allianceId = $allianceId;

        return $this;
    }

    public function getAllianceName(): ?string
    {
        return $this->allianceName;
    }

    public function setAllianceName(?string $allianceName): static
    {
        $this->allianceName = $allianceName;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getEveToken(): ?EveToken
    {
        return $this->eveToken;
    }

    public function setEveToken(?EveToken $eveToken): static
    {
        // Unset the owning side of the relation if necessary
        if ($eveToken === null && $this->eveToken !== null) {
            $this->eveToken->setCharacter(null);
        }

        // Set the owning side of the relation if necessary
        if ($eveToken !== null && $eveToken->getCharacter() !== $this) {
            $eveToken->setCharacter($this);
        }

        $this->eveToken = $eveToken;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSyncAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncAt;
    }

    public function setLastSyncAt(?\DateTimeImmutable $lastSyncAt): static
    {
        $this->lastSyncAt = $lastSyncAt;

        return $this;
    }

    public function updateLastSync(): static
    {
        $this->lastSyncAt = new \DateTimeImmutable();

        return $this;
    }

    public function isMain(): bool
    {
        return $this->user?->getMainCharacter() === $this;
    }

    public function isInSameCorporation(Character $other): bool
    {
        return $this->corporationId === $other->getCorporationId();
    }
}
