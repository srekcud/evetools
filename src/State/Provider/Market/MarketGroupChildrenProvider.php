<?php

declare(strict_types=1);

namespace App\State\Provider\Market;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Market\MarketGroupResource;
use App\Repository\Sde\InvMarketGroupRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<MarketGroupResource>
 */
class MarketGroupChildrenProvider implements ProviderInterface
{
    public function __construct(
        private readonly InvMarketGroupRepository $marketGroupRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MarketGroupResource
    {
        $groupId = (int) ($uriVariables['id'] ?? 0);
        $group = $this->marketGroupRepository->find($groupId);

        if ($group === null) {
            throw new NotFoundHttpException('Market group not found');
        }

        $resource = new MarketGroupResource();
        $resource->id = $group->getMarketGroupId();
        $resource->name = $group->getMarketGroupName();
        $resource->parentId = $group->getParentGroup()?->getMarketGroupId();
        $resource->hasChildren = !$group->getChildren()->isEmpty();
        $resource->hasTypes = $group->hasTypes();

        return $resource;
    }
}
