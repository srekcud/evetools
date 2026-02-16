<?php

declare(strict_types=1);

namespace App\ApiResource\Market;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Market\MarketSearchProvider;

#[ApiResource(
    shortName: 'MarketSearch',
    description: 'Search marketable items with Jita prices',
    operations: [
        new Get(
            uriTemplate: '/market/search',
            provider: MarketSearchProvider::class,
            openapi: new Model\Operation(
                summary: 'Search marketable items',
                description: 'Search for published items with market group, enriched with Jita prices',
                parameters: [
                    new Model\Parameter(name: 'q', in: 'query', required: true, schema: ['type' => 'string']),
                ],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class MarketSearchResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'search';

    /** @var MarketSearchItemResource[] */
    public array $results = [];
}
