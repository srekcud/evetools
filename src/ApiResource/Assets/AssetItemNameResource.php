<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\Assets\ResolveAssetItemNameProcessor;

#[ApiResource(
    shortName: 'AssetItemName',
    operations: [
        new Post(
            uriTemplate: '/me/assets/{id}/item-name',
            uriVariables: ['id' => new Link(fromClass: self::class)],
            processor: ResolveAssetItemNameProcessor::class,
            input: EmptyInput::class,
            deserialize: false,
            status: 200,
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class AssetItemNameResource
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    public string $status = '';

    public ?string $itemName = null;
}
