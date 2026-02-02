<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
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
            openapiContext: [
                'summary' => 'Get corporation assets',
                'description' => 'Returns all assets for the user corporation',
                'parameters' => [
                    [
                        'name' => 'divisionName',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'string'],
                        'description' => 'Filter by hangar division name',
                    ],
                ],
            ],
        ),
        new Post(
            uriTemplate: '/me/corporation/assets/refresh',
            processor: RefreshCorporationAssetsProcessor::class,
            input: EmptyInput::class,
            output: SyncStatusResource::class,
            openapiContext: [
                'summary' => 'Refresh corporation assets',
                'description' => 'Triggers a sync of corporation assets from ESI',
                'parameters' => [
                    [
                        'name' => 'async',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'boolean', 'default' => true],
                        'description' => 'Use async processing (default: true)',
                    ],
                ],
            ],
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
