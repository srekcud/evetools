<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Assets\AssetLocationsProvider;

#[ApiResource(
    shortName: 'CorporationAssetLocations',
    description: 'One summary row per location of the assets of the corporation visible to the user',
    operations: [
        new Get(
            uriTemplate: '/me/corporation/assets/locations',
            provider: AssetLocationsProvider::class,
            openapi: new Model\Operation(
                summary: 'Summarize corporation assets by location',
                tags: ['Inventory'],
                parameters: [
                    new Model\Parameter(name: 'divisionName', in: 'query', required: false, schema: ['type' => 'string']),
                ],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
final class CorporationAssetLocationsResource implements AssetBrowseResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'corporation';

    public int $itemCount = 0;

    public int $typeCount = 0;

    public int $systemCount = 0;

    public int $locationCount = 0;

    public ?string $lastSyncedAt = null;

    public ?string $nextRefreshAt = null;

    /** @var list<array<string, mixed>> */
    public array $locations = [];
}
