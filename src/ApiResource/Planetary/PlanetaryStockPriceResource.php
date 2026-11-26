<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Planetary\PlanetaryStockPriceProvider;

#[ApiResource(
    shortName: 'PlanetaryStockPrice',
    description: 'Paid price, market prices, buy orders and signals of the PI inputs of the connected user',
    operations: [
        new Get(
            uriTemplate: '/planetary/stock-prices',
            provider: PlanetaryStockPriceProvider::class,
            openapi: new Model\Operation(summary: 'Get the price grid and the signals of the PI inputs', tags: ['Planetary']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
)]
final class PlanetaryStockPriceResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'stock-prices';

    public int $hubStructureId = 0;

    public string $hubStructureName = '';

    public int $paidPriceDays = 0;

    public bool $complete = false;

    /** @var list<array{characterId: int, characterName: string, status: string}> */
    public array $unsyncedWallets = [];

    /** @var list<array<string, mixed>> */
    public array $rows = [];
}
