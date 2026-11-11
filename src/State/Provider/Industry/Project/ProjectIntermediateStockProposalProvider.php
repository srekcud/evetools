<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectIntermediateStockProposalResource;

/**
 * @implements ProviderInterface<ProjectIntermediateStockProposalResource>
 */
final readonly class ProjectIntermediateStockProposalProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectIntermediateStockProposalResource
    {
        $resource = new ProjectIntermediateStockProposalResource();
        $resource->id = (string) ($uriVariables['projectId'] ?? '');

        return $resource;
    }
}
