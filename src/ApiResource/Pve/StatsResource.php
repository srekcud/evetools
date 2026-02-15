<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
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
            openapi: new Model\Operation(
                summary: 'Get PVE statistics',
                parameters: [
                    new Model\Parameter(name: 'days', in: 'query', schema: ['type' => 'integer']),
                ],
            ),
        ),
        new Get(
            uriTemplate: '/pve/stats/daily',
            provider: StatsDailyProvider::class,
            output: StatsDailyResource::class,
            openapi: new Model\Operation(
                summary: 'Get daily PVE statistics',
                parameters: [
                    new Model\Parameter(name: 'days', in: 'query', schema: ['type' => 'integer']),
                ],
            ),
        ),
        new Get(
            uriTemplate: '/pve/stats/by-type',
            provider: StatsByTypeProvider::class,
            output: StatsByTypeResource::class,
            openapi: new Model\Operation(
                summary: 'Get PVE statistics by type',
                parameters: [
                    new Model\Parameter(name: 'days', in: 'query', schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class StatsResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'stats';

    /** @var array<string, mixed> */
    public array $period = [];

    /** @var array<string, float> */
    public array $totals = [];

    /** @var array<string, float> */
    public array $expensesByType = [];

    public float $iskPerDay = 0.0;
}
