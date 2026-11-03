<?php

declare(strict_types=1);

namespace App\Service\Assets;

use App\Entity\CachedAsset;

final readonly class CorporationContainerContents
{
    /**
     * @param list<CachedAsset> $visibleAssets
     *
     * @return array<int, int>|null typeId => total quantity across every container of that name, null when no container has that name
     */
    public function quantitiesIn(array $visibleAssets, string $containerName): ?array
    {
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
                $quantities[$content->getTypeId()] = ($quantities[$content->getTypeId()] ?? 0) + $content->getQuantity();
                $pendingContainerItemIds[] = $content->getItemId();
            }
        }

        return $quantities;
    }
}
