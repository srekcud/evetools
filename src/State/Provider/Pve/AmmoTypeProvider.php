<?php

declare(strict_types=1);

namespace App\State\Provider\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Pve\AmmoTypeResource;

/**
 * @implements ProviderInterface<AmmoTypeResource>
 */
class AmmoTypeProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AmmoTypeResource
    {
        $resource = new AmmoTypeResource();
        $resource->id = (int) $uriVariables['id'];

        return $resource;
    }
}
