<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectPlanningResource;

/**
 * @implements ProviderInterface<ProjectPlanningResource>
 */
final readonly class ProjectPlanningProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectPlanningResource
    {
        throw new \LogicException('Not implemented');
    }
}
