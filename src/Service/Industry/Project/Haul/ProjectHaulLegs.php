<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Haul;

use App\Entity\IndustryProject;
use App\Entity\User;
use App\Industry\Application\ItemVolumes;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\Industry\Project\Courier\PurchaseOriginResolver;
use App\Service\Industry\Project\ProjectContainerReadings;
use App\Service\Industry\Project\ProjectContainerStations;
use App\Service\Industry\Project\PurchasesToDeliverBuilder;
use App\Service\Industry\Project\StationQuantitiesSource;
use App\Service\Industry\Project\StructureAccess\CharacterStructureAccess;
use App\Service\Industry\Project\Transfer\ProjectStationTransfers;
use App\Service\Industry\Stock\PersonalStockReader;

final readonly class ProjectHaulLegs
{
    public function __construct(
        private StationQuantitiesSource $stationQuantities,
        private PurchasesToDeliverBuilder $purchasesToDeliverBuilder,
        private ProjectStationTransfers $stationTransfers,
        private ProjectContainerReadings $containerReadings,
        private ProjectContainerStations $containerStations,
        private PersonalStockReader $personalStockReader,
        private CachedAssetRepository $assetRepository,
        private CharacterStructureAccess $structureAccess,
        private ItemVolumes $itemVolumes,
        private PurchaseOriginResolver $originResolver,
        private MapSolarSystemRepository $solarSystemRepository,
        private IndustryStructureConfigRepository $structureConfigRepository,
        private HaulLegsBuilder $builder,
    ) {
    }

    public function of(IndustryProject $project, User $viewer): HaulLegs
    {
        return new HaulLegs([], [], null);
    }
}
