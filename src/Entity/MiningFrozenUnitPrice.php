<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'mining_frozen_unit_prices')]
#[ORM\UniqueConstraint(columns: ['operation_id', 'type_id'])]
class MiningFrozenUnitPrice
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: MiningOperation::class, inversedBy: 'frozenUnitPrices')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private MiningOperation $operation,
        #[ORM\Column(type: 'integer')]
        private int $typeId,
        #[ORM\Column(type: 'float')]
        private float $unitPrice,
    ) {
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getOperation(): MiningOperation
    {
        return $this->operation;
    }

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function getUnitPrice(): float
    {
        return $this->unitPrice;
    }
}
