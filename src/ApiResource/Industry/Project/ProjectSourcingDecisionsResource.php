<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectSourcingDecisionsProvider;

#[ApiResource(
    shortName: 'ProjectSourcingDecisions',
    description: 'Bottom-up buy or build decision of every intermediate of a project, at the cost delivered to the station that consumes it',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/sourcing-decisions',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectSourcingDecisionsProvider::class,
            openapi: new Model\Operation(
                summary: 'Decide buy or build for each intermediate of the project, from the base materials up',
                description: 'Returns one row per base material and per intermediate with the build, Jita plus import and capital market options at their delivered cost, the recommended and the chosen option, and the totals of the recalculated plan. The optional buy and build query parameters (comma separated type ids) replace the stored choices for this reading only.',
                tags: ['Group Industry - Sourcing decisions'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectSourcingDecisionsResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    /**
     * @var list<array<string, mixed>>
     */
    public array $rows = [];

    /**
     * @var array<string, mixed>
     */
    public array $totals = [];

    /**
     * @var list<int>
     */
    public array $boughtTypeIds = [];
}
