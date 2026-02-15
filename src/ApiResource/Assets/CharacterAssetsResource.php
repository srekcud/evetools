<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\Assets\RefreshCharacterAssetsProcessor;
use App\State\Provider\Assets\CharacterAssetsProvider;

#[ApiResource(
    shortName: 'CharacterAssets',
    description: 'Character assets inventory',
    operations: [
        new Get(
            uriTemplate: '/me/characters/{characterId}/assets',
            provider: CharacterAssetsProvider::class,
            openapi: new Model\Operation(
                summary: 'Get character assets',
                description: 'Returns all assets for a specific character',
                parameters: [
                    new Model\Parameter(name: 'locationId', in: 'query', required: false, schema: ['type' => 'integer']),
                ],
            ),
        ),
        new Post(
            uriTemplate: '/me/characters/{characterId}/assets/refresh',
            processor: RefreshCharacterAssetsProcessor::class,
            input: EmptyInput::class,
            output: SyncStatusResource::class,
            openapi: new Model\Operation(
                summary: 'Refresh character assets',
                description: 'Triggers a sync of character assets from ESI',
                parameters: [
                    new Model\Parameter(name: 'async', in: 'query', required: false, schema: ['type' => 'boolean', 'default' => true]),
                ],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class CharacterAssetsResource
{
    #[ApiProperty(identifier: true)]
    public string $characterId;

    public int $total = 0;

    /** @var AssetItemResource[] */
    public array $items = [];
}
