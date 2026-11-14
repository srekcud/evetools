<?php

declare(strict_types=1);

namespace App\State\Provider\Assets;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Assets\AssetBrowseResource;
use App\ApiResource\Assets\CharacterAssetContainerContentsResource;
use App\ApiResource\Assets\CorporationAssetContainerContentsResource;
use App\Repository\AssetBrowseRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<AssetBrowseResource>
 */
final readonly class AssetContainerContentsProvider implements ProviderInterface
{
    public function __construct(
        private AssetBrowseOwnerResolver $ownerResolver,
        private AssetBrowseRepository $repository,
        private AssetBrowseItemMapper $itemMapper,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CharacterAssetContainerContentsResource|CorporationAssetContainerContentsResource
    {
        $owner = $this->ownerResolver->resolve($uriVariables);
        $itemId = (int) $uriVariables['itemId'];

        $rows = $this->repository->contentsOf($owner->scope, $itemId) ?? throw new NotFoundHttpException('Container not found');

        if ($owner->corporationId === null) {
            $resource = new CharacterAssetContainerContentsResource();
            $resource->characterId = $owner->scope->characterId ?? throw new \LogicException('A character scope carries its id');
        } else {
            $resource = new CorporationAssetContainerContentsResource();
        }

        $resource->itemId = $itemId;
        $resource->items = $this->itemMapper->items($owner, $rows);

        return $resource;
    }
}
