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

    /** @var array<string, array{items: list<array{id: string, typeId: int, typeName: string, targetQuantity: int, remainingTargetQuantity: int, stock: int, percent: float, status: string, unitPrice: float|null, stockValue: float|null, deficit: int, deficitCost: float|null, stage: string, sourceProductTypeId: int|null, locations: list<array{locationId: int, locationName: string, systemName: string|null, quantity: int}>, suggestedProjectRuns: int|null, priceSources: array{stock: array{market: string, side: string, method: string, coveragePercent: ?float, percentile?: float}|null, deficit: array{market: string, side: string, method: string, coveragePercent: ?float, percentile?: float}|null}}>, totalValue: float|null, healthPercent: float}> */
    public array $stages = [];

    /** @var array{pipelineHealth: float, totalInvested: float|null, bottleneck: array<string, mixed>|null, estOutput: array{ready: int, total: int, readyNames: list<string>}}|array{} */
    public array $kpis = [];

    /** @var list<array<string, mixed>> */
    public array $shoppingList = [];

    /** @var list<int> */
    public array $importsWithoutPlan = [];

    /** @var list<array{typeId: int, reason: string}> */
    public array $failedProducts = [];

    /** @var list<int> */
    public array $missingPriceTypeIds = [];

    /** @var array{stockValue: array{market: string, side: string, method: string, coveragePercent: ?float, percentile?: float}|null, deficitCost: array{market: string, side: string, method: string, coveragePercent: ?float, percentile?: float}|null}|array{} */
    public array $priceSources = [];
}
