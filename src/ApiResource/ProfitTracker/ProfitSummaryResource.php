<?php

declare(strict_types=1);

namespace App\ApiResource\ProfitTracker;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\ProfitTracker\ProfitSummaryProvider;

#[ApiResource(
    shortName: 'ProfitSummary',
    description: 'Profit tracker KPI summary',
    operations: [
        new Get(
            uriTemplate: '/profit-tracker/summary',
            provider: ProfitSummaryProvider::class,
            openapi: new Model\Operation(summary: 'Get profit tracker summary'),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ProfitSummaryResource
{
    public float $totalProfit = 0.0;

    public float $totalRevenue = 0.0;

    public float $totalCost = 0.0;

    public float $avgMargin = 0.0;

    public int $itemCount = 0;

    /** @var array{typeName: string, profit: float}|null */
    public ?array $bestItem = null;

    /** @var array{typeName: string, profit: float}|null */
    public ?array $worstItem = null;
}
