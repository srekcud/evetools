<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\ProjectResource;

/**
 * @implements ProviderInterface<ProjectResource>
 */
class ProjectDeleteProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectResource
    {
        $resource = new ProjectResource();
        $resource->id = (string) $uriVariables['id'];

        return $resource;
    }
}
