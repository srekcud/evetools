<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Blueprint;

use App\Entity\CachedAsset;
use App\Entity\OwnedBlueprint;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use App\Service\Industry\Project\AssetStation;
use App\Service\Industry\Project\AssetStations;
use App\Service\Industry\Project\ProjectContainerService;

final class BlueprintStationLocator
{
    /** @var array<int, array{assetsByItemId: array<int, CachedAsset>, stations: AssetStations}> */
    private array $personalByCharacterId = [];

    /** @var array<string, array{assetsByItemId: array<int, CachedAsset>, stations: AssetStations}> */
    private array $corporationByViewerAndCorporation = [];

    public function __construct(
        private readonly CachedAssetRepository $assetRepository,
        private readonly VisibleCorporationAssetsFinder $visibleAssetsFinder,
        private readonly ProjectContainerService $containerService,
    ) {
    }

    public function stationOf(OwnedBlueprint $blueprint, User $viewer): AssetStation|string
    {
        $character = $blueprint->getCharacter();
        if (null !== $character) {
            $source = $this->personalByCharacterId[$character->getEveCharacterId()] ??= $this->sourceOf(
                $this->assetRepository->findByCharacter($character),
                $character->getCorporationId(),
            );

            return $this->stationIn($source, $blueprint);
        }

        $corporationId = $blueprint->getCorporationId();
        if (null === $corporationId) {
            return LineBlueprintPosition::REASON_ASSET_NOT_FOUND;
        }

        $unavailabilityReason = $this->containerService->unavailabilityReasonOfUser($viewer);
        if (null !== $unavailabilityReason) {
            return $unavailabilityReason;
        }

        $key = $viewer->getId()?->toRfc4122().':'.$corporationId;
        $source = $this->corporationByViewerAndCorporation[$key] ??= $this->sourceOf(
            $this->visibleAssetsFinder->findVisibleAssets($corporationId, null, $viewer),
            $corporationId,
        );

        return $this->stationIn($source, $blueprint);
    }

    /**
     * @param CachedAsset[] $assets
     *
     * @return array{assetsByItemId: array<int, CachedAsset>, stations: AssetStations}
     */
    private function sourceOf(array $assets, int $corporationId): array
    {
        $assetsByItemId = [];
        foreach ($assets as $asset) {
            $assetsByItemId[$asset->getItemId()] = $asset;
        }

        return ['assetsByItemId' => $assetsByItemId, 'stations' => new AssetStations($assets, $this->assetRepository, $corporationId)];
    }

    /**
     * @param array{assetsByItemId: array<int, CachedAsset>, stations: AssetStations} $source
     */
    private function stationIn(array $source, OwnedBlueprint $blueprint): AssetStation|string
    {
        $asset = $source['assetsByItemId'][$blueprint->getItemId()] ?? null;
        if (null === $asset) {
            return LineBlueprintPosition::REASON_ASSET_NOT_FOUND;
        }

        return $source['stations']->locationOf($asset) ?? LineBlueprintPosition::REASON_STATION_UNRESOLVED;
    }
}
