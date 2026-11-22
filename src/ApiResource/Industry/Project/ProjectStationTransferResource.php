<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\Input\Industry\Project\DeclareStationTransferInput;
use App\State\Processor\Industry\Project\ArriveStationTransferProcessor;
use App\State\Processor\Industry\Project\DeclareStationTransferProcessor;
use App\State\Processor\Industry\Project\DeleteStationTransferProcessor;
use App\State\Provider\Industry\Project\ProjectStationTransferItemProvider;
use App\State\Provider\Industry\Project\ProjectStationTransfersProvider;

#[ApiResource(
    shortName: 'ProjectStationTransfer',
    description: 'One leg of the movement of project items between two places, declared by a participant and followed through the project containers',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/projects/{projectId}/station-transfers',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectStationTransfersProvider::class,
            paginationEnabled: false,
            openapi: new Model\Operation(
                summary: 'List the station transfers of a project',
                description: 'Returns every leg with its state, volume in cubic meters and, per line, what departed, arrived and is in transit, null when it cannot be read, never a zero',
                tags: ['Group Industry - Containers'],
            ),
        ),
        new Post(
            uriTemplate: '/industry/projects/{projectId}/station-transfers',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            processor: DeclareStationTransferProcessor::class,
            input: DeclareStationTransferInput::class,
            openapi: new Model\Operation(
                summary: 'Declare a station transfer',
                description: 'Declares items moving from a departure to an arrival and creates the legs of the route through the courier hub (courier from Jita to the hub, jump bridge from the hub to the station); the response is the first leg',
                tags: ['Group Industry - Containers'],
            ),
        ),
        new Post(
            uriTemplate: '/industry/projects/{projectId}/station-transfers/{id<[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}>}/arrive',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            status: 200,
            processor: ArriveStationTransferProcessor::class,
            input: EmptyInput::class,
            deserialize: false,
            openapi: new Model\Operation(
                summary: 'Mark a station transfer leg as arrived',
                description: 'Declares by hand that the leg arrived (declarer, project owner or admin); the leg before it must have arrived',
                tags: ['Group Industry - Containers'],
            ),
        ),
        new Delete(
            uriTemplate: '/industry/projects/{projectId}/station-transfers/{id<[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}>}',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            provider: ProjectStationTransferItemProvider::class,
            processor: DeleteStationTransferProcessor::class,
            openapi: new Model\Operation(
                summary: 'Delete a station transfer leg',
                description: 'Deletes the leg and the legs after it on the same route (declarer, project owner or admin); a leg that arrived is never deleted',
                tags: ['Group Industry - Containers'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectStationTransferResource
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    public string $routeId = '';

    public int $legIndex = 0;

    public int $legCount = 0;

    /**
     * @var array{solarSystemId: int, solarSystemName: ?string, locationId: ?int, stationName: ?string}
     */
    public array $from = ['solarSystemId' => 0, 'solarSystemName' => null, 'locationId' => null, 'stationName' => null];

    /**
     * @var array{solarSystemId: int, solarSystemName: ?string, locationId: ?int, stationName: ?string}
     */
    public array $to = ['solarSystemId' => 0, 'solarSystemName' => null, 'locationId' => null, 'stationName' => null];

    /**
     * @var array{kind: string, characterId: ?int, contractId: ?int}
     */
    public array $carrier = ['kind' => '', 'characterId' => null, 'contractId' => null];

    public ?string $declaredBy = null;

    public string $declaredAt = '';

    public string $expectedArrivalAt = '';

    public ?string $arrivedAt = null;

    public string $state = '';

    public ?float $volume = null;

    public ?string $unknownReason = null;

    /**
     * @var list<array{typeId: int, typeName: string, quantity: int, volume: ?float, departed: ?int, arrived: ?int, inTransit: ?int, departureSource: string, arrivalSource: string, unknownReason: ?string}>
     */
    public array $lines = [];
}
