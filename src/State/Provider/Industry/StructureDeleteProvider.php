<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\StructureConfigResource;

/**
 * @implements ProviderInterface<StructureConfigResource>
 */
class StructureDeleteProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StructureConfigResource
    {
        $resource = new StructureConfigResource();
        $resource->id = (string) $uriVariables['id'];

        return $resource;
    }
}
