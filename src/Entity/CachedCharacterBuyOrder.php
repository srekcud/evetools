<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CachedCharacterBuyOrderRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CachedCharacterBuyOrderRepository::class)]
#[ORM\Table(name: 'cached_character_buy_orders')]
#[ORM\Index(columns: ['character_id'])]
#[ORM\Index(columns: ['type_id'])]
#[ORM\UniqueConstraint(columns: ['order_id'])]
class CachedCharacterBuyOrder
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Character $character;

    #[ORM\Column(type: 'bigint')]
    private int $orderId;

    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Column(type: 'bigint')]
    private int $locationId;

    #[ORM\Column(type: 'float')]
    private float $price;

    #[ORM\Column(type: 'integer')]
    private int $volumeRemain;

    #[ORM\Column(type: 'integer')]
    private int $volumeTotal;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $issuedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $syncedAt;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function setCharacter(Character $character): static
    {
        $this->character = $character;

        return $this;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function setOrderId(int $orderId): static
    {
        $this->orderId = $orderId;

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

    public function getPrice(): float
    {
        return $this->price;
    }

    public function setPrice(float $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getVolumeRemain(): int
    {
        return $this->volumeRemain;
    }

    public function setVolumeRemain(int $volumeRemain): static
    {
        $this->volumeRemain = $volumeRemain;

        return $this;
    }

    public function getVolumeTotal(): int
    {
        return $this->volumeTotal;
    }

    public function setVolumeTotal(int $volumeTotal): static
    {
        $this->volumeTotal = $volumeTotal;

        return $this;
    }

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function setIssuedAt(\DateTimeImmutable $issuedAt): static
    {
        $this->issuedAt = $issuedAt;

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
}
