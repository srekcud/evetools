<?php

declare(strict_types=1);

namespace App\ApiResource\ShoppingList;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\ShoppingList\SyncMarketInput;
use App\State\Processor\ShoppingList\SyncMarketProcessor;

#[ApiResource(
    shortName: 'ShoppingListSyncMarket',
    description: 'Sync structure market data',
    operations: [
        new Post(
            uriTemplate: '/shopping-list/sync-structure-market',
            processor: SyncMarketProcessor::class,
            input: SyncMarketInput::class,
            openapiContext: [
                'summary' => 'Sync structure market data',
            ],
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class SyncMarketResultResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'sync';

    public bool $success = false;

    public ?int $structureId = null;

    public ?string $structureName = null;

    public int $totalOrders = 0;

    public int $sellOrders = 0;

    public int $typeCount = 0;

    public ?string $error = null;
}
