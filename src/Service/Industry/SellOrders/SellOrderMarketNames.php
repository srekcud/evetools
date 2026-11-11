<?php

declare(strict_types=1);

namespace App\Service\Industry\SellOrders;

use App\Constant\EveConstants;
use App\Repository\CachedStructureRepository;
use App\Service\Industry\SellMarkets\SellMarketComparison;

class SellOrderMarketNames
{
    public function __construct(
        private readonly CachedStructureRepository $structureRepository,
    ) {
    }

    public function of(int $locationId): ?string
    {
        if ($locationId === EveConstants::JITA_STATION_ID) {
            return SellMarketComparison::JITA_MARKET_NAME;
        }

        return $this->structureRepository->findByStructureId($locationId)?->getName();
    }
}
