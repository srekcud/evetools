<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Planetary\PlanetarySupplyHoldersProvider;

#[ApiResource(
    shortName: 'PlanetarySupplyHolders',
    description: 'What each character of the connected user holds at the PI home location, for the input types of the launchpads',
    operations: [
        new Get(
            uriTemplate: '/planetary/supply-holders',
            provider: PlanetarySupplyHoldersProvider::class,
            openapi: new Model\Operation(summary: 'Get the stock of each character at the home location', tags: ['Planetary']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
)]
final class PlanetarySupplyHoldersResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'supply-holders';

    public ?int $homeLocationId = null;

    public ?string $homeLocationName = null;

    /** @var list<array<string, mixed>> */
    public array $characters = [];
}
