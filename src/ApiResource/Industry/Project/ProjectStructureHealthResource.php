<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectStructureHealthProvider;

#[ApiResource(
    shortName: 'ProjectStructureHealth',
    description: 'Fuel and service state of the structures retained by the plan of a project',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/structure-health',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectStructureHealthProvider::class,
            openapi: new Model\Operation(
                summary: 'Read the health of the planned structures of the project',
                description: 'Reads the corporation structures from ESI with the first character of the caller holding the Station_Manager role; a structure that cannot be read is unknown, never ok',
                tags: ['Group Industry - Structures'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectStructureHealthResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public float $lowFuelThresholdDays;

    /**
     * @var list<array<string, mixed>>
     */
    public array $rows = [];
}
