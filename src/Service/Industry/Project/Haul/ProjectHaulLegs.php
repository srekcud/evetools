<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Haul;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Enum\StructureAccessStatus;
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
use App\Service\Industry\Project\Transfer\StationTransferReading;
use App\Service\Industry\Project\Transfer\TransferLegState;
use App\Service\Industry\Project\TypeStationQuantities;
use App\Service\Industry\Stock\PersonalStock;
use App\Service\Industry\Stock\PersonalStockReader;

final readonly class ProjectHaulLegs
{
    private const string REASON_PERSONAL_ASSETS_NOT_SYNCED = 'personal_assets_not_synced';
    private const array JOB_GROUPS_OF_HAULED_PRODUCTS = ['component', 'final'];
    private const array ACTIVITIES_OF_HAULED_PRODUCTS = [IndustryProjectBomLine::ACTIVITY_MANUFACTURING, IndustryProjectBomLine::ACTIVITY_REACTION];
    private const array STATUSES_WITH_PENDING_OUTPUT = [CachedIndustryJob::STATUS_ACTIVE, CachedIndustryJob::STATUS_PAUSED, CachedIndustryJob::STATUS_READY];

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
        if ($project->isCompleted()) {
            return new HaulLegs([], [], null);
        }

        $typeStationQuantities = $this->stationQuantities->of($project);
        $transferReadings = $this->stationTransfers->of($project, $this->containerReadings->of($project));
        $transferLegStates = array_map(static fn (StationTransferReading $reading) => $reading->state, $transferReadings);
        $routeDepartureLocationIds = $this->routeDepartureLocationIdsOf($transferReadings);

        $personalStockUnknownReason = null;
        $personalStock = null;
        if ($this->assetRepository->hasPersonalAssetsOf($viewer)) {
            $personalStock = $this->personalStockReader->of($viewer);
        } else {
            $personalStockUnknownReason = self::REASON_PERSONAL_ASSETS_NOT_SYNCED;
        }

        $planStations = $this->containerStations->of($project);
        $planLocationIds = [];
        $places = [];
        foreach ($planStations as $station) {
            if (null !== $station->locationId) {
                $planLocationIds[] = $station->locationId;
                $places[$station->locationId] = new HaulPlace($station->locationId, $station->structureName, $station->solarSystemId, $this->solarSystemNameOf($station->solarSystemId));
            }
        }

        $transferEndpoints = $this->transferEndpointsOf($transferReadings);
        foreach ($this->otherLocationIdsOf($typeStationQuantities, $personalStock, $transferLegStates, $routeDepartureLocationIds) as $locationId) {
            $places[$locationId] ??= $this->placeOfOtherStation($locationId, $personalStock, $transferEndpoints);
        }

        $viewerCharacters = array_values($viewer->getCharacters()->toArray());

        return $this->builder->build(new HaulReadings(
            $typeStationQuantities,
            $this->purchasesToDeliverBuilder->build($project)->rows,
            $transferLegStates,
            $routeDepartureLocationIds,
            $personalStock,
            $personalStockUnknownReason,
            $this->productionEndOf($project),
            $places,
            $this->accessOf($viewerCharacters, $places),
            array_map(static fn (Character $character): int => $character->getEveCharacterId(), $viewerCharacters),
            $this->unitVolumesOf($typeStationQuantities, $transferLegStates),
            $planLocationIds,
        ));
    }

    /**
     * @param list<StationTransferReading> $transferReadings
     *
     * @return array<string, int>
     */
    private function routeDepartureLocationIdsOf(array $transferReadings): array
    {
        $firstLegIndexByRouteId = [];
        foreach ($transferReadings as $reading) {
            $routeId = $reading->state->routeId;
            $firstLegIndexByRouteId[$routeId] = min($firstLegIndexByRouteId[$routeId] ?? $reading->leg->getLegIndex(), $reading->leg->getLegIndex());
        }

        $departures = [];
        foreach ($transferReadings as $reading) {
            $departureLocationId = $reading->leg->getFrom()->locationId;
            if ($reading->leg->getLegIndex() === $firstLegIndexByRouteId[$reading->state->routeId] && null !== $departureLocationId) {
                $departures[$reading->state->routeId] = $departureLocationId;
            }
        }

        return $departures;
    }

    /**
     * @param list<StationTransferReading> $transferReadings
     *
     * @return array<int, int> solar system id by location id
     */
    private function transferEndpointsOf(array $transferReadings): array
    {
        $solarSystemIdByLocationId = [];
        foreach ($transferReadings as $reading) {
            foreach ([$reading->leg->getFrom(), $reading->leg->getTo()] as $endpoint) {
                if (null !== $endpoint->locationId) {
                    $solarSystemIdByLocationId[$endpoint->locationId] = $endpoint->solarSystemId;
                }
            }
        }

        return $solarSystemIdByLocationId;
    }

    /**
     * @param list<TypeStationQuantities> $typeStationQuantities
     * @param list<TransferLegState> $transferLegStates
     * @param array<string, int> $routeDepartureLocationIds
     *
     * @return list<int>
     */
    private function otherLocationIdsOf(array $typeStationQuantities, ?PersonalStock $personalStock, array $transferLegStates, array $routeDepartureLocationIds): array
    {
        $locationIds = array_values($routeDepartureLocationIds);
        foreach ($transferLegStates as $legState) {
            if (null !== $legState->toLocationId) {
                $locationIds[] = $legState->toLocationId;
            }
        }
        foreach ($typeStationQuantities as $type) {
            foreach ($type->stations ?? [] as $station) {
                if (null !== $station->locationId) {
                    $locationIds[] = $station->locationId;
                }
            }
            foreach ($personalStock?->stationsOf($type->typeId) ?? [] as $location) {
                $locationIds[] = $location['locationId'];
            }
        }

        return array_values(array_unique($locationIds));
    }

    /**
     * @param array<int, int> $transferSolarSystemIds
     */
    private function placeOfOtherStation(int $locationId, ?PersonalStock $personalStock, array $transferSolarSystemIds): HaulPlace
    {
        $solarSystemId = $this->originResolver->solarSystemIdOfLocation($locationId) ?? ($transferSolarSystemIds[$locationId] ?? null);

        return new HaulPlace($locationId, $this->stockLocationNameOf($personalStock, $locationId), $solarSystemId, $this->solarSystemNameOf($solarSystemId));
    }

    private function stockLocationNameOf(?PersonalStock $personalStock, int $locationId): ?string
    {
        foreach ($personalStock->locationsByTypeId ?? [] as $entry) {
            foreach ($entry['locations'] as $location) {
                if ($location['locationId'] === $locationId) {
                    return $location['locationName'];
                }
            }
        }

        return null;
    }

    private function solarSystemNameOf(?int $solarSystemId): ?string
    {
        return null === $solarSystemId ? null : $this->solarSystemRepository->findBySolarSystemId($solarSystemId)?->getSolarSystemName();
    }

    /**
     * @return array<int, array<int, \DateTimeImmutable|null>>
     */
    private function productionEndOf(IndustryProject $project): array
    {
        $locationIdByStructureId = [];
        foreach ($this->structureConfigRepository->findByUser($project->getOwner()) as $structure) {
            $locationIdByStructureId[$structure->getId()->toRfc4122()] = $structure->getLocationId();
        }

        $endDates = [];
        foreach ($project->getBomItems() as $line) {
            if (!$line->isJob()
                || !\in_array($line->getJobGroup(), self::JOB_GROUPS_OF_HAULED_PRODUCTS, true)
                || !\in_array($line->getActivityType(), self::ACTIVITIES_OF_HAULED_PRODUCTS, true)
                || null === $line->getPlannedStructureId()
                || !isset($locationIdByStructureId[$line->getPlannedStructureId()])
            ) {
                continue;
            }

            $locationId = $locationIdByStructureId[$line->getPlannedStructureId()];
            foreach ($line->getJobMatches() as $match) {
                if (!\in_array($match->getStatus(), self::STATUSES_WITH_PENDING_OUTPUT, true)) {
                    continue;
                }
                $endDates[$locationId][$line->getTypeId()][] = $match->getEndDate();
            }
        }

        return array_map(
            static fn (array $endDatesByTypeId): array => array_map(
                static fn (array $endDatesOfType): ?\DateTimeImmutable => \in_array(null, $endDatesOfType, true) ? null : max($endDatesOfType),
                $endDatesByTypeId,
            ),
            $endDates,
        );
    }

    /**
     * @param list<Character> $characters
     * @param array<int, HaulPlace> $places
     *
     * @return array<int, array<int, StructureAccessStatus>>
     */
    private function accessOf(array $characters, array $places): array
    {
        $access = [];
        foreach ($characters as $character) {
            foreach (array_keys($places) as $locationId) {
                $access[$character->getEveCharacterId()][$locationId] = $this->structureAccess->statusOf($character, $locationId);
            }
        }

        return $access;
    }

    /**
     * @param list<TypeStationQuantities> $typeStationQuantities
     * @param list<TransferLegState> $transferLegStates
     *
     * @return array<int, float|null>
     */
    private function unitVolumesOf(array $typeStationQuantities, array $transferLegStates): array
    {
        $typeIds = array_map(static fn (TypeStationQuantities $type): int => $type->typeId, $typeStationQuantities);
        foreach ($transferLegStates as $legState) {
            foreach ($legState->lines as $line) {
                $typeIds[] = $line->typeId;
            }
        }

        $unitVolumes = [];
        foreach (array_unique($typeIds) as $typeId) {
            $unitVolumes[$typeId] = $this->itemVolumes->volumeOf($typeId);
        }

        return $unitVolumes;
    }
}
