<?php

declare(strict_types=1);

namespace App\ApiResource\Ledger;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\Ledger\MiningStatsByTypeProvider;
use App\State\Provider\Ledger\MiningStatsDailyProvider;
use App\State\Provider\Ledger\MiningStatsProvider;

#[ApiResource(
    shortName: 'MiningStats',
    description: 'Mining statistics',
    operations: [
        new Get(
            uriTemplate: '/ledger/mining/stats',
            provider: MiningStatsProvider::class,
            openapiContext: [
                'summary' => 'Get mining statistics',
                'parameters' => [
                    ['name' => 'days', 'in' => 'query', 'type' => 'integer', 'description' => 'Number of days (default: 30)'],
                ],
            ],
        ),
        new Get(
            uriTemplate: '/ledger/mining/stats/daily',
            provider: MiningStatsDailyProvider::class,
            output: MiningStatsDailyResource::class,
            openapiContext: [
                'summary' => 'Get daily mining statistics',
                'parameters' => [
                    ['name' => 'days', 'in' => 'query', 'type' => 'integer', 'description' => 'Number of days (default: 30)'],
                ],
            ],
        ),
        new Get(
            uriTemplate: '/ledger/mining/stats/by-type',
            provider: MiningStatsByTypeProvider::class,
            output: MiningStatsByTypeResource::class,
            openapiContext: [
                'summary' => 'Get mining statistics by ore type',
                'parameters' => [
                    ['name' => 'days', 'in' => 'query', 'type' => 'integer', 'description' => 'Number of days (default: 30)'],
                ],
            ],
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class MiningStatsResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'mining-stats';

    public array $period = [];

    public array $totals = [];

    public array $byUsage = [];

    public float $iskPerDay = 0.0;
}
