<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Planetary\PlanetaryProjectNeedsProvider;

#[ApiResource(
    shortName: 'PlanetaryProjectNeeds',
    description: 'PI products asked by the open industry projects of the connected user',
    operations: [
        new Get(
            uriTemplate: '/planetary/project-needs',
            provider: PlanetaryProjectNeedsProvider::class,
            openapi: new Model\Operation(summary: 'Get the PI products asked by the industry projects of the connected user', tags: ['Planetary']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
)]
final class PlanetaryProjectNeedsResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'project-needs';

    /** @var list<array<string, mixed>> */
    public array $products = [];
}
