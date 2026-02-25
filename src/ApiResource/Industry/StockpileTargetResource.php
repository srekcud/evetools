<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Industry\CreateStockpileTargetInput;
use App\ApiResource\Input\Industry\UpdateStockpileTargetInput;
use App\State\Processor\Industry\CreateStockpileTargetProcessor;
use App\State\Processor\Industry\DeleteStockpileTargetProcessor;
use App\State\Processor\Industry\UpdateStockpileTargetProcessor;
use App\State\Provider\Industry\StockpileTargetCollectionProvider;
use App\State\Provider\Industry\StockpileTargetDeleteProvider;
use App\State\Provider\Industry\StockpileTargetProvider;

#[ApiResource(
    shortName: 'StockpileTarget',
    description: 'Industry stockpile targets for tracking material inventory',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/stockpile-targets',
            provider: StockpileTargetCollectionProvider::class,
            openapi: new Model\Operation(summary: 'List stockpile targets', description: 'Returns all stockpile targets for the user', tags: ['Industry - Stockpile']),
        ),
        new Get(
            uriTemplate: '/industry/stockpile-targets/{id<[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}>}',
            provider: StockpileTargetProvider::class,
            openapi: new Model\Operation(summary: 'Get stockpile target', description: 'Returns a single stockpile target', tags: ['Industry - Stockpile']),
        ),
        new Post(
            uriTemplate: '/industry/stockpile-targets',
            processor: CreateStockpileTargetProcessor::class,
            input: CreateStockpileTargetInput::class,
            openapi: new Model\Operation(summary: 'Create stockpile target', description: 'Creates a new stockpile target', tags: ['Industry - Stockpile']),
        ),
        new Patch(
            uriTemplate: '/industry/stockpile-targets/{id<[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}>}',
            provider: StockpileTargetProvider::class,
            processor: UpdateStockpileTargetProcessor::class,
            input: UpdateStockpileTargetInput::class,
            openapi: new Model\Operation(summary: 'Update stockpile target', description: 'Updates a stockpile target quantity', tags: ['Industry - Stockpile']),
        ),
        new Delete(
            uriTemplate: '/industry/stockpile-targets/{id<[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}>}',
            provider: StockpileTargetDeleteProvider::class,
            processor: DeleteStockpileTargetProcessor::class,
            openapi: new Model\Operation(summary: 'Delete stockpile target', description: 'Deletes a stockpile target', tags: ['Industry - Stockpile']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class StockpileTargetResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public int $typeId;

    public string $typeName;

    public int $targetQuantity;

    public string $stage;

    public ?int $sourceProductTypeId = null;

    public string $createdAt;

    public ?string $updatedAt = null;
}
