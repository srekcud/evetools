<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\StockpileTargetResource;

/**
 * @implements ProviderInterface<StockpileTargetResource>
 */
class StockpileTargetDeleteProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StockpileTargetResource
    {
        $resource = new StockpileTargetResource();
        $resource->id = (string) $uriVariables['id'];

        return $resource;
    }
}
