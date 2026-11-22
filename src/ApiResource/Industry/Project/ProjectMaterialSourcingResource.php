<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectMaterialSourcingProvider;

#[ApiResource(
    shortName: 'ProjectMaterialSourcing',
    description: 'Where to buy the materials still to source for a project: Jita with import or the preferred market structure of the reader',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/material-sourcing',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectMaterialSourcingProvider::class,
            openapi: new Model\Operation(
                summary: 'Compare Jita with import and the preferred market structure for the remaining materials',
                description: 'Returns one row per material line the reader reserved with a quantity left to source, with the cost at Jita, of the import, at the preferred market structure, the cheaper side and the saving',
                tags: ['Group Industry - Material sourcing'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectMaterialSourcingResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public float $importIskPerCubicMeterJump;

    public ?float $courierIskPerCubicMeter = null;

    public ?float $jumpBridgeIskPerCubicMeter = null;

    public ?string $courierHubSolarSystemName = null;

    public ?int $structureId = null;

    public ?string $structureName = null;

    public bool $structureSynced = false;

    public ?string $structureLastSync = null;

    public bool $hasReservations = false;

    /**
     * @var array{jitaWithImport: ?float, structure: ?float, structureDelivered: ?float, best: ?float, selected: ?float}
     */
    public array $totals = ['jitaWithImport' => null, 'structure' => null, 'structureDelivered' => null, 'best' => null, 'selected' => null];

    /**
     * @var list<array<string, mixed>>
     */
    public array $rows = [];
}
