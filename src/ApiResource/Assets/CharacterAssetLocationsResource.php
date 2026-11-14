<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Assets\AssetLocationsProvider;

#[ApiResource(
    shortName: 'CharacterAssetLocations',
    description: 'One summary row per location of the assets of a character',
    operations: [
        new Get(
            uriTemplate: '/me/characters/{characterId}/assets/locations',
            provider: AssetLocationsProvider::class,
            openapi: new Model\Operation(summary: 'Summarize character assets by location', tags: ['Inventory']),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
final class CharacterAssetLocationsResource implements AssetBrowseResource
{
    #[ApiProperty(identifier: true)]
    public string $characterId;

    public int $itemCount = 0;

    public int $typeCount = 0;

    public int $systemCount = 0;

    public int $locationCount = 0;

    public ?string $lastSyncedAt = null;

    /** @var list<array<string, mixed>> */
    public array $locations = [];
}
