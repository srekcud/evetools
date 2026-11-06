<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\SellMarketProvider;

#[ApiResource(
    shortName: 'IndustrySellMarket',
    operations: [
        new Get(
            uriTemplate: '/industry/sell-markets/{typeId}',
            provider: SellMarketProvider::class,
            openapi: new Model\Operation(
                summary: 'Compare the sell markets of a type',
                tags: ['Industry - Profit'],
                parameters: [
                    new Model\Parameter(name: 'typeId', in: 'path', required: true, schema: ['type' => 'integer']),
                    new Model\Parameter(name: 'quantity', in: 'query', required: true, schema: ['type' => 'integer', 'minimum' => 1]),
                ],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class SellMarketResource
{
    #[ApiProperty(identifier: true)]
    public int $typeId = 0;

    public ?string $typeName = null;

    public int $quantity = 0;

    public float $sellShareOfDailyVolume = 0.0;

    /** @var list<array{key: string, name: string, unitPrice: ?float, coveragePercent: ?float, dailyVolume: ?float, volumeKind: string, feeRate: ?float, netUnitPrice: ?float, daysToSell: ?float}> */
    public array $markets = [];

    /** @var array{averageUnitPrice: ?float, coveragePercent: ?float, netUnitPrice: ?float}|null */
    public ?array $immediateSale = null;
}
