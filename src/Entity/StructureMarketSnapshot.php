<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StructureMarketSnapshotRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: StructureMarketSnapshotRepository::class)]
#[ORM\Table(name: 'structure_market_snapshots')]
#[ORM\UniqueConstraint(columns: ['structure_id', 'type_id', 'date'])]
#[ORM\Index(columns: ['structure_id', 'type_id'])]
#[ORM\Index(columns: ['date'])]
class StructureMarketSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'bigint')]
    private int $structureId;

    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $sellMin = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $buyMax = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $sellOrderCount = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $buyOrderCount = 0;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $sellVolume = 0;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $buyVolume = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getStructureId(): int
    {
        return $this->structureId;
    }

    public function setStructureId(int $structureId): static
    {
        $this->structureId = $structureId;
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

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;
        return $this;
    }

    public function getSellMin(): ?float
    {
        return $this->sellMin;
    }

    public function setSellMin(?float $sellMin): static
    {
        $this->sellMin = $sellMin;
        return $this;
    }

    public function getBuyMax(): ?float
    {
        return $this->buyMax;
    }

    public function setBuyMax(?float $buyMax): static
    {
        $this->buyMax = $buyMax;
        return $this;
    }

    public function getSellOrderCount(): int
    {
        return $this->sellOrderCount;
    }

    public function setSellOrderCount(int $sellOrderCount): static
    {
        $this->sellOrderCount = $sellOrderCount;
        return $this;
    }

    public function getBuyOrderCount(): int
    {
        return $this->buyOrderCount;
    }

    public function setBuyOrderCount(int $buyOrderCount): static
    {
        $this->buyOrderCount = $buyOrderCount;
        return $this;
    }

    public function getSellVolume(): int
    {
        return $this->sellVolume;
    }

    public function setSellVolume(int $sellVolume): static
    {
        $this->sellVolume = $sellVolume;
        return $this;
    }

    public function getBuyVolume(): int
    {
        return $this->buyVolume;
    }

    public function setBuyVolume(int $buyVolume): static
    {
        $this->buyVolume = $buyVolume;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
