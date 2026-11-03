<?php

declare(strict_types=1);

namespace App\ApiResource\MiningOperation;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\MiningOperation\MiningBoostShipProvider;

#[ApiResource(
    shortName: 'MiningBoostShip',
    description: 'Boost ships of a mining operation and the compressors they can mount, read from the SDE',
    operations: [
        new GetCollection(
            uriTemplate: '/mining-boost-ships',
            provider: MiningBoostShipProvider::class,
            openapi: new Model\Operation(
                summary: 'List the boost ships with their compressors',
                tags: ['Mining Operations'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MiningBoostShipResource
{
    public int $typeId = 0;

    public string $name = '';

    /** @var list<array{kind: string, typeId: int, name: string}> */
    public array $compressors = [];
}
