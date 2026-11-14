<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Assets\AssetLocationContentsProvider;

#[ApiResource(
    shortName: 'CharacterAssetLocationContents',
    description: 'Root items of one location of the assets of a character',
    operations: [
        new Get(
            uriTemplate: '/me/characters/{characterId}/assets/locations/{locationId}',
            provider: AssetLocationContentsProvider::class,
            openapi: new Model\Operation(summary: 'List the root items of a character asset location', tags: ['Inventory']),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
final class CharacterAssetLocationContentsResource implements AssetBrowseResource
{
    #[ApiProperty(identifier: true)]
    public string $characterId;

    #[ApiProperty(identifier: true)]
    public int $locationId;

    /** @var list<AssetBrowseItemResource> */
    public array $items = [];
}
