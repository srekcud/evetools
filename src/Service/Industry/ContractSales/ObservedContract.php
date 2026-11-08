<?php

declare(strict_types=1);

namespace App\Service\Industry\ContractSales;

final readonly class ObservedContract
{
    /**
     * @param list<array{typeId: int, quantity: int}> $items
     */
    public function __construct(
        public int $contractId,
        public int $issuerId,
        public float $price,
        public array $items,
        public \DateTimeImmutable $dateExpired,
    ) {
    }
}
