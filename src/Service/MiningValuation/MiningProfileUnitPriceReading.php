<?php

declare(strict_types=1);

namespace App\Service\MiningValuation;

final readonly class MiningProfileUnitPriceReading
{
    /**
     * @param array<int, ?float> $unitPricesByTypeId
     * @param array<int, array<string, mixed>> $pricesByTypeId
     */
    public function __construct(
        public array $unitPricesByTypeId,
        public array $pricesByTypeId,
        public ValuationSource $valuationSource,
        public ?\DateTimeImmutable $pricesSyncedAt,
    ) {
    }
}
