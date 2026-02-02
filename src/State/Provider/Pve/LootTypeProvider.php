<?php

declare(strict_types=1);

namespace App\State\Provider\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Pve\LootTypeResource;

/**
 * @implements ProviderInterface<LootTypeResource>
 */
class LootTypeProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): LootTypeResource
    {
        $resource = new LootTypeResource();
        $resource->id = (int) $uriVariables['id'];

        return $resource;
    }
}
