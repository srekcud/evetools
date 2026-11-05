<?php

declare(strict_types=1);

namespace App\Service\Industry\SellMarkets;

final readonly class SellMarketComparisonResult
{
    /**
     * @param list<SellMarketRow> $markets
     */
    public function __construct(
        public int $typeId,
        public int $quantity,
        public array $markets,
        public ImmediateSale $immediateSale,
    ) {
    }
}
