<?php

declare(strict_types=1);

namespace App\State\Provider\Assets;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Assets\AssetBrowseResource;
use App\ApiResource\Assets\CharacterAssetLocationsResource;
use App\ApiResource\Assets\CorporationAssetLocationsResource;
use App\Repository\AssetBrowseRepository;
use App\Service\Sync\CorporationAssetsRefreshGate;

/**
 * @implements ProviderInterface<AssetBrowseResource>
 */
final readonly class AssetLocationsProvider implements ProviderInterface
{
    public function __construct(
        private AssetBrowseOwnerResolver $ownerResolver,
        private AssetBrowseRepository $repository,
        private CorporationAssetsRefreshGate $refreshGate,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CharacterAssetLocationsResource|CorporationAssetLocationsResource
    {
        $owner = $this->ownerResolver->resolve($uriVariables);
        $totals = $this->repository->totals($owner->scope);
        $locations = $this->repository->summarizeLocations($owner->scope);

        if ($owner->corporationId === null) {
            $resource = new CharacterAssetLocationsResource();
            $resource->characterId = $owner->scope->characterId ?? throw new \LogicException('A character scope carries its id');
        } else {
            $resource = new CorporationAssetLocationsResource();
            $resource->nextRefreshAt = $this->refreshGate->nextRefreshAt($owner->corporationId)?->format('c');
        }

        $resource->itemCount = $totals['itemCount'];
        $resource->typeCount = $totals['typeCount'];
        $resource->systemCount = $totals['systemCount'];
        $resource->locationCount = $totals['locationCount'];
        $resource->lastSyncedAt = $totals['lastSyncedAt'];
        $resource->locations = $locations;

        return $resource;
    }
}
