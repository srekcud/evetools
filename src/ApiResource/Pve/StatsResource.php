<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\Pve\StatsByTypeProvider;
use App\State\Provider\Pve\StatsDailyProvider;
use App\State\Provider\Pve\StatsProvider;

#[ApiResource(
    shortName: 'PveStats',
    description: 'PVE statistics',
    operations: [
        new Get(
            uriTemplate: '/pve/stats',
            provider: StatsProvider::class,
            openapiContext: [
                'summary' => 'Get PVE statistics',
                'parameters' => [
                    ['name' => 'days', 'in' => 'query', 'type' => 'integer', 'description' => 'Number of days to include (default: 30)'],
                ],
            ],
        ),
        new Get(
            uriTemplate: '/pve/stats/daily',
            provider: StatsDailyProvider::class,
            output: StatsDailyResource::class,
            openapiContext: [
                'summary' => 'Get daily PVE statistics',
                'parameters' => [
                    ['name' => 'days', 'in' => 'query', 'type' => 'integer', 'description' => 'Number of days to include (default: 30)'],
                ],
            ],
        ),
        new Get(
            uriTemplate: '/pve/stats/by-type',
            provider: StatsByTypeProvider::class,
            output: StatsByTypeResource::class,
            openapiContext: [
                'summary' => 'Get PVE statistics by type',
                'parameters' => [
                    ['name' => 'days', 'in' => 'query', 'type' => 'integer', 'description' => 'Number of days to include (default: 30)'],
                ],
            ],
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class StatsResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'stats';

    public array $period = [];

    public array $totals = [];

    public array $expensesByType = [];

    public float $iskPerDay = 0.0;
}
