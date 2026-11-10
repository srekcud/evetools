<?php

declare(strict_types=1);

namespace App\Service\MiningValuation;

use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningValuationForm;
use App\Enum\MiningValuationMarket;
use App\Repository\ReprocessingProfileRepository;
use App\Service\JitaMarketService;
use App\Service\OreValueService;
use App\Service\StructureMarketService;

final readonly class MiningProfileUnitPrices
{
    public function __construct(
        private MiningValuationProfileReader $profileReader,
        private ReprocessingProfileRepository $reprocessingProfileRepository,
        private OreValueService $oreValueService,
        private JitaMarketService $jitaMarketService,
        private StructureMarketService $structureMarketService,
    ) {
    }

    /**
     * @param list<int> $typeIds
     */
    public function read(User $user, array $typeIds): MiningProfileUnitPriceReading
    {
        return new MiningProfileUnitPriceReading(
            array_fill_keys($typeIds, 0.0),
            [],
            new ValuationSource(MiningValuationMarket::Jita, MarketSide::Sell, MiningValuationForm::Raw, null),
            null,
        );
    }
}
