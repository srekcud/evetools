<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

use App\Enum\MarketSide;
use App\Service\OreValueService;

final readonly class MoonTaxUnitPrices
{
    private const float UNUSED_REPROCESS_YIELD = 1.0;
    private const float NO_EXPORT_TAX = 0.0;
    private const string PRICE_KEY = 'compressedEquivalentPrice';

    public function __construct(private OreValueService $oreValueService)
    {
    }

    /**
     * @param list<int> $typeIds
     *
     * @return array<int, ?float>
     */
    public function read(array $typeIds): array
    {
        $prices = $this->oreValueService->getBatchOrePrices($typeIds, null, self::UNUSED_REPROCESS_YIELD, self::NO_EXPORT_TAX, MarketSide::Buy);

        $unitPricesByTypeId = [];
        foreach ($typeIds as $typeId) {
            $unitPrice = $prices[$typeId][self::PRICE_KEY];
            $unitPricesByTypeId[$typeId] = $unitPrice === null ? null : (float) $unitPrice;
        }

        return $unitPricesByTypeId;
    }
}
