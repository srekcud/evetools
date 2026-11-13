<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AuthStatus;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    /** @var Collection<int, Character> */
    #[ORM\OneToMany(targetEntity: Character::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $characters;

    #[ORM\OneToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Character $mainCharacter = null;

    /** @var int[] SDE group IDs blacklisted from industry production */
    #[ORM\Column(type: 'json')]
    private array $industryBlacklistGroupIds = [];

    /** @var int[] Individual type IDs blacklisted from industry production */
    #[ORM\Column(type: 'json')]
    private array $industryBlacklistTypeIds = [];

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $industryExcludeInvention = false;

    /** Preferred structure ID for market price comparison */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $preferredMarketStructureId = null;

    /** Preferred structure name (display only) */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $preferredMarketStructureName = null;

    /** @var list<array{id: int, name: string}> User's favorite market structures */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $marketStructures = [];

    /** @var array<string, int> Default line rental rates per category */
    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $lineRentalRates = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    public function __construct()
    {
        $this->characters = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    /**
     * @return Collection<int, Character>
     */
    public function getCharacters(): Collection
    {
        return $this->characters;
    }

    /**
     * @return list<int>
     */
    public function getCharacterCorporationIds(): array
    {
        $corporationIds = array_unique($this->characters->map(
            static fn (Character $character): int => $character->getCorporationId(),
        )->toArray());
        sort($corporationIds);

        return $corporationIds;
    }

    /**
     * @return list<int>
     */
    public function getPlayerCorporationIds(): array
    {
        return array_values(array_filter(
            $this->getCharacterCorporationIds(),
            static fn (int $corporationId): bool => $corporationId >= Character::FIRST_PLAYER_CORPORATION_ID,
        ));
    }

    public function addCharacter(Character $character): static
    {
        if (!$this->characters->contains($character)) {
            $this->characters->add($character);
            $character->setUser($this);
        }

        return $this;
    }

    public function removeCharacter(Character $character): static
    {
        if ($this->characters->removeElement($character)) {
            if ($character->getUser() === $this) {
                $character->setUser(null);
            }
        }

        return $this;
    }

    public function getMainCharacter(): ?Character
    {
        return $this->mainCharacter;
    }

    public function setMainCharacter(?Character $mainCharacter): static
    {
        $this->mainCharacter = $mainCharacter;

        return $this;
    }

    public function getAuthStatus(): AuthStatus
    {
        foreach ($this->characters as $character) {
            if (!$character->isAuthValid()) {
                return AuthStatus::Invalid;
            }
        }

        return AuthStatus::Valid;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;

        return $this;
    }

    public function updateLastLogin(): static
    {
        $this->lastLoginAt = new \DateTimeImmutable();

        return $this;
    }

    public function isActive(int $days = 7): bool
    {
        if ($this->lastLoginAt === null) {
            return false;
        }

        $threshold = new \DateTimeImmutable("-{$days} days");

        return $this->lastLoginAt >= $threshold;
    }

    public function getCorporationId(): ?int
    {
        return $this->mainCharacter?->getCorporationId();
    }

    public function getCorporationName(): ?string
    {
        return $this->mainCharacter?->getCorporationName();
    }

    public function getAllianceId(): ?int
    {
        return $this->mainCharacter?->getAllianceId();
    }

    /** @return int[] */
    public function getIndustryBlacklistGroupIds(): array
    {
        return $this->industryBlacklistGroupIds;
    }

    /** @param int[] $ids */
    public function setIndustryBlacklistGroupIds(array $ids): static
    {
        $this->industryBlacklistGroupIds = $ids;
        return $this;
    }

    public function isIndustryExcludeInvention(): bool
    {
        return $this->industryExcludeInvention;
    }

    public function setIndustryExcludeInvention(bool $industryExcludeInvention): static
    {
        $this->industryExcludeInvention = $industryExcludeInvention;
        return $this;
    }

    /** @return int[] */
    public function getIndustryBlacklistTypeIds(): array
    {
        return $this->industryBlacklistTypeIds;
    }

    /** @param int[] $ids */
    public function setIndustryBlacklistTypeIds(array $ids): static
    {
        $this->industryBlacklistTypeIds = $ids;
        return $this;
    }

    public function getPreferredMarketStructureId(): ?int
    {
        return $this->preferredMarketStructureId;
    }

    public function setPreferredMarketStructureId(?int $structureId): static
    {
        $this->preferredMarketStructureId = $structureId;
        return $this;
    }

    public function getPreferredMarketStructureName(): ?string
    {
        return $this->preferredMarketStructureName;
    }

    public function setPreferredMarketStructureName(?string $name): static
    {
        $this->preferredMarketStructureName = $name;
        return $this;
    }

    public function getEffectiveMarketStructureId(): ?int
    {
        return $this->preferredMarketStructureId ?? ($this->marketStructures[0]['id'] ?? null);
    }

    public function getEffectiveMarketStructureName(): ?string
    {
        if (null !== $this->preferredMarketStructureId) {
            return $this->preferredMarketStructureName;
        }

        return $this->marketStructures[0]['name'] ?? null;
    }

    public function followsMarketStructure(int $structureId): bool
    {
        return $this->preferredMarketStructureId === $structureId
            || \in_array($structureId, array_column($this->marketStructures, 'id'), true);
    }

    /** @return list<array{id: int, name: string}> */
    public function getMarketStructures(): array
    {
        return $this->marketStructures;
    }

    /** @param list<array{id: int, name: string}> $structures */
    public function setMarketStructures(array $structures): static
    {
        $this->marketStructures = $structures;
        return $this;
    }

    public function addMarketStructure(int $id, string $name): static
    {
        foreach ($this->marketStructures as $s) {
            if ($s['id'] === $id) {
                return $this;
            }
        }
        $this->marketStructures[] = ['id' => $id, 'name' => $name];
        return $this;
    }

    public function removeMarketStructure(int $id): static
    {
        $this->marketStructures = array_values(array_filter(
            $this->marketStructures,
            static fn (array $s) => $s['id'] !== $id,
        ));
        return $this;
    }

    /** @return array<string, int> */
    public function getLineRentalRates(): array
    {
        return $this->lineRentalRates;
    }

    /** @param array<string, int> $rates */
    public function setLineRentalRates(array $rates): static
    {
        $this->lineRentalRates = $rates;
        return $this;
    }

    // UserInterface implementation

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
        // No credentials to erase
    }

    public function getUserIdentifier(): string
    {
        /** @var non-empty-string */
        return $this->id?->toRfc4122() ?? 'anonymous';
    }
}
