<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\BpcPriceResource;

/**
 * @implements ProviderInterface<BpcPriceResource>
 */
class BpcPriceDeleteProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BpcPriceResource
    {
        $resource = new BpcPriceResource();
        $resource->blueprintTypeId = (int) $uriVariables['blueprintTypeId'];

        return $resource;
    }
}
