<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MoonObserverRecordRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MoonObserverRecordRepository::class)]
#[ORM\Table(name: 'moon_observer_records')]
#[ORM\UniqueConstraint(columns: ['observer_id', 'character_id', 'type_id', 'last_updated'])]
#[ORM\Index(columns: ['corporation_id', 'last_updated'])]
class MoonObserverRecord
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'bigint')]
    private int $corporationId;

    #[ORM\Column(type: 'bigint')]
    private int $observerId;

    #[ORM\Column(type: 'bigint')]
    private int $characterId;

    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $lastUpdated;

    #[ORM\Column(type: 'bigint')]
    private int $quantity;

    #[ORM\Column(type: 'bigint')]
    private int $recordedCorporationId;

    public function getId(): ?Uuid
    {
        return $this->id;
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

    public function getObserverId(): int
    {
        return $this->observerId;
    }

    public function setObserverId(int $observerId): static
    {
        $this->observerId = $observerId;

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

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function setTypeId(int $typeId): static
    {
        $this->typeId = $typeId;

        return $this;
    }

    public function getLastUpdated(): \DateTimeImmutable
    {
        return $this->lastUpdated;
    }

    public function setLastUpdated(\DateTimeImmutable $lastUpdated): static
    {
        $this->lastUpdated = $lastUpdated;

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

    public function getRecordedCorporationId(): int
    {
        return $this->recordedCorporationId;
    }

    public function setRecordedCorporationId(int $recordedCorporationId): static
    {
        $this->recordedCorporationId = $recordedCorporationId;

        return $this;
    }
}
