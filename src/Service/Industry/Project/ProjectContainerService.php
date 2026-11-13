<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\ApiResource\Industry\Project\ProjectContainerVerificationResource;
use App\Constant\EveConstants;
use App\Entity\CachedAsset;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\IndustryProjectBomLineRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use App\Service\ESI\CorporationDirectorAccess;

class ProjectContainerService
{
    private const string STATUS_VERIFIED = 'verified';
    private const string STATUS_PARTIAL = 'partial';
    private const string STATUS_MISSING = 'missing';
    private const string STATUS_CONTAINER_NOT_FOUND = 'container_not_found';
    private const string STATUS_CONTAINER_IN_HIDDEN_DIVISION = 'container_in_hidden_division';
    private const string STATUS_CORPORATION_ASSETS_STALE = 'corporation_assets_stale';
    private const string STATUS_UNKNOWN = 'unknown';

    private const string REASON_OWNER_WITHOUT_CORPORATION = 'owner_without_corporation';
    private const string REASON_CORPORATION_ASSETS_NOT_SYNCED = 'corporation_assets_not_synced';
    private const string REASON_NO_VISIBLE_DIVISION = 'no_visible_division';
    private const string REASON_NO_DIRECTOR = 'no_director';
    public const string REASON_NO_CONTAINER_NAME = 'no_container_name';

    private const string CORPORATION_ASSETS_STALE_AFTER = 'PT48H';
    private const array CORPORATION_DIVISION_NUMBERS = [1, 2, 3, 4, 5, 6, 7];

    public function __construct(
        private readonly VisibleCorporationAssetsFinder $visibleAssetsFinder,
        private readonly IndustryProjectBomLineRepository $bomItemRepository,
        private readonly CachedAssetRepository $cachedAssetRepository,
        private readonly CorpAssetVisibilityRepository $visibilityRepository,
        private readonly CorporationDirectorAccess $directorAccess,
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
        $materialItems = [
            ...$this->bomItemRepository->findMaterialsByProject($project),
            ...$this->bomItemRepository->findComponentJobsByProject($project),
        ];
        $containerName = $this->containerNameOf($project);

        if ($materialItems === [] || $containerName === null) {
            return [];
        }

        $owner = $project->getOwner();
        $corporationId = $owner->getCorporationId();

        if ($corporationId === null) {
            return $this->buildAllUnknown($materialItems, self::REASON_OWNER_WITHOUT_CORPORATION, null);
        }

        $assetsSyncedAt = $this->cachedAssetRepository->lastCachedAtOfCorporationAssets($corporationId);

        $unavailabilityReason = $this->unavailabilityReason($corporationId, $owner);
        if ($unavailabilityReason !== null) {
            return $this->buildAllUnknown($materialItems, $unavailabilityReason, $assetsSyncedAt);
        }

        $containerQuantities = $this->getContainerQuantities($corporationId, $containerName, $owner);

        if ($containerQuantities === null) {
            return $this->buildAllContainerNotFound(
                $materialItems,
                $this->notFoundStatusOf($corporationId, $containerName, $owner, $assetsSyncedAt),
                $assetsSyncedAt,
            );
        }

        return $this->buildVerificationResults($materialItems, $containerQuantities, $assetsSyncedAt);
    }

    public function containerScopeOf(IndustryProject $project): ProjectContainerScope
    {
        $owner = $project->getOwner();
        $corporationId = $owner->getCorporationId();

        if ($corporationId === null) {
            return new ProjectContainerScope(self::REASON_OWNER_WITHOUT_CORPORATION);
        }

        $unavailabilityReason = $this->unavailabilityReason($corporationId, $owner);
        if ($unavailabilityReason !== null) {
            return new ProjectContainerScope($unavailabilityReason);
        }

        $visibleAssets = $this->visibleAssetsFinder->findVisibleAssets($corporationId, null, $owner);
        $visibleAssetsByItemId = [];
        foreach ($visibleAssets as $asset) {
            $visibleAssetsByItemId[$asset->getItemId()] = $asset;
        }

        $containerName = $this->containerNameOf($project);
        if ($containerName === null) {
            return new ProjectContainerScope(self::REASON_NO_CONTAINER_NAME, [], $visibleAssetsByItemId);
        }

        $named = $this->walkNamedContainers($visibleAssets, $containerName);
        if ($named === null) {
            return new ProjectContainerScope(self::STATUS_CONTAINER_NOT_FOUND, [], $visibleAssetsByItemId);
        }

        $containerItemIds = $named['rootItemIds'];
        foreach ($named['contents'] as $content) {
            $containerItemIds[] = $content->getItemId();
        }

        return new ProjectContainerScope(null, array_values(array_unique($containerItemIds)), $visibleAssetsByItemId);
    }

