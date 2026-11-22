<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Haul;

use App\Enum\StructureAccessStatus;
use App\Service\Industry\Project\PurchaseToDeliver;
use App\Service\Industry\Project\Transfer\TransferLegState;
use App\Service\Industry\Project\TypeStationQuantities;
use App\Service\Industry\Stock\PersonalStock;

final readonly class HaulReadings
{
    /**
     * @param list<TypeStationQuantities> $typeStationQuantities
     * @param list<PurchaseToDeliver> $purchasesToDeliver
     * @param list<TransferLegState> $transferLegStates
     * @param array<string, int> $routeDepartureLocationIds
     * @param array<int, array<int, \DateTimeImmutable|null>> $productionEnd
     * @param array<int, HaulPlace> $places
     * @param array<int, array<int, StructureAccessStatus>> $access
     * @param list<int> $viewerCharacterIds
     * @param array<int, float|null> $unitVolumes
     * @param list<int> $planLocationIds
     */
    public function __construct(
        public array $typeStationQuantities,
        public array $purchasesToDeliver,
        public array $transferLegStates,
        public array $routeDepartureLocationIds,
        public ?PersonalStock $personalStock,
        public ?string $personalStockUnknownReason,
        public array $productionEnd,
        public array $places,
        public array $access,
        public array $viewerCharacterIds,
        public array $unitVolumes,
        public array $planLocationIds,
    ) {
    }
}
