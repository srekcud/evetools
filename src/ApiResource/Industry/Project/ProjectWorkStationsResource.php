<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectWorkStationsProvider;

#[ApiResource(
    shortName: 'ProjectWorkStations',
    description: 'Station comparison for each type of work of the plan of the project',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/work-stations',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectWorkStationsProvider::class,
            openapi: new Model\Operation(
                summary: 'Compare the stations of the project for each type of work',
                description: 'Returns one entry per type of work of the plan, with the travels and the total cost of each candidate structure',
                tags: ['Group Industry - Structures'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectWorkStationsResource
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    public ?int $courierHubSolarSystemId = null;

    public ?string $courierHubSolarSystemName = null;

    public float $courierIskPerCubicMeter = 0.0;

    public float $jumpBridgeIskPerCubicMeter = 0.0;

    /**
     * @var list<array<string, mixed>>
     */
    public array $workTypes = [];

    /**
     * @var list<array{id: string, name: string, structureType: string}>
     */
    public array $structuresToSpecify = [];
}
