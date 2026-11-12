<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Industry\Project\DeclareT2BlueprintCopiesInput;
use App\State\Processor\Industry\Project\DeclareT2BlueprintCopiesProcessor;
use App\State\Provider\Industry\Project\ProjectT2BlueprintCopyProposalProvider;

#[ApiResource(
    shortName: 'ProjectT2BlueprintCopyProposal',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/t2-blueprint-copy-proposal',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectT2BlueprintCopyProposalProvider::class,
        ),
        new Post(
            uriTemplate: '/industry/projects/{projectId}/t2-blueprint-copies',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            status: 200,
            input: DeclareT2BlueprintCopiesInput::class,
            processor: DeclareT2BlueprintCopiesProcessor::class,
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectT2BlueprintCopyProposalResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public string $state = 'ready';

    public ?string $unknownReason = null;

    /**
     * @var list<array{productTypeId: int, typeName: string, manufacturingRuns: int, personalRuns: int, containerRuns: int|null, availableRuns: int, proposedRuns: int, declaredRuns: int}>
     */
    public array $lines = [];
}
