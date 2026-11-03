<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Enum\MiningValuationMarket;
use App\Service\JitaMarketService;
use App\Service\OreValueService;
use App\Service\StructureMarketService;

final readonly class MiningOperationUnitPrices
{
    private const float UNUSED_REPROCESS_YIELD = 1.0;
    private const float NO_EXPORT_TAX = 0.0;

    public function __construct(
        private OreValueService $oreValueService,
        private JitaMarketService $jitaMarketService,
        private StructureMarketService $structureMarketService,
    ) {
    }

    /**
     * @param list<int> $typeIds
     */
    public function read(MiningOperation $operation, array $typeIds): MiningUnitPriceReading
    {
        $market = $operation->getValuationMarket();
        $structureId = $market === MiningValuationMarket::Structure ? $operation->getValuationStructureId() : null;

        $prices = $this->oreValueService->getBatchOrePrices(
            $typeIds,
            $structureId,
            $operation->getReprocessYield() ?? self::UNUSED_REPROCESS_YIELD,
            self::NO_EXPORT_TAX,
            $operation->getValuationSide(),
        );

        $priceKey = $operation->getValuationForm()->priceKeyOn($market);
        $unitPricesByTypeId = [];
        foreach ($typeIds as $typeId) {
            $unitPrice = $prices[$typeId][$priceKey];
            $unitPricesByTypeId[$typeId] = $unitPrice === null ? null : (float) $unitPrice;
        }

        return new MiningUnitPriceReading(
            $unitPricesByTypeId,
            $structureId === null ? $this->jitaMarketService->getLastSyncTime() : $this->structureMarketService->getLastSyncTime($structureId),
        );
    }
}
