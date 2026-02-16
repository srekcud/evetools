<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MarketPriceHistoryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MarketPriceHistoryRepository::class)]
#[ORM\Table(name: 'market_price_history')]
#[ORM\UniqueConstraint(columns: ['type_id', 'region_id', 'date'])]
#[ORM\Index(columns: ['date'])]
#[ORM\Index(columns: ['type_id'])]
class MarketPriceHistory
{
    public const DEFAULT_REGION_ID = 10000002; // The Forge

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Column(type: 'integer')]
    private int $regionId = self::DEFAULT_REGION_ID;

    #[ORM\Column(type: 'date')]
    private \DateTimeInterface $date;

    #[ORM\Column(type: 'float')]
    private float $average;

    #[ORM\Column(type: 'float')]
    private float $highest;

    #[ORM\Column(type: 'float')]
    private float $lowest;

    #[ORM\Column(type: 'integer')]
    private int $orderCount;

    #[ORM\Column(type: 'bigint')]
    private int $volume;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
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

    public function getRegionId(): int
    {
        return $this->regionId;
    }

    public function setRegionId(int $regionId): static
    {
        $this->regionId = $regionId;
        return $this;
    }

    public function getDate(): \DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(\DateTimeInterface $date): static
    {
        $this->date = $date;
        return $this;
    }

    public function getAverage(): float
    {
        return $this->average;
    }

    public function setAverage(float $average): static
    {
        $this->average = $average;
        return $this;
    }

    public function getHighest(): float
    {
        return $this->highest;
    }

    public function setHighest(float $highest): static
    {
        $this->highest = $highest;
        return $this;
    }

    public function getLowest(): float
    {
        return $this->lowest;
    }

    public function setLowest(float $lowest): static
    {
        $this->lowest = $lowest;
        return $this;
    }

    public function getOrderCount(): int
    {
        return $this->orderCount;
    }

    public function setOrderCount(int $orderCount): static
    {
        $this->orderCount = $orderCount;
        return $this;
    }

    public function getVolume(): int
    {
        return $this->volume;
    }

    public function setVolume(int $volume): static
    {
        $this->volume = $volume;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
