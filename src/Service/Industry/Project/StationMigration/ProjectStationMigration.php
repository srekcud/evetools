<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StationMigration;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectContainer;
use App\Enum\ProjectStatus;
use App\Exception\ContainerNameTakenException;
use App\Exception\LocationNotInPlanException;
use App\Exception\StationAlreadyHasContainerException;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\WorkType;
use App\Repository\CachedAssetRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use App\Service\Industry\Project\AssetStations;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\ProjectContainerPlacer;
use App\Service\Industry\Project\ProjectContainerService;
use App\Service\Industry\Project\ProjectWorkStations;

final readonly class ProjectStationMigration
{
    private const string REASON_CORPORATION_ASSETS_STALE = 'corporation_assets_stale';

    public function __construct(
        private ProjectWorkStations $workStations,
        private IndustryProjectService $projectService,
        private WorkStationInference $workStationInference,
        private ContainerLocationInference $containerLocationInference,
        private ProjectContainerPlacer $containerPlacer,
        private ProjectContainerService $containerService,
        private VisibleCorporationAssetsFinder $visibleAssetsFinder,
        private CachedAssetRepository $cachedAssetRepository,
    ) {
    }

    public function migrate(IndustryProject $project): ProjectStationMigrationReport
    {
        $outcomes = [...$this->migrateWorkStations($project), ...$this->migrateContainers($project)];

        return new ProjectStationMigrationReport(
            $project->getId()->toRfc4122(),
            $project->getName(),
            $project->getStatus()->value,
            array_values(array_filter($outcomes, static fn (StationMigrationChange|StationMigrationUnmigrated $outcome): bool => $outcome instanceof StationMigrationChange)),
            array_values(array_filter($outcomes, static fn (StationMigrationChange|StationMigrationUnmigrated $outcome): bool => $outcome instanceof StationMigrationUnmigrated)),
        );
    }

    /**
     * @return list<StationMigrationChange|StationMigrationUnmigrated>
     */
    private function migrateWorkStations(IndustryProject $project): array
    {
        $projectId = $project->getId()->toRfc4122();
        $targetProductTypeIds = $this->workStations->targetProductTypeIdsOf($project);

        $outcomes = [];
        $plannedStructureIdsByWorkType = [];
        foreach ($project->getBomItems() as $line) {
            if (!$line->isJob()) {
                continue;
            }

            try {
                $workType = $this->workStations->workTypeOf($line, $targetProductTypeIds);
            } catch (\UnexpectedValueException) {
                $outcomes[] = new StationMigrationUnmigrated($projectId, StationMigrationChangeKind::WorkStation, \sprintf('type_%d', $line->getTypeId()), StationMigrationReason::TypeMissingFromSde);

                continue;
            }

            $plannedStructureIdsByWorkType[$workType->value][] = $line->getPlannedStructureId();
        }

        $ownerStructuresById = [];
        foreach ($this->projectService->structuresOf($project->getOwner()) as $structure) {
            $ownerStructuresById[$structure->id] = $structure;
        }

        $stored = $project->getWorkStations();
        $updated = $stored;
        foreach ($plannedStructureIdsByWorkType as $workTypeValue => $plannedStructureIds) {
            if (isset($stored[$workTypeValue])) {
                continue;
            }

            $inferred = $this->workStationInference->of(WorkType::from($workTypeValue), $plannedStructureIds, $ownerStructuresById);
            if ($inferred instanceof StationMigrationReason) {
                $outcomes[] = new StationMigrationUnmigrated($projectId, StationMigrationChangeKind::WorkStation, $workTypeValue, $inferred);

                continue;
            }

            $updated[$workTypeValue] = ['structureId' => $inferred, 'recommendedStructureId' => null];
            $outcomes[] = new StationMigrationChange($projectId, StationMigrationChangeKind::WorkStation, $workTypeValue, $inferred);
        }

        if ($updated !== $stored) {
            $project->setWorkStations($updated);
        }

        return $outcomes;
    }

    /**
     * @return list<StationMigrationChange|StationMigrationUnmigrated>
     */
    private function migrateContainers(IndustryProject $project): array
    {
        $unlocatedContainers = array_values(array_filter(
            $project->getContainers()->toArray(),
            static fn (IndustryProjectContainer $container): bool => null === $container->getLocationId(),
        ));
        if ([] === $unlocatedContainers) {
            return [];
        }

        usort(
            $unlocatedContainers,
            static fn (IndustryProjectContainer $left, IndustryProjectContainer $right): int => IndustryProjectContainer::keyOf($left->getName()) <=> IndustryProjectContainer::keyOf($right->getName()),
        );

        $projectId = $project->getId()->toRfc4122();
        $refuseAll = static fn (StationMigrationReason $reason, ?string $detail = null): array => array_map(
            static fn (IndustryProjectContainer $container): StationMigrationUnmigrated => new StationMigrationUnmigrated($projectId, StationMigrationChangeKind::ContainerLocation, $container->getId()->toRfc4122(), $reason, $detail),
            $unlocatedContainers,
        );

        if (ProjectStatus::Completed === $project->getStatus()) {
            return $refuseAll(StationMigrationReason::ProjectCompleted);
        }

        $unavailabilityReason = $this->containerService->unavailabilityReasonOf($project);
        if (null !== $unavailabilityReason) {
            return $refuseAll(StationMigrationReason::AssetsUnavailable, $unavailabilityReason);
        }

        $owner = $project->getOwner();
        $corporationId = $owner->getCorporationId() ?? throw new \LogicException('An available project has an owner corporation.');
        if ($this->containerService->areCorporationAssetsStale($this->cachedAssetRepository->lastCachedAtOfCorporationAssets($corporationId))) {
            return $refuseAll(StationMigrationReason::AssetsUnavailable, self::REASON_CORPORATION_ASSETS_STALE);
        }

        $visibleAssets = $this->visibleAssetsFinder->findVisibleAssets($corporationId, null, $owner);
        $assetsByNameKey = [];
        foreach ($visibleAssets as $asset) {
            if (null !== $asset->getItemName()) {
                $assetsByNameKey[IndustryProjectContainer::keyOf($asset->getItemName())][] = $asset;
            }
        }

        $stations = new AssetStations($visibleAssets, $this->cachedAssetRepository, $corporationId);
        $outcomes = [];
        foreach ($unlocatedContainers as $container) {
            array_push($outcomes, ...$this->locate($project, $container, array_map($stations->of(...), $assetsByNameKey[IndustryProjectContainer::keyOf($container->getName())] ?? [])));
        }

        return $outcomes;
    }

    /**
     * @param list<int|null> $assetStationIds
     *
     * @return list<StationMigrationChange|StationMigrationUnmigrated>
     */
    private function locate(IndustryProject $project, IndustryProjectContainer $container, array $assetStationIds): array
    {
        $projectId = $project->getId()->toRfc4122();
        $containerId = $container->getId()->toRfc4122();
        $unmigrated = static fn (StationMigrationReason $reason, ?string $detail = null): StationMigrationUnmigrated => new StationMigrationUnmigrated($projectId, StationMigrationChangeKind::ContainerLocation, $containerId, $reason, $detail);

        $locationIds = $this->containerLocationInference->of($assetStationIds);
        if ($locationIds instanceof StationMigrationReason) {
            return [$unmigrated($locationIds)];
        }

        $detailOf = static fn (int $locationId): ?string => 1 === \count($locationIds) ? null : (string) $locationId;
        $outcomes = [];
        foreach ($locationIds as $locationId) {
            try {
                $station = $this->containerPlacer->place($project, $locationId, $container->getName());
            } catch (LocationNotInPlanException) {
                $outcomes[] = $unmigrated(StationMigrationReason::StationNotInPlan, $detailOf($locationId));

                continue;
            } catch (StationAlreadyHasContainerException) {
                $outcomes[] = $unmigrated(StationMigrationReason::StationAlreadyHasContainer, $detailOf($locationId));

                continue;
            } catch (ContainerNameTakenException) {
                $outcomes[] = $unmigrated(StationMigrationReason::NamedLikePersonalStock, $detailOf($locationId));

                continue;
            }

            $outcomes[] = new StationMigrationChange($projectId, StationMigrationChangeKind::ContainerLocation, $station->containerId ?? throw new \LogicException('A placed container has an id.'), (string) $locationId);
        }

        return $outcomes;
    }
}