    public function containerStockOf(IndustryProject $project): ProjectContainerStock
    {
        $containerName = $this->containerNameOf($project);
        if ($containerName === null) {
            return new ProjectContainerStock(self::REASON_NO_CONTAINER_NAME);
        }

        $owner = $project->getOwner();
        $corporationId = $owner->getCorporationId();
        if ($corporationId === null) {
            return new ProjectContainerStock(self::REASON_OWNER_WITHOUT_CORPORATION);
        }

        $unavailabilityReason = $this->unavailabilityReason($corporationId, $owner);
        if ($unavailabilityReason !== null) {
            return new ProjectContainerStock($unavailabilityReason);
        }

        $named = $this->walkNamedContainers($this->visibleAssetsFinder->findVisibleAssets($corporationId, null, $owner), $containerName);
        if ($named === null) {
            return new ProjectContainerStock(self::STATUS_CONTAINER_NOT_FOUND);
        }

        $subContainerItemIds = [];
        foreach ($named['contents'] as $content) {
            $subContainerItemIds[$content->getLocationId()] = true;
        }

        $quantities = [];
        $contentItemIds = [];
        foreach ($named['contents'] as $content) {
            $contentItemIds[] = $content->getItemId();
            if (isset($subContainerItemIds[$content->getItemId()])) {
                continue;
            }

            $typeId = $content->getTypeId();
            $quantities[$typeId] = isset($quantities[$typeId]) ? $quantities[$typeId] + $content->getQuantity() : $content->getQuantity();
        }

        return new ProjectContainerStock(null, $quantities, $contentItemIds);
    }

    private function containerNameOf(IndustryProject $project): ?string
    {
        $containerName = $project->getContainerName();
        if ($containerName === null) {
            return null;
        }

        $containerName = trim($containerName);

        return $containerName !== '' ? $containerName : null;
    }

    private function notFoundStatusOf(int $corporationId, string $containerName, User $owner, ?\DateTimeImmutable $assetsSyncedAt): string
    {
        if ($this->isNamedInAHiddenDivision($corporationId, $containerName, $owner)) {
            return self::STATUS_CONTAINER_IN_HIDDEN_DIVISION;
        }

        $staleBefore = (new \DateTimeImmutable())->sub(new \DateInterval(self::CORPORATION_ASSETS_STALE_AFTER));
        if ($assetsSyncedAt !== null && $assetsSyncedAt < $staleBefore) {
            return self::STATUS_CORPORATION_ASSETS_STALE;
        }

        return self::STATUS_CONTAINER_NOT_FOUND;
    }

    private function isNamedInAHiddenDivision(int $corporationId, string $containerName, User $owner): bool
    {
        $visibility = $this->visibilityRepository->findByCorporationId($corporationId);
        if ($visibility === null) {
            return false;
        }

        $hiddenDivisions = array_values(array_diff(self::CORPORATION_DIVISION_NUMBERS, $visibility->divisionsVisibleTo($owner)));
        if ($hiddenDivisions === []) {
            return false;
        }

        $hangarAssets = $this->cachedAssetRepository->findByCorporationAndDivisions($corporationId, $hiddenDivisions);
        $containerContents = $this->cachedAssetRepository->findContentsOfContainers(
            $corporationId,
            array_map(static fn (CachedAsset $asset): int => $asset->getItemId(), $hangarAssets),
        );

        foreach ([...$hangarAssets, ...$containerContents] as $asset) {
            if ($asset->getItemName() === $containerName) {
                return true;
            }
        }

        return false;
    }

