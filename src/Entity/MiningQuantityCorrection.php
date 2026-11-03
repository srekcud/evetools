<?php

declare(strict_types=1);

namespace App\Entity;

use Symfony\Component\Uid\Uuid;

class MiningQuantityCorrection
{
    private ?Uuid $id = null;

    public function __construct(
        private MiningOperationCharacter $operationCharacter,
        private int $typeId,
        private ?int $originalQuantity,
        private int $correctedQuantity,
        private ?User $correctedBy,
        private ?string $correctedByName,
        private \DateTimeImmutable $correctedAt,
    ) {
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getOperationCharacter(): MiningOperationCharacter
    {
        return $this->operationCharacter;
    }

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function getOriginalQuantity(): ?int
    {
        return $this->originalQuantity;
    }

    public function getCorrectedQuantity(): int
    {
        return $this->correctedQuantity;
    }

    public function getCorrectedBy(): ?User
    {
        return $this->correctedBy;
    }

    public function getCorrectedByName(): ?string
    {
        return $this->correctedByName;
    }

    public function getCorrectedAt(): \DateTimeImmutable
    {
        return $this->correctedAt;
    }
}
