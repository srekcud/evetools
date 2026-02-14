<?php

declare(strict_types=1);

namespace App\ApiResource\Ledger;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Ledger\LedgerDashboardProvider;
use App\State\Provider\Ledger\LedgerDailyStatsProvider;

#[ApiResource(
    shortName: 'LedgerDashboard',
    description: 'Combined Ledger dashboard (PVE + Mining)',
    operations: [
        new Get(
            uriTemplate: '/ledger/dashboard',
            provider: LedgerDashboardProvider::class,
            openapi: new Model\Operation(
                summary: 'Get combined ledger dashboard',
                parameters: [
                    new Model\Parameter(name: 'days', in: 'query', schema: ['type' => 'integer']),
                ],
            ),
        ),
        new Get(
            uriTemplate: '/ledger/stats/daily',
            provider: LedgerDailyStatsProvider::class,
            output: LedgerDailyStatsResource::class,
            openapi: new Model\Operation(
                summary: 'Get combined daily ledger statistics (PVE + Mining)',
                parameters: [
                    new Model\Parameter(name: 'days', in: 'query', schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class LedgerDashboardResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'dashboard';

    public array $period = [];

    /**
     * Combined totals.
     * @var array{total: float, pve: float, mining: float, expenses: float, profit: float}
     */
    public array $totals = ['total' => 0.0, 'pve' => 0.0, 'mining' => 0.0, 'expenses' => 0.0, 'profit' => 0.0];

    /**
     * PVE breakdown.
     * @var array{bounties: float, ess: float, missions: float, lootSales: float, corpProjects: float}
     */
    public array $pveBreakdown = ['bounties' => 0.0, 'ess' => 0.0, 'missions' => 0.0, 'lootSales' => 0.0, 'corpProjects' => 0.0];

    /**
     * Mining breakdown by usage.
     * @var array{sold: float, corpProject: float, industry: float, unknown: float}
     */
    public array $miningBreakdown = ['sold' => 0.0, 'corpProject' => 0.0, 'industry' => 0.0, 'unknown' => 0.0];

    /**
     * Expenses breakdown by type.
     */
    public array $expensesByType = [];

    /**
     * ISK per day (profit / days).
     */
    public float $iskPerDay = 0.0;

    /**
     * Percentage of total from PVE.
     */
    public float $pvePercent = 0.0;

    /**
     * Percentage of total from Mining.
     */
    public float $miningPercent = 0.0;

    /**
     * Last sync timestamps.
     */
    public array $lastSync = [];

    /**
     * Current user settings.
     */
    public array $settings = [];
}
