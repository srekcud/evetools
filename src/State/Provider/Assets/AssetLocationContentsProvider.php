<?php

declare(strict_types=1);

namespace App\State\Provider\Assets;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Assets\AssetBrowseResource;
use App\ApiResource\Assets\CharacterAssetLocationContentsResource;
use App\ApiResource\Assets\CorporationAssetLocationContentsResource;
use App\Repository\AssetBrowseRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<AssetBrowseResource>
 */
final readonly class AssetLocationContentsProvider implements ProviderInterface
{
    public function __construct(
        private AssetBrowseOwnerResolver $ownerResolver,
        private AssetBrowseRepository $repository,
        private AssetBrowseItemMapper $itemMapper,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CharacterAssetLocationContentsResource|CorporationAssetLocationContentsResource
    {
        $owner = $this->ownerResolver->resolve($uriVariables);
        $locationId = (int) $uriVariables['locationId'];

        $rows = $this->repository->rootItemsOf($owner->scope, $locationId);
        if ($rows === []) {
            throw new NotFoundHttpException('Location not found');
        }

        if ($owner->corporationId === null) {
            $resource = new CharacterAssetLocationContentsResource();
            $resource->characterId = $owner->scope->characterId ?? throw new \LogicException('A character scope carries its id');
        } else {
            $resource = new CorporationAssetLocationContentsResource();
        }

        $resource->locationId = $locationId;
        $resource->items = $this->itemMapper->items($owner, $rows);

        return $resource;
    }
}
