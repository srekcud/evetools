<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Assets\AssetSearchProvider;

#[ApiResource(
    shortName: 'CharacterAssetSearch',
    description: 'Search of the assets of a character by item, type, location or system name',
    operations: [
        new Get(
            uriTemplate: '/me/characters/{characterId}/assets/search',
            provider: AssetSearchProvider::class,
            openapi: new Model\Operation(
                summary: 'Search character assets',
                tags: ['Inventory'],
                parameters: [
                    new Model\Parameter(name: 'q', in: 'query', required: true, schema: ['type' => 'string', 'minLength' => 2]),
                ],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
final class CharacterAssetSearchResource implements AssetBrowseResource
{
    #[ApiProperty(identifier: true)]
    public string $characterId;

    public string $query = '';

    public bool $truncated = false;

    /** @var list<int> */
    public array $locationIds = [];

    /** @var list<AssetSearchHitResource> */
    public array $hits = [];
}
