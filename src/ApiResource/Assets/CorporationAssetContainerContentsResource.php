<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Assets\AssetContainerContentsProvider;

#[ApiResource(
    shortName: 'CorporationAssetContainerContents',
    description: 'Direct contents of one container of the corporation assets visible to the user',
    operations: [
        new Get(
            uriTemplate: '/me/corporation/assets/containers/{itemId}',
            provider: AssetContainerContentsProvider::class,
            openapi: new Model\Operation(summary: 'List the direct contents of a corporation asset container', tags: ['Inventory']),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
final class CorporationAssetContainerContentsResource implements AssetBrowseResource
{
    #[ApiProperty(identifier: true)]
    public int $itemId;

    /** @var list<AssetBrowseItemResource> */
    public array $items = [];
}
