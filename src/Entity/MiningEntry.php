<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MiningEntryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MiningEntryRepository::class)]
#[ORM\Table(name: 'mining_entries')]
#[ORM\UniqueConstraint(columns: ['user_id', 'character_id', 'date', 'type_id', 'solar_system_id'])]
#[ORM\Index(columns: ['user_id', 'date'])]
#[ORM\Index(columns: ['user_id', 'usage'])]
class MiningEntry
{
    public const USAGE_UNKNOWN = 'unknown';
    public const USAGE_SOLD = 'sold';
    public const USAGE_CORP_PROJECT = 'corp_project';
    public const USAGE_INDUSTRY = 'industry';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'bigint')]
    private int $characterId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $characterName;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $typeName;

    #[ORM\Column(type: 'integer')]
    private int $solarSystemId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $solarSystemName;

    #[ORM\Column(type: 'bigint')]
    private int $quantity;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $unitPrice = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $totalValue = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $usage = self::USAGE_UNKNOWN;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $linkedProjectId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $linkedCorpProjectId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $syncedAt;

    public function __construct()
    {
        $this->syncedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getCharacterId(): int
    {
        return $this->characterId;
    }

    public function setCharacterId(int $characterId): static
    {
        $this->characterId = $characterId;
        return $this;
    }

    public function getCharacterName(): string
    {
        return $this->characterName;
    }

    public function setCharacterName(string $characterName): static
    {
        $this->characterName = $characterName;
        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;
        return $this;
    }

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function setTypeId(int $typeId): static
    {
        $this->typeId = $typeId;
        return $this;
    }

    public function getTypeName(): string
    {
        return $this->typeName;
    }

    public function setTypeName(string $typeName): static
    {
        $this->typeName = $typeName;
        return $this;
    }

    public function getSolarSystemId(): int
    {
        return $this->solarSystemId;
    }

    public function setSolarSystemId(int $solarSystemId): static
    {
        $this->solarSystemId = $solarSystemId;
        return $this;
    }

    public function getSolarSystemName(): string
    {
        return $this->solarSystemName;
    }

    public function setSolarSystemName(string $solarSystemName): static
    {
        $this->solarSystemName = $solarSystemName;
        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;
        $this->updateTotalValue();
        return $this;
    }

    public function getUnitPrice(): ?float
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(?float $unitPrice): static
    {
        $this->unitPrice = $unitPrice;
        $this->updateTotalValue();
        return $this;
    }

    public function getTotalValue(): ?float
    {
        return $this->totalValue;
    }

    public function getUsage(): string
    {
        return $this->usage;
    }

    public function setUsage(string $usage): static
    {
        $this->usage = $usage;
        return $this;
    }

    public function getLinkedProjectId(): ?string
    {
        return $this->linkedProjectId;
    }

    public function setLinkedProjectId(?string $linkedProjectId): static
    {
        $this->linkedProjectId = $linkedProjectId;
        return $this;
    }

    public function getLinkedCorpProjectId(): ?int
    {
        return $this->linkedCorpProjectId;
    }

    public function setLinkedCorpProjectId(?int $linkedCorpProjectId): static
    {
        $this->linkedCorpProjectId = $linkedCorpProjectId;
        return $this;
    }

    public function getSyncedAt(): \DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function setSyncedAt(\DateTimeImmutable $syncedAt): static
    {
        $this->syncedAt = $syncedAt;
        return $this;
    }

    private function updateTotalValue(): void
    {
        if ($this->unitPrice !== null) {
            $this->totalValue = $this->quantity * $this->unitPrice;
        }
    }
}
