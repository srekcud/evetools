<?php

declare(strict_types=1);

namespace App\ApiResource\Market;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Market\MarketHistoryProvider;

#[ApiResource(
    shortName: 'MarketHistory',
    description: 'Market price history for an item',
    operations: [
        new Get(
            uriTemplate: '/market/types/{typeId}/history',
            provider: MarketHistoryProvider::class,
            openapi: new Model\Operation(
                summary: 'Get price history for an item',
                parameters: [
                    new Model\Parameter(name: 'days', in: 'query', schema: ['type' => 'integer', 'default' => 30]),
                ],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class MarketHistoryResource
{
    #[ApiProperty(identifier: true)]
    public int $typeId;

    /** @var MarketHistoryEntryResource[] */
    public array $entries = [];
}
