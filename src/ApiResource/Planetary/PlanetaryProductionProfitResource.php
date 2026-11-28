<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Planetary\PlanetaryProductionProfitProvider;

#[ApiResource(
    shortName: 'PlanetaryProductionProfit',
    description: 'Planetary Interaction production profit per day: outputs at the hub, inputs at the paid price, POCO taxes',
    operations: [
        new Get(
            uriTemplate: '/planetary/production-profit',
            provider: PlanetaryProductionProfitProvider::class,
            openapi: new Model\Operation(summary: 'Get the PI production profit per day by product', tags: ['Planetary']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
final class PlanetaryProductionProfitResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'production-profit';

    /** @var array{structureId: int, structureName: string, hasData: bool} */
    public array $hub = ['structureId' => 0, 'structureName' => '', 'hasData' => false];

    /** @var list<array<string, mixed>> */
    public array $lines = [];

    public float $salesIsk = 0.0;

    public float $inputsIsk = 0.0;

    /** @var array{purchase: string, sale: string, followedTemplateName: string|null} */
    public array $sources = ['purchase' => '', 'sale' => '', 'followedTemplateName' => null];

    public float $profitPerDay = 0.0;

    public int $unknownLineCount = 0;

    /** @var array{days: int, perDay: float|null} */
    public array $taxes = ['days' => 0, 'perDay' => null];

    public float $netProfitPerDay = 0.0;

    /** @var list<array{characterId: int, characterName: string, status: string}> */
    public array $unsyncedWallets = [];
}
