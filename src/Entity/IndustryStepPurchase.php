<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndustryStepPurchaseRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IndustryStepPurchaseRepository::class)]
#[ORM\Table(name: 'industry_step_purchases')]
#[ORM\Index(columns: ['step_id'])]
#[ORM\Index(columns: ['type_id'])]
class IndustryStepPurchase
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: IndustryProjectStep::class, inversedBy: 'purchases')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IndustryProjectStep $step;

    #[ORM\ManyToOne(targetEntity: CachedWalletTransaction::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CachedWalletTransaction $transaction = null;

    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    #[ORM\Column(type: 'float')]
    private float $unitPrice;

    #[ORM\Column(type: 'float')]
    private float $totalPrice;

    /** 'esi_wallet' or 'manual' */
    #[ORM\Column(type: 'string', length: 20)]
    private string $source;

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

    public function getStep(): IndustryProjectStep
    {
        return $this->step;
    }

    public function setStep(IndustryProjectStep $step): static
    {
        $this->step = $step;
        return $this;
    }

    public function getTransaction(): ?CachedWalletTransaction
    {
        return $this->transaction;
    }

    public function setTransaction(?CachedWalletTransaction $transaction): static
    {
        $this->transaction = $transaction;
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

    public function getTotalPrice(): float
    {
        return $this->totalPrice;
    }

    public function setTotalPrice(float $totalPrice): static
    {
        $this->totalPrice = $totalPrice;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
