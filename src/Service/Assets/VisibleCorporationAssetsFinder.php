<?php

declare(strict_types=1);

namespace App\Service\Assets;

use App\Entity\CachedAsset;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CorpAssetVisibilityRepository;

class VisibleCorporationAssetsFinder
{
    public function __construct(
        private readonly CachedAssetRepository $cachedAssetRepository,
        private readonly CorpAssetVisibilityRepository $visibilityRepository,
    ) {
    }

    /**
     * @return CachedAsset[]
     */
    public function findVisibleAssets(int $corporationId, ?string $divisionName = null, ?User $viewer = null): array
    {
        $visibility = $this->visibilityRepository->findByCorporationId($corporationId);
        if ($visibility === null) {
            return [];
        }

        $allowedDivisions = $visibility->divisionsVisibleTo($viewer);
        if ($allowedDivisions === []) {
            return [];
        }

        $hangarAssets = $divisionName === null
            ? $this->cachedAssetRepository->findByCorporationAndDivisions($corporationId, $allowedDivisions)
            : $this->cachedAssetRepository->findByCorporationDivisionNameAndFlags($corporationId, $divisionName, $allowedDivisions);

        $containerContents = $this->cachedAssetRepository->findContentsOfContainers(
            $corporationId,
            array_map(static fn (CachedAsset $asset): int => $asset->getItemId(), $hangarAssets),
        );

        return [...$hangarAssets, ...$containerContents];
    }

    /**
     * @return list<string>
     */
    public function visibleDivisionNames(int $corporationId, ?User $viewer): array
    {
        $visibility = $this->visibilityRepository->findByCorporationId($corporationId);
        if ($visibility === null) {
            return [];
        }

        $divisionNamesByNumber = $this->cachedAssetRepository->findDistinctDivisions($corporationId);

        return array_values(array_unique(array_filter(array_map(
            static fn (int $division): ?string => $divisionNamesByNumber[$division] ?? null,
            $visibility->divisionsVisibleTo($viewer),
        ))));
    }
}