    private function unavailabilityReason(int $corporationId, User $owner): ?string
    {
        if (!$this->directorAccess->isConnected($corporationId, [EveConstants::CORPORATION_ASSETS_SCOPE])) {
            return self::REASON_NO_DIRECTOR;
        }

        if (!$this->cachedAssetRepository->hasCorporationAssets($corporationId)) {
            return self::REASON_CORPORATION_ASSETS_NOT_SYNCED;
        }

        $visibility = $this->visibilityRepository->findByCorporationId($corporationId);
        if ($visibility === null || $visibility->divisionsVisibleTo($owner) === []) {
            return self::REASON_NO_VISIBLE_DIVISION;
        }

        return null;
    }

    /**
     * Find named containers in the visible corp assets and aggregate the quantities of
     * everything stored inside them, sub-containers included. Each asset counts once.
     *
     * @return array<int, int>|null typeId => total quantity across all matching containers, null when no container has that name
     */
    private function getContainerQuantities(int $corporationId, string $containerName, User $owner): ?array
    {
        $named = $this->walkNamedContainers($this->visibleAssetsFinder->findVisibleAssets($corporationId, null, $owner), $containerName);
        if ($named === null) {
            return null;
        }

        $quantities = [];
        foreach ($named['contents'] as $content) {
            $typeId = $content->getTypeId();
            $quantities[$typeId] = isset($quantities[$typeId]) ? $quantities[$typeId] + $content->getQuantity() : $content->getQuantity();
        }

        return $quantities;
    }

    /**
     * @param CachedAsset[] $visibleAssets
     *
     * @return array{rootItemIds: list<int>, contents: list<CachedAsset>}|null null when no asset has that name
     */
    private function walkNamedContainers(array $visibleAssets, string $containerName): ?array
    {
        $assetsByLocationId = [];
        $rootItemIds = [];
        foreach ($visibleAssets as $asset) {
            $assetsByLocationId[$asset->getLocationId()][] = $asset;
            if ($asset->getItemName() === $containerName) {
                $rootItemIds[] = $asset->getItemId();
            }
        }

        if ($rootItemIds === []) {
            return null;
        }

        $pendingContainerItemIds = $rootItemIds;
        $contents = [];
        $countedItemIds = [];

        while ($pendingContainerItemIds !== []) {
            $containerItemId = array_pop($pendingContainerItemIds);

            foreach ($assetsByLocationId[$containerItemId] ?? [] as $content) {
                if (isset($countedItemIds[$content->getItemId()])) {
                    continue;
                }

                $countedItemIds[$content->getItemId()] = true;
                $contents[] = $content;
                $pendingContainerItemIds[] = $content->getItemId();
            }
        }

        return ['rootItemIds' => $rootItemIds, 'contents' => $contents];
    }

    /**
     * @param IndustryProjectBomLine[] $materialItems
     * @return ProjectContainerVerificationResource[]
     */
    private function buildAllUnknown(array $materialItems, string $unknownReason, ?\DateTimeImmutable $assetsSyncedAt): array
    {
        return array_map(
            fn (IndustryProjectBomLine $item): ProjectContainerVerificationResource => $this->buildResource($item, null, self::STATUS_UNKNOWN, $assetsSyncedAt, $unknownReason),
            $materialItems,
        );
    }

    /**
     * @param IndustryProjectBomLine[] $materialItems
     * @return ProjectContainerVerificationResource[]
     */
    private function buildAllContainerNotFound(array $materialItems, string $status, ?\DateTimeImmutable $assetsSyncedAt): array
    {
        return array_map(
            fn (IndustryProjectBomLine $item): ProjectContainerVerificationResource => $this->buildResource($item, null, $status, $assetsSyncedAt),
            $materialItems,
        );
    }

    /**
     * @param IndustryProjectBomLine[] $materialItems
     * @param array<int, int> $containerQuantities
     * @return ProjectContainerVerificationResource[]
     */
    private function buildVerificationResults(array $materialItems, array $containerQuantities, ?\DateTimeImmutable $assetsSyncedAt): array
    {
        $results = [];

        foreach ($materialItems as $item) {
            $containerQty = isset($containerQuantities[$item->getTypeId()]) ? $containerQuantities[$item->getTypeId()] : 0;
            $status = $this->determineStatus($containerQty, $item->getRequiredQuantity());
            $results[] = $this->buildResource($item, $containerQty, $status, $assetsSyncedAt);
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
        ?\DateTimeImmutable $assetsSyncedAt,
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
        $resource->assetsSyncedAt = $assetsSyncedAt;

        return $resource;
    }
}
