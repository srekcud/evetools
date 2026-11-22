<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectStationQuantitiesProvider;

#[ApiResource(
    shortName: 'ProjectStationQuantities',
    description: 'Where the quantity of each type of the project BOM is, station by station',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/projects/{projectId}/station-quantities',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectStationQuantitiesProvider::class,
            paginationEnabled: false,
            openapi: new Model\Operation(
                summary: 'Read the project BOM quantities by station',
                description: 'Returns one entry per BOM type: needed, consumed, in production, on site, out and to bring at each station, null when it cannot be read, never a zero',
                tags: ['Group Industry - Containers'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectStationQuantitiesResource
{
    #[ApiProperty(identifier: true)]
    public int $typeId = 0;

    public string $typeName = '';

    /**
     * @var list<string>
     */
    public array $lineIds = [];

    public ?string $unknownReason = null;

    /**
     * @var list<array{locationId: ?int, stationName: ?string, needed: int, consumed: int, inProduction: int, onSite: ?int, out: ?int, toBring: ?int, inTransit: ?int, unknownReason: ?string}>|null
     */
    public ?array $stations = null;
}
