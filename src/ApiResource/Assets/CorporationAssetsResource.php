<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\Assets\RefreshCorporationAssetsProcessor;
use App\State\Provider\Assets\CorporationAssetsProvider;

#[ApiResource(
    shortName: 'CorporationAssets',
    description: 'Corporation assets inventory',
    operations: [
        new Get(
            uriTemplate: '/me/corporation/assets',
            provider: CorporationAssetsProvider::class,
            openapi: new Model\Operation(
                summary: 'Get corporation assets',
                description: 'Returns all assets for the user corporation',
                tags: ['Inventory'],
                parameters: [
                    new Model\Parameter(name: 'divisionName', in: 'query', required: false, schema: ['type' => 'string']),
                ],
            ),
        ),
        new Post(
            uriTemplate: '/me/corporation/assets/refresh',
            processor: RefreshCorporationAssetsProcessor::class,
            input: EmptyInput::class,
            output: SyncStatusResource::class,
            openapi: new Model\Operation(
                summary: 'Refresh corporation assets',
                description: 'Triggers a sync of corporation assets from ESI',
                tags: ['Inventory'],
                parameters: [
                    new Model\Parameter(name: 'async', in: 'query', required: false, schema: ['type' => 'boolean', 'default' => true]),
                ],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class CorporationAssetsResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'corporation';

    public int $total = 0;

    /** @var AssetItemResource[] */
    public array $items = [];
}
