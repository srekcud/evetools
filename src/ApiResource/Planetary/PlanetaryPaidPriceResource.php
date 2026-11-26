<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Planetary\PlanetaryPaidPriceProvider;

#[ApiResource(
    shortName: 'PlanetaryPaidPrice',
    description: 'Paid price of the Planetary Interaction inputs, weighted average of the wallet purchases of the last 90 days',
    operations: [
        new Get(
            uriTemplate: '/planetary/paid-prices',
            provider: PlanetaryPaidPriceProvider::class,
            openapi: new Model\Operation(summary: 'Get the paid price of every PI input type from the wallet purchases', tags: ['Planetary']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
final class PlanetaryPaidPriceResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'paid-prices';

    public int $days = 0;

    public string $since = '';

    /** @var list<array{typeId: int, typeName: string|null, paidPrice: float|null, quantity: int, purchaseCount: int}> */
    public array $prices = [];

    /** @var list<array{characterId: int, characterName: string, status: string}> */
    public array $unsyncedWallets = [];
}
