<?php

declare(strict_types=1);

namespace App\ApiResource\Market;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Market\MarketTypeDetailProvider;

#[ApiResource(
    shortName: 'MarketTypeDetail',
    description: 'Full market detail for an item',
    operations: [
        new Get(
            uriTemplate: '/market/types/{typeId}',
            provider: MarketTypeDetailProvider::class,
            openapi: new Model\Operation(summary: 'Get full market detail for an item'),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class MarketTypeDetailResource
{
    #[ApiProperty(identifier: true)]
    public int $typeId;

    public string $typeName;

    public ?string $groupName = null;

    public ?string $categoryName = null;

    public ?float $jitaSell = null;

    public ?float $jitaBuy = null;

    public ?float $spread = null;

    /** @var list<array{price: float, volume: int}> */
    public array $sellOrders = [];

    /** @var list<array{price: float, volume: int}> */
    public array $buyOrders = [];

    public ?float $structureSell = null;

    public ?float $structureBuy = null;

    public ?float $avgDailyVolume = null;

    public ?float $change30d = null;

    public bool $isFavorite = false;
}
