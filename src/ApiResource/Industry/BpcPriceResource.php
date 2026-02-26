<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Industry\UpsertBpcPriceInput;
use App\State\Processor\Industry\DeleteBpcPriceProcessor;
use App\State\Processor\Industry\UpsertBpcPriceProcessor;
use App\State\Provider\Industry\BpcPriceCollectionProvider;
use App\State\Provider\Industry\BpcPriceDeleteProvider;
use App\State\Provider\Industry\BpcPriceItemProvider;

#[ApiResource(
    shortName: 'BpcPrice',
    description: 'Manual BPC prices for faction/non-inventable blueprints',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/bpc-prices',
            provider: BpcPriceCollectionProvider::class,
            openapi: new Model\Operation(summary: 'List BPC prices', description: 'Returns all user-defined BPC prices', tags: ['Industry - Scanner']),
        ),
        new Get(
            uriTemplate: '/industry/bpc-prices/{blueprintTypeId}',
            provider: BpcPriceItemProvider::class,
            openapi: new Model\Operation(summary: 'Get BPC price', description: 'Returns a single BPC price', tags: ['Industry - Scanner']),
        ),
        new Put(
            uriTemplate: '/industry/bpc-prices',
            processor: UpsertBpcPriceProcessor::class,
            input: UpsertBpcPriceInput::class,
            openapi: new Model\Operation(summary: 'Upsert BPC price', description: 'Creates or updates a BPC price for a blueprint', tags: ['Industry - Scanner']),
        ),
        new Delete(
            uriTemplate: '/industry/bpc-prices/{blueprintTypeId}',
            provider: BpcPriceDeleteProvider::class,
            processor: DeleteBpcPriceProcessor::class,
            openapi: new Model\Operation(summary: 'Delete BPC price', description: 'Removes a user-defined BPC price', tags: ['Industry - Scanner']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class BpcPriceResource
{
    #[ApiProperty(identifier: true)]
    public int $blueprintTypeId;

    public float $pricePerRun;

    public string $updatedAt;
}
