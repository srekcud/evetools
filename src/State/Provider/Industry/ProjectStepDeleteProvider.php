<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\ProjectStepResource;

/**
 * @implements ProviderInterface<ProjectStepResource>
 */
class ProjectStepDeleteProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectStepResource
    {
        $resource = new ProjectStepResource();
        $resource->id = (string) $uriVariables['stepId'];

        return $resource;
    }
}
