<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\CachedAsset;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectContainer;
use App\Repository\CachedAssetRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;

final readonly class ProjectContainerReadings
{
    private const string REASON_CONTAINER_AT_ANOTHER_STATION = 'container_at_another_station';
    private const string REASON_STATION_UNRESOLVED = 'station_unresolved';

    public function __construct(
        private ProjectContainerService $containerService,
        private VisibleCorporationAssetsFinder $visibleAssetsFinder,
        private CachedAssetRepository $cachedAssetRepository,
    ) {
    }

    /**
     * @return list<ProjectContainerReading>
     */
    public function of(IndustryProject $project): array
    {
        $containers = $project->getContainers()->toArray();
        if ($containers === []) {
            return [];
        }

        usort(
            $containers,
            static fn (IndustryProjectContainer $left, IndustryProjectContainer $right): int => IndustryProjectContainer::keyOf($left->getName()) <=> IndustryProjectContainer::keyOf($right->getName()),
        );

        $owner = $project->getOwner();
        $corporationId = $owner->getCorporationId();
        $assetsSyncedAt = null === $corporationId ? null : $this->cachedAssetRepository->lastCachedAtOfCorporationAssets($corporationId);

        $unavailabilityReason = $this->containerService->unavailabilityReasonOf($project);
        if (null !== $unavailabilityReason) {
            return array_map(
                static fn (IndustryProjectContainer $container): ProjectContainerReading => ProjectContainerReading::unknown(
                    $container->getId()->toRfc4122(),
                    $container->getName(),
                    $container->getLocationId(),
                    $unavailabilityReason,
                    $assetsSyncedAt,
                ),
                $containers,
            );
        }

        $visibleAssets = $this->visibleAssetsFinder->findVisibleAssets($corporationId ?? throw new \LogicException('An available project has an owner corporation.'), null, $owner);
        $assetsByNameKey = [];
        foreach ($visibleAssets as $asset) {
            if (null !== $asset->getItemName()) {
                $assetsByNameKey[IndustryProjectContainer::keyOf($asset->getItemName())][] = $asset;
            }
        }

        $stations = new AssetStations($visibleAssets, $this->cachedAssetRepository, $corporationId);

        return array_map(
            fn (IndustryProjectContainer $container): ProjectContainerReading => $this->readingOf(
                $project,
                $container,
                $assetsByNameKey[IndustryProjectContainer::keyOf($container->getName())] ?? [],
                $visibleAssets,
                $stations,
                $assetsSyncedAt,
            ),
            $containers,
        );
    }

    /**
     * @param list<CachedAsset> $namedAssets
     * @param CachedAsset[] $visibleAssets
     */
    private function readingOf(
        IndustryProject $project,
        IndustryProjectContainer $container,
        array $namedAssets,
        array $visibleAssets,
        AssetStations $stations,
        ?\DateTimeImmutable $assetsSyncedAt,
    ): ProjectContainerReading {
        $containerId = $container->getId()->toRfc4122();
        $locationId = $container->getLocationId();

        if ([] === $namedAssets) {
            return ProjectContainerReading::unknown($containerId, $container->getName(), $locationId, $this->containerService->notFoundReasonOf($project, $container->getName()), $assetsSyncedAt);
        }

        $roots = $namedAssets;
        if (null !== $locationId) {
            $roots = [];
            $unresolved = false;
            foreach ($namedAssets as $asset) {
                $station = $stations->of($asset);
                if (null === $station) {
                    $unresolved = true;
                } elseif ($station === $locationId) {
                    $roots[] = $asset;
                }
            }

            if ([] === $roots) {
                return ProjectContainerReading::unknown(
                    $containerId,
                    $container->getName(),
                    $locationId,
                    $unresolved ? self::REASON_STATION_UNRESOLVED : self::REASON_CONTAINER_AT_ANOTHER_STATION,
                    $assetsSyncedAt,
                );
            }
        }

        return ProjectContainerReading::known(
            $containerId,
            $container->getName(),
            $locationId,
            $this->contentsOf($visibleAssets, $roots),
            $assetsSyncedAt,
        );
    }

    /**
     * @param CachedAsset[] $visibleAssets
     * @param list<CachedAsset> $roots
     *
     * @return list<ProjectContainerContent>
     */
    private function contentsOf(array $visibleAssets, array $roots): array
    {
        $contents = $this->containerService->contentsOfContainers(
            $visibleAssets,
            array_map(static fn (CachedAsset $root): int => $root->getItemId(), $roots),
        );

        $parentItemIds = [];
        foreach ($contents as $content) {
            $parentItemIds[$content->getLocationId()] = true;
        }

        $quantitiesByTypeId = [];
        $typeNamesByTypeId = [];
        foreach ($contents as $content) {
            if (isset($parentItemIds[$content->getItemId()])) {
                continue;
            }

            $typeId = $content->getTypeId();
            if (!isset($quantitiesByTypeId[$typeId])) {
                $quantitiesByTypeId[$typeId] = $content->getQuantity();
                $typeNamesByTypeId[$typeId] = $content->getTypeName();
                continue;
            }

            $quantitiesByTypeId[$typeId] += $content->getQuantity();
            if (null === $typeNamesByTypeId[$typeId]) {
                $typeNamesByTypeId[$typeId] = $content->getTypeName();
            }
        }

        ksort($quantitiesByTypeId);

        $articles = [];
        foreach ($quantitiesByTypeId as $typeId => $quantity) {
            $articles[] = new ProjectContainerContent($typeId, $typeNamesByTypeId[$typeId], $quantity);
        }

        return $articles;
    }
}
