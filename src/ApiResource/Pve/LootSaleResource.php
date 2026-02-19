<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Pve\CreateLootSaleInput;
use App\ApiResource\Input\Pve\ImportLootContractsInput;
use App\ApiResource\Input\Pve\ImportLootSalesInput;
use App\State\Processor\Pve\CreateLootSaleProcessor;
use App\State\Processor\Pve\DeleteLootSaleProcessor;
use App\State\Processor\Pve\ImportLootContractsProcessor;
use App\State\Processor\Pve\ImportLootSalesProcessor;
use App\State\Provider\Pve\LootSaleCollectionProvider;
use App\State\Provider\Pve\LootSaleProvider;

#[ApiResource(
    shortName: 'PveLootSale',
    description: 'PVE loot sales',
    operations: [
        new GetCollection(
            uriTemplate: '/pve/loot-sales',
            provider: LootSaleCollectionProvider::class,
            openapi: new Model\Operation(
                summary: 'List loot sales',
                tags: ['Revenue - PVE'],
                parameters: [
                    new Model\Parameter(name: 'days', in: 'query', schema: ['type' => 'integer']),
                ],
            ),
        ),
        new Post(
            uriTemplate: '/pve/loot-sales',
            processor: CreateLootSaleProcessor::class,
            input: CreateLootSaleInput::class,
            openapi: new Model\Operation(summary: 'Create a loot sale', tags: ['Revenue - PVE']),
        ),
        new Delete(
            uriTemplate: '/pve/loot-sales/{id}',
            provider: LootSaleProvider::class,
            processor: DeleteLootSaleProcessor::class,
            openapi: new Model\Operation(summary: 'Delete a loot sale', tags: ['Revenue - PVE']),
        ),
        new Post(
            uriTemplate: '/pve/import-loot-sales',
            processor: ImportLootSalesProcessor::class,
            input: ImportLootSalesInput::class,
            output: ImportResultResource::class,
            openapi: new Model\Operation(summary: 'Import scanned loot sales', tags: ['Revenue - PVE']),
        ),
        new Post(
            uriTemplate: '/pve/import-loot-contracts',
            processor: ImportLootContractsProcessor::class,
            input: ImportLootContractsInput::class,
            output: ImportResultResource::class,
            openapi: new Model\Operation(summary: 'Import scanned loot contracts', tags: ['Revenue - PVE']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class LootSaleResource
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    public string $type = '';

    public string $description = '';

    public float $amount = 0.0;

    public string $date = '';
}
