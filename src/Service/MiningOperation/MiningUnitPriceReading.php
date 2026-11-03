<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningUnitPriceReading
{
    /**
     * @param array<int, ?float> $unitPricesByTypeId
     */
    public function __construct(
        public array $unitPricesByTypeId,
        public ?\DateTimeImmutable $pricesSyncedAt,
    ) {
    }
}
