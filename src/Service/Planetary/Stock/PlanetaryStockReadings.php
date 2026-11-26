<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

use App\Service\Industry\Stock\PersonalStockContainerContents;

final readonly class PlanetaryStockReadings
{
    /**
     * @param list<PlanetaryWeeklyNeed> $weeklyNeeds
     * @param array<int, array<int, int>> $quantitiesByRootLocation locationId => typeId => quantity
     * @param list<PersonalStockContainerContents> $corporationContainers
     * @param list<PlanetaryBuyOrder> $buyOrders
     */
    public function __construct(
        public array $weeklyNeeds,
        public ?int $homeLocationId,
        public int $hubStructureId,
        public int $coverageWeeks,
        public array $quantitiesByRootLocation,
        public array $corporationContainers,
        public array $buyOrders,
        public bool $needsComplete,
    ) {
    }
}
