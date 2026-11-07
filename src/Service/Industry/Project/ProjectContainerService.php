<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\ApiResource\Industry\Project\ProjectContainerVerificationResource;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProject;
use App\Repository\CachedAssetRepository;
use App\Repository\CharacterRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\IndustryProjectBomLineRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;

class ProjectContainerService
{
    private const string STATUS_VERIFIED = 'verified';
    private const string STATUS_PARTIAL = 'partial';
    private const string STATUS_MISSING = 'missing';
    private const string STATUS_CONTAINER_NOT_FOUND = 'container_not_found';
    private const string STATUS_UNKNOWN = 'unknown';

    private const string REASON_OWNER_WITHOUT_CORPORATION = 'owner_without_corporation';
    private const string REASON_CORPORATION_ASSETS_NOT_SYNCED = 'corporation_assets_not_synced';
    private const string REASON_NO_VISIBLE_DIVISION = 'no_visible_division';
    private const string REASON_NO_DIRECTOR = 'no_director';

    public function __construct(
        private readonly VisibleCorporationAssetsFinder $visibleAssetsFinder,
        private readonly IndustryProjectBomLineRepository $bomItemRepository,
        private readonly CachedAssetRepository $cachedAssetRepository,
        private readonly CorpAssetVisibilityRepository $visibilityRepository,
        private readonly CharacterRepository $characterRepository,
    ) {
    }

    /**
     * Verify corp container contents against project BOM materials.
     *
     * Looks for named containers in corp assets, then checks quantities
     * of each material BOM item against what's inside those containers.
     *
     * @return ProjectContainerVerificationResource[]
     */
    public function verifyContainer(IndustryProject $project): array
    {
        $materialItems = $this->bomItemRepository->findMaterialsByProject($project);
        $containerName = $project->getContainerName();

        if ($materialItems === [] || $containerName === null) {
            return [];
        }

        $corporationId = $project->getOwner()->getCorporationId();

        if ($corporationId === null) {
            return $this->buildAllUnknown($materialItems, self::REASON_OWNER_WITHOUT_CORPORATION);
        }

        if (!$this->cachedAssetRepository->hasCorporationAssets($corporationId)) {
            return $this->buildAllUnknown($materialItems, self::REASON_CORPORATION_ASSETS_NOT_SYNCED);
        }

        $visibility = $this->visibilityRepository->findByCorporationId($corporationId);
        if ($visibility === null || $visibility->getVisibleDivisions() === []) {
            return $this->buildAllUnknown($materialItems, self::REASON_NO_VISIBLE_DIVISION);
        }

        $containerQuantities = $this->getContainerQuantities($corporationId, $containerName);

        if ($containerQuantities === null) {
            return $this->buildAllContainerNotFound($materialItems);
        }

        return $this->buildVerificationResults($materialItems, $containerQuantities);
    }

    /**
     * Find named containers in the visible corp assets and aggregate the quantities of
     * everything stored inside them, sub-containers included. Each asset counts once.
     *
     * @return array<int, int>|null typeId => total quantity across all matching containers, null when no container has that name
     */
    private function getContainerQuantities(int $corporationId, string $containerName): ?array
    {
        $visibleAssets = $this->visibleAssetsFinder->findVisibleAssets($corporationId);

        $assetsByLocationId = [];
        $pendingContainerItemIds = [];
        foreach ($visibleAssets as $asset) {
            $assetsByLocationId[$asset->getLocationId()][] = $asset;
            if ($asset->getItemName() === $containerName) {
                $pendingContainerItemIds[] = $asset->getItemId();
            }
        }

        if ($pendingContainerItemIds === []) {
            return null;
        }

        $quantities = [];
        $countedItemIds = [];

        while ($pendingContainerItemIds !== []) {
            $containerItemId = array_pop($pendingContainerItemIds);

            foreach ($assetsByLocationId[$containerItemId] ?? [] as $content) {
                if (isset($countedItemIds[$content->getItemId()])) {
                    continue;
                }

                $countedItemIds[$content->getItemId()] = true;
                $typeId = $content->getTypeId();
                $quantities[$typeId] = isset($quantities[$typeId]) ? $quantities[$typeId] + $content->getQuantity() : $content->getQuantity();
                $pendingContainerItemIds[] = $content->getItemId();
            }
        }

        return $quantities;
    }

    /**
     * @param IndustryProjectBomLine[] $materialItems
     * @return ProjectContainerVerificationResource[]
     */
    private function buildAllUnknown(array $materialItems, string $unknownReason): array
    {
        return array_map(
            fn (IndustryProjectBomLine $item): ProjectContainerVerificationResource => $this->buildResource($item, null, self::STATUS_UNKNOWN, $unknownReason),
            $materialItems,
        );
    }

    /**
     * @param IndustryProjectBomLine[] $materialItems
     * @return ProjectContainerVerificationResource[]
     */
    private function buildAllContainerNotFound(array $materialItems): array
    {
        return array_map(
            fn (IndustryProjectBomLine $item): ProjectContainerVerificationResource => $this->buildResource($item, null, self::STATUS_CONTAINER_NOT_FOUND),
            $materialItems,
        );
    }

    /**
     * @param IndustryProjectBomLine[] $materialItems
     * @param array<int, int> $containerQuantities
     * @return ProjectContainerVerificationResource[]
     */
    private function buildVerificationResults(array $materialItems, array $containerQuantities): array
    {
        $results = [];

        foreach ($materialItems as $item) {
            $containerQty = isset($containerQuantities[$item->getTypeId()]) ? $containerQuantities[$item->getTypeId()] : 0;
            $status = $this->determineStatus($containerQty, $item->getRequiredQuantity());
            $results[] = $this->buildResource($item, $containerQty, $status);
        }

        return $results;
    }

    private function determineStatus(int $containerQuantity, int $requiredQuantity): string
    {
        if ($containerQuantity >= $requiredQuantity) {
            return self::STATUS_VERIFIED;
        }

        if ($containerQuantity > 0) {
            return self::STATUS_PARTIAL;
        }

        return self::STATUS_MISSING;
    }

    private function buildResource(
        IndustryProjectBomLine $item,
        ?int $containerQuantity,
        string $status,
        ?string $unknownReason = null,
    ): ProjectContainerVerificationResource {
        $resource = new ProjectContainerVerificationResource();
        $resource->bomItemId = $item->getId()->toString();
        $resource->typeId = $item->getTypeId();
        $resource->typeName = $item->getTypeName();
        $resource->requiredQuantity = $item->getRequiredQuantity();
        $resource->containerQuantity = $containerQuantity;
        $resource->status = $status;
        $resource->unknownReason = $unknownReason;

        return $resource;
    }
}
