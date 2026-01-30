<?php

declare(strict_types=1);

namespace App\Entity;

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
    public const AUTH_STATUS_VALID = 'valid';
    public const AUTH_STATUS_INVALID = 'invalid';

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

    #[ORM\Column(type: 'string', length: 20)]
    private string $authStatus = self::AUTH_STATUS_VALID;

    /** @var int[] SDE group IDs blacklisted from industry production */
    #[ORM\Column(type: 'json')]
    private array $industryBlacklistGroupIds = [];

    /** @var int[] Individual type IDs blacklisted from industry production */
    #[ORM\Column(type: 'json')]
    private array $industryBlacklistTypeIds = [];

    /** Preferred structure ID for market price comparison */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $preferredMarketStructureId = null;

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

    public function getAuthStatus(): string
    {
        return $this->authStatus;
    }

    public function setAuthStatus(string $authStatus): static
    {
        $this->authStatus = $authStatus;

        return $this;
    }

    public function isAuthValid(): bool
    {
        return $this->authStatus === self::AUTH_STATUS_VALID;
    }

    public function markAuthInvalid(): static
    {
        $this->authStatus = self::AUTH_STATUS_INVALID;

        return $this;
    }

    public function markAuthValid(): static
    {
        $this->authStatus = self::AUTH_STATUS_VALID;

        return $this;
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

    public function getCorporationId(): ?int
    {
        return $this->mainCharacter?->getCorporationId();
    }

    public function getCorporationName(): ?string
    {
        return $this->mainCharacter?->getCorporationName();
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
        return $this->id?->toRfc4122() ?? '';
    }
}
