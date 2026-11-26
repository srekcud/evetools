<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Planetary\PlanetaryPendingStockProvider;

#[ApiResource(
    shortName: 'PlanetaryPendingStock',
    description: 'Planetary Interaction pending stock in the storages of the colonies of the user',
    operations: [
        new Get(
            uriTemplate: '/planetary/pending-stock',
            provider: PlanetaryPendingStockProvider::class,
            openapi: new Model\Operation(summary: 'Get PI storage stock and pending outputs per colony', tags: ['Planetary']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class PlanetaryPendingStockResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'pending-stock';

    /** @var list<array<string, mixed>> */
    public array $storages = [];

    /** @var list<array<string, mixed>> */
    public array $pendingOutputs = [];

    public bool $complete = true;
}
