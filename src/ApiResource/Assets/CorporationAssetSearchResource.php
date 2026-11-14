<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Assets\AssetSearchProvider;

#[ApiResource(
    shortName: 'CorporationAssetSearch',
    description: 'Search of the corporation assets visible to the user by item, type, location or system name',
    operations: [
        new Get(
            uriTemplate: '/me/corporation/assets/search',
            provider: AssetSearchProvider::class,
            openapi: new Model\Operation(
                summary: 'Search corporation assets',
                tags: ['Inventory'],
                parameters: [
                    new Model\Parameter(name: 'q', in: 'query', required: true, schema: ['type' => 'string', 'minLength' => 2]),
                    new Model\Parameter(name: 'divisionName', in: 'query', required: false, schema: ['type' => 'string']),
                ],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
final class CorporationAssetSearchResource implements AssetBrowseResource
{
    #[ApiProperty(identifier: true)]
    public string $query = '';

    public bool $truncated = false;

    /** @var list<int> */
    public array $locationIds = [];

    /** @var list<AssetSearchHitResource> */
    public array $hits = [];
}
