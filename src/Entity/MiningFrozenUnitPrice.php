<?php

declare(strict_types=1);

namespace App\Entity;

use Symfony\Component\Uid\Uuid;

class MiningFrozenUnitPrice
{
    private ?Uuid $id = null;

    public function __construct(
        private MiningOperation $operation,
        private int $typeId,
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
