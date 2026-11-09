<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Contract;

final readonly class ContractSaleCandidate
{
    public function __construct(
        public int $contractId,
        public int $typeId,
        public string $typeName,
        public int $quantity,
        public float $price,
        public \DateTimeImmutable $completedAt,
        public int $sellerEveCharacterId,
        public string $sellerCharacterName,
    ) {
    }

    public function unitPrice(): float
    {
        return $this->price / $this->quantity;
    }
}
