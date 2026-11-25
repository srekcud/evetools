<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Planetary\PlanetarySlotsProvider;

#[ApiResource(
    shortName: 'PlanetarySlots',
    description: 'Colony slots used and available per character, from the Interplanetary Consolidation skill',
    operations: [
        new Get(
            uriTemplate: '/planetary/slots',
            provider: PlanetarySlotsProvider::class,
            openapi: new Model\Operation(summary: 'Get the colony slots used and available per character and in total', tags: ['Planetary']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
)]
final class PlanetarySlotsResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'slots';

    /** @var list<array{characterId: int, characterName: string, isMain: bool, coloniesUsed: int, consolidationLevel: int|null, maxColonies: int|null}> */
    public array $characters = [];

    public int $totalUsed = 0;

    public ?int $totalMax = null;
}
