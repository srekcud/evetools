<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Planetary\PlanetaryPlannerMarketProvider;

#[ApiResource(
    shortName: 'PlanetaryPlannerMarket',
    description: 'The Forge volume and price trend of the P2 to P4 products, for the production planner ranking',
    operations: [
        new Get(
            uriTemplate: '/planetary/planner/market',
            provider: PlanetaryPlannerMarketProvider::class,
            openapi: new Model\Operation(summary: 'Get the 30-day volume, average price and price trend of the P2 to P4 products', tags: ['Planetary']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
final class PlanetaryPlannerMarketResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'market';

    public int $regionId = 0;

    /** @var list<array{typeId: int, averageDailyVolume: float, averagePrice: float, priceChange: float|null, lastDate: string}> */
    public array $products = [];
}
