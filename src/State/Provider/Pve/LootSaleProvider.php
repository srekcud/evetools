<?php

declare(strict_types=1);

namespace App\State\Provider\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Pve\LootSaleResource;

/**
 * @implements ProviderInterface<LootSaleResource>
 */
class LootSaleProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): LootSaleResource
    {
        $resource = new LootSaleResource();
        $resource->id = (string) $uriVariables['id'];

        return $resource;
    }
}
