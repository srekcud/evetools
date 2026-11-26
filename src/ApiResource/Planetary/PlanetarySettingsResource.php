<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model;
use App\State\Processor\Planetary\PlanetarySettingsProcessor;
use App\State\Provider\Planetary\PlanetarySettingsProvider;

#[ApiResource(
    shortName: 'PlanetarySettings',
    description: 'Planetary settings of the connected user: home location, hub and coverage target',
    operations: [
        new Get(
            uriTemplate: '/planetary/settings',
            provider: PlanetarySettingsProvider::class,
            openapi: new Model\Operation(summary: 'Get the planetary settings of the connected user', tags: ['Planetary']),
        ),
        new Patch(
            uriTemplate: '/planetary/settings',
            provider: PlanetarySettingsProvider::class,
            processor: PlanetarySettingsProcessor::class,
            openapi: new Model\Operation(summary: 'Update the planetary settings of the connected user', tags: ['Planetary']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
final class PlanetarySettingsResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'settings';

    public ?int $homeLocationId = null;

    #[ApiProperty(writable: false)]
    public ?string $homeLocationName = null;

    public int $coverageWeeks = 0;

    #[ApiProperty(writable: false)]
    public int $hubStructureId = 0;

    #[ApiProperty(writable: false)]
    public string $hubStructureName = '';
}
