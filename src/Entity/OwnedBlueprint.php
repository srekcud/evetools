<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OwnedBlueprintRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OwnedBlueprintRepository::class)]
#[ORM\Table(name: 'owned_blueprints')]
#[ORM\Index(columns: ['character_id'])]
#[ORM\Index(columns: ['corporation_id'])]
#[ORM\Index(columns: ['type_id'])]
class OwnedBlueprint
{
    private const int COPY_QUANTITY = -2;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'bigint')]
    private int $itemId;

    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Column(type: 'bigint')]
    private int $locationId;

    #[ORM\Column(type: 'string', length: 50)]
    private string $locationFlag;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    #[ORM\Column(type: 'integer')]
    private int $materialEfficiency;

    #[ORM\Column(type: 'integer')]
    private int $timeEfficiency;

    #[ORM\Column(type: 'integer')]
    private int $runs;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Character $character = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $corporationId = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $divisionNumber = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $syncedAt;

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

    public function getLocationId(): int
    {
        return $this->locationId;
    }

    public function setLocationId(int $locationId): static
    {
        $this->locationId = $locationId;

        return $this;
    }

    public function getLocationFlag(): string
    {
        return $this->locationFlag;
    }

    public function setLocationFlag(string $locationFlag): static
    {
        $this->locationFlag = $locationFlag;

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

    public function getMaterialEfficiency(): int
    {
        return $this->materialEfficiency;
    }

    public function setMaterialEfficiency(int $materialEfficiency): static
    {
        $this->materialEfficiency = $materialEfficiency;

        return $this;
    }

    public function getTimeEfficiency(): int
    {
        return $this->timeEfficiency;
    }

    public function setTimeEfficiency(int $timeEfficiency): static
    {
        $this->timeEfficiency = $timeEfficiency;

        return $this;
    }

    public function getRuns(): int
    {
        return $this->runs;
    }

    public function setRuns(int $runs): static
    {
        $this->runs = $runs;

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

    public function getDivisionNumber(): ?int
    {
        return $this->divisionNumber;
    }

    public function setDivisionNumber(?int $divisionNumber): static
    {
        $this->divisionNumber = $divisionNumber;

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

    public function isOriginal(): bool
    {
        return $this->quantity !== self::COPY_QUANTITY;
    }

    public function isCopy(): bool
    {
        return $this->quantity === self::COPY_QUANTITY;
    }
}
