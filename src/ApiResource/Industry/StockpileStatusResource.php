<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\StockpileStatusProvider;

#[ApiResource(
    shortName: 'StockpileStatus',
    description: 'Stockpile dashboard status with KPIs',
    operations: [
        new Get(
            uriTemplate: '/industry/stockpile-status',
            provider: StockpileStatusProvider::class,
            openapi: new Model\Operation(summary: 'Get stockpile status', description: 'Returns stockpile status with inventory comparison and KPIs', tags: ['Industry - Stockpile']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class StockpileStatusResource
{
    public int $targetCount = 0;

    /** @var array<string, array{items: list<array<string, mixed>>, totalValue: float, healthPercent: float}> */
    public array $stages = [];

    /** @var array{pipelineHealth: float, totalInvested: float, bottleneck: array<string, mixed>|null, estOutput: array{ready: int, total: int, readyNames: list<string>}} */
    public array $kpis = [];

    /** @var list<array<string, mixed>> */
    public array $shoppingList = [];
}
