<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectTransportLegsProvider;

#[ApiResource(
    shortName: 'ProjectTransportLegs',
    description: 'The hauls to make between the stations of a project: what to carry, from where, to where',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/transport-legs',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectTransportLegsProvider::class,
            paginationEnabled: false,
            openapi: new Model\Operation(
                summary: 'Read the hauls to make between the stations of the project',
                description: 'Returns one leg per departure and arrival station with its lines (ready, in production, in transit), the volume, the access of the reader characters and the readings that could not be made, never a zero for an unknown',
                tags: ['Group Industry - Containers'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectTransportLegsResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public ?string $personalStockUnknownReason = null;

    /**
     * @var list<array{typeId: int, typeName: string, locationId: ?int, reason: string}>
     */
    public array $unreadable = [];

    /**
     * @var list<array{key: string, departure: array{locationId: ?int, stationName: ?string, solarSystemId: ?int, solarSystemName: ?string}, arrival: array{locationId: ?int, stationName: ?string, solarSystemId: ?int, solarSystemName: ?string}, access: string, carrierCharacterIds: list<int>, volume: ?float, lines: list<array{typeId: int, typeName: string, quantity: ?int, state: string, source: string, readyAt: ?string, unitVolume: ?float, volume: ?float, unknownReason: ?string}>}>
     */
    public array $legs = [];
}
