<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Assets\AssetLocationContentsProvider;

#[ApiResource(
    shortName: 'CorporationAssetLocationContents',
    description: 'Root items of one location of the corporation assets visible to the user',
    operations: [
        new Get(
            uriTemplate: '/me/corporation/assets/locations/{locationId}',
            provider: AssetLocationContentsProvider::class,
            openapi: new Model\Operation(
                summary: 'List the root items of a corporation asset location',
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
final class CorporationAssetLocationContentsResource implements AssetBrowseResource
{
    #[ApiProperty(identifier: true)]
    public int $locationId;

    /** @var list<AssetBrowseItemResource> */
    public array $items = [];
}
