<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Service\JitaMarketService;
use App\Service\OreValueService;
use App\Service\StructureMarketService;

final readonly class MiningOperationUnitPrices
{
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
        return new MiningUnitPriceReading([], null);
    }
}
