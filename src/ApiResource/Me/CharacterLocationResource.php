<?php

declare(strict_types=1);

namespace App\ApiResource\Me;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Me\CharacterLocationCollectionProvider;

#[ApiResource(
    shortName: 'CharacterLocation',
    description: 'Current location and online status of the characters of the user',
    operations: [
        new GetCollection(
            uriTemplate: '/me/characters/locations',
            provider: CharacterLocationCollectionProvider::class,
            openapi: new Model\Operation(summary: 'Locate user characters', description: 'Returns the current location and online status of each character of the authenticated user, read from ESI with a short cache', tags: ['Account & Characters']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
)]
class CharacterLocationResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public int $eveCharacterId;

    public ?int $solarSystemId = null;

    public ?int $stationId = null;

    public ?int $structureId = null;

    public ?bool $online = null;

    public ?string $lastLoginAt = null;
}
