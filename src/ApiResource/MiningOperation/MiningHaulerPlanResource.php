<?php

declare(strict_types=1);

namespace App\ApiResource\MiningOperation;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\MiningOperation\EmptyMiningFleetHangarProcessor;
use App\State\Provider\MiningOperation\MiningHaulerPlanProvider;

#[ApiResource(
    shortName: 'MiningHaulerPlan',
    description: 'Estimated filling of the fleet hangar of the boost ship of a mining operation and trips to plan',
    operations: [
        new Get(
            uriTemplate: '/mining-operations/{operationId}/hauler-plan',
            uriVariables: [
                'operationId' => new Link(fromClass: MiningOperationResource::class),
            ],
            provider: MiningHaulerPlanProvider::class,
            openapi: new Model\Operation(
                summary: 'Get the hauler plan of a mining operation',
                description: 'Returns the estimated filling of the fleet hangar and the trips to plan before the end of the site (participants only)',
                tags: ['Mining Operations - Hauler plan'],
            ),
        ),
        new Post(
            uriTemplate: '/mining-operations/{operationId}/hauler-plan/emptied',
            uriVariables: [
                'operationId' => new Link(fromClass: MiningOperationResource::class),
            ],
            processor: EmptyMiningFleetHangarProcessor::class,
            input: EmptyInput::class,
            deserialize: false,
            status: 200,
            openapi: new Model\Operation(
                summary: 'Declare the fleet hangar emptied',
                description: 'Restarts the filling estimate from now (fleet storage recipient, operation owner or admin)',
                tags: ['Mining Operations - Hauler plan'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MiningHaulerPlanResource
{
    public ?string $operationId = null;

    public string $state = 'unknown';

    public ?string $unknownReason = null;

    public ?string $boostShipName = null;

    public ?string $haulerName = null;

    public ?\DateTimeImmutable $emptiedAt = null;

    public bool $originIsStart = true;

    public ?float $capacityM3 = null;

    public ?float $holdCapacityM3 = null;

    public ?float $share = null;

    public ?float $inflowM3PerMinute = null;

    public ?float $fillM3 = null;

    public ?float $fillRatio = null;

    public ?float $fullInMinutes = null;

    public ?bool $isFull = null;

    public ?float $transportM3PerMinute = null;

    public ?float $toMoveM3 = null;

    public ?int $trips = null;

    public ?\DateTimeImmutable $siteEtaAt = null;

    /** @var list<int> */
    public array $rawTypeIds = [];

    /** @var list<string> */
    public array $contributorNames = [];
}
