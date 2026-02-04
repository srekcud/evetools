<?php

declare(strict_types=1);

namespace App\ApiResource\Ledger;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\Ledger\LedgerDashboardProvider;
use App\State\Provider\Ledger\LedgerDailyStatsProvider;

#[ApiResource(
    shortName: 'LedgerDashboard',
    description: 'Combined Ledger dashboard (PVE + Mining)',
    operations: [
        new Get(
            uriTemplate: '/ledger/dashboard',
            provider: LedgerDashboardProvider::class,
            openapiContext: [
                'summary' => 'Get combined ledger dashboard',
                'parameters' => [
                    ['name' => 'days', 'in' => 'query', 'type' => 'integer', 'description' => 'Number of days (default: 30)'],
                ],
            ],
        ),
        new Get(
            uriTemplate: '/ledger/stats/daily',
            provider: LedgerDailyStatsProvider::class,
            output: LedgerDailyStatsResource::class,
            openapiContext: [
                'summary' => 'Get combined daily ledger statistics (PVE + Mining)',
                'parameters' => [
                    ['name' => 'days', 'in' => 'query', 'type' => 'integer', 'description' => 'Number of days (default: 30)'],
                ],
            ],
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
    public array $totals = [];

    /**
     * PVE breakdown.
     * @var array{bounties: float, ess: float, missions: float, lootSales: float, corpProjects: float}
     */
    public array $pveBreakdown = [];

    /**
     * Mining breakdown by usage.
     * @var array{sold: float, corpProject: float, industry: float, unknown: float}
     */
    public array $miningBreakdown = [];

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
