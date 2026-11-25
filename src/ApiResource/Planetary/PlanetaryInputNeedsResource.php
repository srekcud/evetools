<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Planetary\PlanetaryInputNeedsProvider;

#[ApiResource(
    shortName: 'PlanetaryInputNeeds',
    description: 'Planetary Interaction input needs per colony, launchpad and user',
    operations: [
        new Get(
            uriTemplate: '/planetary/input-needs',
            provider: PlanetaryInputNeedsProvider::class,
            openapi: new Model\Operation(summary: 'Get PI input needs per colony, launchpad and user', tags: ['Planetary']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class PlanetaryInputNeedsResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'input-needs';

    /** @var list<array<string, mixed>> */
    public array $colonies = [];

    /** @var list<array<string, mixed>> */
    public array $launchpads = [];

    /** @var list<array<string, mixed>> */
    public array $extractions = [];

    /** @var list<array<string, mixed>> */
    public array $weekly = [];

    public bool $complete = true;
}
