<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use App\State\Provider\Industry\Project\ProjectIntermediateStockProposalProvider;

#[ApiResource(
    shortName: 'ProjectIntermediateStockProposal',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/intermediate-stock-proposal',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectIntermediateStockProposalProvider::class,
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectIntermediateStockProposalResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public string $state = 'ready';

    public ?string $unknownReason = null;

    /**
     * @var list<array{bomItemId: string, typeId: int, typeName: string, demandQuantity: int, remainingQuantity: int, personalQuantity: int, containerQuantity: int|null, stockQuantity: int, proposedQuantity: int}>
     */
    public array $lines = [];
}
