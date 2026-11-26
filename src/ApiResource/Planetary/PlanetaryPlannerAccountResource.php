<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Planetary\PlanetaryPlannerAccountProvider;

#[ApiResource(
    shortName: 'PlanetaryPlannerAccount',
    description: 'Planets used, planet slots per character and extraction yields of the connected user, for the production planner',
    operations: [
        new Get(
            uriTemplate: '/planetary/planner/account',
            provider: PlanetaryPlannerAccountProvider::class,
            openapi: new Model\Operation(summary: 'Get the planets used, slots and extraction yields of the connected user', tags: ['Planetary']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
final class PlanetaryPlannerAccountResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'account';

    public int $usedPlanets = 0;

    /** @var list<array{characterId: int, characterName: string, usedPlanets: int, maxPlanets: int|null}> */
    public array $characters = [];

    /** @var list<array{typeId: int, perHourPerPlanet: float, planets: int}> */
    public array $extractionYields = [];

    /** @var array{P1: int, P2: int, P3: int, P4: int} */
    public array $factories = ['P1' => 0, 'P2' => 0, 'P3' => 0, 'P4' => 0];
}
