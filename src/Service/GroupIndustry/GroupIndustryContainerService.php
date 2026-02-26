<?php

declare(strict_types=1);

namespace App\Service\GroupIndustry;

use App\ApiResource\GroupIndustry\GroupContainerVerificationResource;
use App\Entity\GroupIndustryProject;
use App\Repository\CachedAssetRepository;
use App\Repository\GroupIndustryBomItemRepository;

class GroupIndustryContainerService
{
    public function __construct(
        private readonly CachedAssetRepository $assetRepository,
        private readonly GroupIndustryBomItemRepository $bomItemRepository,
    ) {
    }

    /**
     * Verify corp container contents against project BOM materials.
     *
     * Looks for named containers in corp assets, then checks quantities
     * of each material BOM item against what's inside those containers.
     *
     * @return GroupContainerVerificationResource[]
     */
    public function verifyContainer(GroupIndustryProject $project): array
    {
        $materialItems = $this->bomItemRepository->findMaterialsByProject($project);

        if ($materialItems === []) {
            return [];
        }

        $containerName = $project->getContainerName();
        $corporationId = $project->getOwner()->getCorporationId();

        // If no container configured or no corp ID, return all unchecked
        if ($containerName === null || $corporationId === null) {
            return $this->buildAllUnchecked($materialItems);
        }

        $containerQuantities = $this->getContainerQuantities($corporationId, $containerName);

        return $this->buildVerificationResults($materialItems, $containerQuantities);
    }

    /**
     * Find named containers in corp assets and aggregate item quantities inside them.
     *
     * Corp assets are flat: containers have an itemName, and items inside a container
     * have their locationId set to the container's itemId.
     *
     * @return array<int, int> typeId => total quantity across all matching containers
     */
    private function getContainerQuantities(int $corporationId, string $containerName): array
    {
        $corpAssets = $this->assetRepository->findByCorporationId($corporationId);

        // Step 1: Find all containers matching the name
        $containerItemIds = [];
        foreach ($corpAssets as $asset) {
            if ($asset->getItemName() === $containerName) {
                $containerItemIds[] = $asset->getItemId();
            }
        }

        if ($containerItemIds === []) {
            return [];
        }

        // Step 2: Find all assets whose locationId matches a container itemId
        $containerItemIdSet = array_flip($containerItemIds);
        $quantities = [];

        foreach ($corpAssets as $asset) {
            if (isset($containerItemIdSet[$asset->getLocationId()])) {
                $typeId = $asset->getTypeId();
                $quantities[$typeId] = ($quantities[$typeId] ?? 0) + $asset->getQuantity();
            }
        }

        return $quantities;
    }

    /**
     * @param \App\Entity\GroupIndustryBomItem[] $materialItems
     * @return GroupContainerVerificationResource[]
     */
    private function buildAllUnchecked(array $materialItems): array
    {
        $results = [];
        foreach ($materialItems as $item) {
            $results[] = $this->buildResource($item, 0, 'unchecked');
        }

        return $results;
    }

    /**
     * @param \App\Entity\GroupIndustryBomItem[] $materialItems
     * @param array<int, int> $containerQuantities
     * @return GroupContainerVerificationResource[]
     */
    private function buildVerificationResults(array $materialItems, array $containerQuantities): array
    {
        $results = [];

        foreach ($materialItems as $item) {
            $containerQty = $containerQuantities[$item->getTypeId()] ?? 0;
            $status = $this->determineStatus($containerQty, $item->getRequiredQuantity());
            $results[] = $this->buildResource($item, $containerQty, $status);
        }

        return $results;
    }

    private function determineStatus(int $containerQuantity, int $requiredQuantity): string
    {
        if ($containerQuantity >= $requiredQuantity) {
            return 'verified';
        }

        if ($containerQuantity > 0) {
            return 'partial';
        }

        return 'unchecked';
    }

    private function buildResource(
        \App\Entity\GroupIndustryBomItem $item,
        int $containerQuantity,
        string $status,
    ): GroupContainerVerificationResource {
        $resource = new GroupContainerVerificationResource();
        $resource->bomItemId = $item->getId()->toString();
        $resource->typeId = $item->getTypeId();
        $resource->typeName = $item->getTypeName();
        $resource->requiredQuantity = $item->getRequiredQuantity();
        $resource->containerQuantity = $containerQuantity;
        $resource->status = $status;

        return $resource;
    }
}
