<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\AssetQuantityChange;
use App\Repository\AssetSyncChangeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AssetSyncChangeRepository::class)]
#[ORM\Table(name: 'asset_sync_changes')]
#[ORM\Index(columns: ['character_id'])]
#[ORM\Index(columns: ['corporation_id'])]
class AssetSyncChange
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Character $character;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $corporationId;

    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $typeName;

    #[ORM\Column(type: 'bigint')]
    private int $locationId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $locationName;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $divisionName;

    #[ORM\Column(type: 'integer')]
    private int $quantityDelta;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $syncedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $previousSyncedAt;

    public function __construct(
        ?Character $character,
        ?int $corporationId,
        AssetQuantityChange $change,
        \DateTimeImmutable $syncedAt,
        \DateTimeImmutable $previousSyncedAt,
    ) {
        $this->character = $character;
        $this->corporationId = $corporationId;
        $this->typeId = $change->typeId;
        $this->typeName = $change->typeName;
        $this->locationId = $change->locationId;
        $this->locationName = $change->locationName;
        $this->divisionName = $change->divisionName;
        $this->quantityDelta = $change->quantityDelta;
        $this->syncedAt = $syncedAt;
        $this->previousSyncedAt = $previousSyncedAt;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getCharacter(): ?Character
    {
        return $this->character;
    }

    public function getCorporationId(): ?int
    {
        return $this->corporationId;
    }

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function getTypeName(): ?string
    {
        return $this->typeName;
    }

    public function getLocationId(): int
    {
        return $this->locationId;
    }

    public function getLocationName(): string
    {
        return $this->locationName;
    }

    public function getDivisionName(): ?string
    {
        return $this->divisionName;
    }

    public function getQuantityDelta(): int
    {
        return $this->quantityDelta;
    }

    public function getSyncedAt(): \DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function getPreviousSyncedAt(): \DateTimeImmutable
    {
        return $this->previousSyncedAt;
    }
}
