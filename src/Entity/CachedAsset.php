<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CachedAssetRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CachedAssetRepository::class)]
#[ORM\Table(name: 'cached_assets')]
#[ORM\Index(columns: ['character_id'])]
#[ORM\Index(columns: ['corporation_id'])]
#[ORM\Index(columns: ['location_id'])]
#[ORM\Index(columns: ['type_id'])]
class CachedAsset
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'bigint')]
    private int $itemId;

    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $typeName;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    #[ORM\Column(type: 'bigint')]
    private int $locationId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $locationName;

    #[ORM\Column(type: 'string', length: 50)]
    private string $locationType;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $locationFlag = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $divisionName = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $solarSystemId = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $solarSystemName = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $itemName = null;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Character $character = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $corporationId = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isCorporationAsset = false;

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

    public function getItemId(): int
    {
        return $this->itemId;
    }

    public function setItemId(int $itemId): static
    {
        $this->itemId = $itemId;

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

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getLocationId(): int
    {
        return $this->locationId;
    }

    public function setLocationId(int $locationId): static
    {
        $this->locationId = $locationId;

        return $this;
    }

    public function getLocationName(): string
    {
        return $this->locationName;
    }

    public function setLocationName(string $locationName): static
    {
        $this->locationName = $locationName;

        return $this;
    }

    public function getLocationType(): string
    {
        return $this->locationType;
    }

    public function setLocationType(string $locationType): static
    {
        $this->locationType = $locationType;

        return $this;
    }

    public function getLocationFlag(): ?string
    {
        return $this->locationFlag;
    }

    public function setLocationFlag(?string $locationFlag): static
    {
        $this->locationFlag = $locationFlag;

        return $this;
    }

    public function getDivisionName(): ?string
    {
        return $this->divisionName;
    }

    public function setDivisionName(?string $divisionName): static
    {
        $this->divisionName = $divisionName;

        return $this;
    }

    public function getSolarSystemId(): ?int
    {
        return $this->solarSystemId;
    }

    public function setSolarSystemId(?int $solarSystemId): static
    {
        $this->solarSystemId = $solarSystemId;

        return $this;
    }

    public function getSolarSystemName(): ?string
    {
        return $this->solarSystemName;
    }

    public function setSolarSystemName(?string $solarSystemName): static
    {
        $this->solarSystemName = $solarSystemName;

        return $this;
    }

    public function getItemName(): ?string
    {
        return $this->itemName;
    }

    public function setItemName(?string $itemName): static
    {
        $this->itemName = $itemName;

        return $this;
    }

    public function getCharacter(): ?Character
    {
        return $this->character;
    }

    public function setCharacter(?Character $character): static
    {
        $this->character = $character;

        return $this;
    }

    public function getCorporationId(): ?int
    {
        return $this->corporationId;
    }

    public function setCorporationId(?int $corporationId): static
    {
        $this->corporationId = $corporationId;

        return $this;
    }

    public function isCorporationAsset(): bool
    {
        return $this->isCorporationAsset;
    }

    public function setIsCorporationAsset(bool $isCorporationAsset): static
    {
        $this->isCorporationAsset = $isCorporationAsset;

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
