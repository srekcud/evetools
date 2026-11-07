<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectBuyOrBuildProvider;

#[ApiResource(
    shortName: 'ProjectBuyOrBuild',
    description: 'Buy or build comparison of the intermediates of a project plan, with the Jita import cost',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/buy-or-build',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectBuyOrBuildProvider::class,
            openapi: new Model\Operation(
                summary: 'Compare building and buying each intermediate of the project',
                description: 'Returns one row per built intermediate of the plan, with the cost per unit of building, of buying at Jita and of importing it, and the cheaper choice',
                tags: ['Group Industry - Buy or build'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectBuyOrBuildResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public float $importIskPerCubicMeterJump;

    /**
     * @var list<array<string, mixed>>
     */
    public array $rows = [];
}
