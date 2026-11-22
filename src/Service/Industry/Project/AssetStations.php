<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\CachedAsset;
use App\Repository\CachedAssetRepository;

final class AssetStations
{
    private const string ITEM_LOCATION_TYPE = 'item';

    /** @var array<int, CachedAsset> */
    private array $visibleAssetsByItemId = [];

    /** @var array<int, CachedAsset>|null */
    private ?array $officesByItemId = null;

    /**
     * @param CachedAsset[] $visibleAssets
     */
    public function __construct(
        array $visibleAssets,
        private readonly CachedAssetRepository $cachedAssetRepository,
        private readonly int $corporationId,
    ) {
        foreach ($visibleAssets as $asset) {
            $this->visibleAssetsByItemId[$asset->getItemId()] = $asset;
        }
    }

    public function of(CachedAsset $asset): ?int
    {
        return $this->locationOf($asset)?->locationId;
    }

    public function locationOf(CachedAsset $asset): ?AssetStation
    {
        $top = $this->topOf($asset);
        if (null === $top) {
            return null;
        }

        if (self::ITEM_LOCATION_TYPE !== $top->getLocationType()) {
            return new AssetStation($top->getLocationId(), $top->getLocationName(), $top->getSolarSystemName());
        }

        $office = $this->offices()[$top->getLocationId()] ?? null;
        if (null === $office || self::ITEM_LOCATION_TYPE === $office->getLocationType()) {
            return null;
        }

        return new AssetStation($office->getLocationId(), $office->getLocationName(), $office->getSolarSystemName());
    }

    private function topOf(CachedAsset $asset): ?CachedAsset
    {
        $visitedItemIds = [];
        $current = $asset;
        while (self::ITEM_LOCATION_TYPE === $current->getLocationType() && isset($this->visibleAssetsByItemId[$current->getLocationId()])) {
            if (isset($visitedItemIds[$current->getItemId()])) {
                return null;
            }

            $visitedItemIds[$current->getItemId()] = true;
            $current = $this->visibleAssetsByItemId[$current->getLocationId()];
        }

        return $current;
    }

    /**
     * @return array<int, CachedAsset>
     */
    private function offices(): array
    {
        if (null !== $this->officesByItemId) {
            return $this->officesByItemId;
        }

        $officeItemIds = [];
        foreach ($this->visibleAssetsByItemId as $asset) {
            if (self::ITEM_LOCATION_TYPE === $asset->getLocationType() && !isset($this->visibleAssetsByItemId[$asset->getLocationId()])) {
                $officeItemIds[$asset->getLocationId()] = true;
            }
        }

        $this->officesByItemId = $this->cachedAssetRepository->findCorporationByItemIds($this->corporationId, array_keys($officeItemIds));

        return $this->officesByItemId;
    }
}
