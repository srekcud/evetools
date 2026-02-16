<?php

declare(strict_types=1);

namespace App\State\Provider\Market;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Market\MarketGroupResource;
use App\Repository\Sde\InvMarketGroupRepository;

/**
 * @implements ProviderInterface<MarketGroupResource>
 */
class MarketGroupProvider implements ProviderInterface
{
    public function __construct(
        private readonly InvMarketGroupRepository $marketGroupRepository,
    ) {
    }

    /**
     * @return MarketGroupResource[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $rootGroups = $this->marketGroupRepository->findRootGroups();

        return array_map(static function ($group) {
            $resource = new MarketGroupResource();
            $resource->id = $group->getMarketGroupId();
            $resource->name = $group->getMarketGroupName();
            $resource->parentId = null;
            $resource->hasChildren = !$group->getChildren()->isEmpty();
            $resource->hasTypes = $group->hasTypes();

            return $resource;
        }, $rootGroups);
    }
}
