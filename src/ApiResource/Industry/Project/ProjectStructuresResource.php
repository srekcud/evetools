<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectStructuresProvider;

#[ApiResource(
    shortName: 'ProjectStructures',
    description: 'Total cost comparison of the structures of the project owner for each manufacturing or reaction product of the plan',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/structures',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectStructuresProvider::class,
            openapi: new Model\Operation(
                summary: 'Compare the structures of the project at total cost',
                description: 'Returns one group per manufactured or reacted product of the plan, with the materials, installation and transport cost of each candidate structure and the structure retained by the plan',
                tags: ['Group Industry - Structures'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectStructuresResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public bool $acceptsOutsideFavoriteSystem;

    public float $minimumOutsideSaving;

    public float $transportIskPerCubicMeterJump;

    /**
     * @var list<array<string, mixed>>
     */
    public array $groups = [];
}
