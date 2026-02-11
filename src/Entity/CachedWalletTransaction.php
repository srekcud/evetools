<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CachedWalletTransactionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CachedWalletTransactionRepository::class)]
#[ORM\Table(name: 'cached_wallet_transactions')]
#[ORM\UniqueConstraint(columns: ['transaction_id'])]
#[ORM\Index(columns: ['character_id'])]
#[ORM\Index(columns: ['type_id'])]
#[ORM\Index(columns: ['date'])]
class CachedWalletTransaction
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
    private int $transactionId;

    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    #[ORM\Column(type: 'float')]
    private float $unitPrice;

    #[ORM\Column(type: 'boolean')]
    private bool $isBuy;

    #[ORM\Column(type: 'bigint')]
    private int $locationId;

    #[ORM\Column(type: 'bigint')]
    private int $clientId;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $date;

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

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function setCharacter(Character $character): static
    {
        $this->character = $character;
        return $this;
    }

    public function getTransactionId(): int
    {
        return $this->transactionId;
    }

    public function setTransactionId(int $transactionId): static
    {
        $this->transactionId = $transactionId;
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

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function getUnitPrice(): float
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(float $unitPrice): static
    {
        $this->unitPrice = $unitPrice;
        return $this;
    }

    public function isBuy(): bool
    {
        return $this->isBuy;
    }

    public function setIsBuy(bool $isBuy): static
    {
        $this->isBuy = $isBuy;
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

    public function getClientId(): int
    {
        return $this->clientId;
    }

    public function setClientId(int $clientId): static
    {
        $this->clientId = $clientId;
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

    public function getCachedAt(): \DateTimeImmutable
    {
        return $this->cachedAt;
    }

    public function getTotalPrice(): float
    {
        return $this->unitPrice * $this->quantity;
    }
}
