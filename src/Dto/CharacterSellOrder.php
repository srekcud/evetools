<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class CharacterSellOrder
{
    public function __construct(
        public int $orderId,
        public int $typeId,
        public int $locationId,
        public float $price,
        public int $volumeRemain,
        public int $volumeTotal,
        public \DateTimeImmutable $issuedAt,
    ) {
    }
}
