<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Planetary\PlanetaryHomeLocationProvider;

#[ApiResource(
    shortName: 'PlanetaryHomeLocation',
    description: 'Locations where the characters of the connected user hold assets, candidates for the planetary home location',
    operations: [
        new Get(
            uriTemplate: '/planetary/settings/locations',
            provider: PlanetaryHomeLocationProvider::class,
            openapi: new Model\Operation(summary: 'List the candidate planetary home locations of the connected user', tags: ['Planetary']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
final class PlanetaryHomeLocationResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'locations';

    /** @var list<array{locationId: int, locationName: string, solarSystemName: string|null}> */
    public array $locations = [];
}
