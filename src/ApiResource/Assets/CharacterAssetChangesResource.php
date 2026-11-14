<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Assets\AssetChangesProvider;

#[ApiResource(
    shortName: 'CharacterAssetChanges',
    description: 'Changes between the last two asset syncs of a character',
    operations: [
        new Get(
            uriTemplate: '/me/characters/{characterId}/assets/changes',
            provider: AssetChangesProvider::class,
            openapi: new Model\Operation(
                summary: 'Get what changed in a character inventory since the previous sync',
                tags: ['Inventory'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class CharacterAssetChangesResource extends AssetChangesPayload
{
    #[ApiProperty(identifier: true)]
    public string $characterId = '';
}
