<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Assets\AssetChangesProvider;

#[ApiResource(
    shortName: 'CorporationAssetChanges',
    description: 'Changes between the last two asset syncs of the corporation divisions visible to the user',
    operations: [
        new Get(
            uriTemplate: '/me/corporation/assets/changes',
            provider: AssetChangesProvider::class,
            openapi: new Model\Operation(
                summary: 'Get what changed in the visible corporation divisions since the previous sync',
                tags: ['Inventory'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class CorporationAssetChangesResource extends AssetChangesPayload
{
    #[ApiProperty(identifier: true)]
    public string $id = 'corporation';
}
