<?php

declare(strict_types=1);

namespace App\ApiResource\Ledger;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
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
            openapi: new Model\Operation(
                summary: 'Get mining statistics',
                parameters: [
                    new Model\Parameter(name: 'days', in: 'query', schema: ['type' => 'integer']),
                ],
            ),
        ),
        new Get(
            uriTemplate: '/ledger/mining/stats/daily',
            provider: MiningStatsDailyProvider::class,
            output: MiningStatsDailyResource::class,
            openapi: new Model\Operation(
                summary: 'Get daily mining statistics',
                parameters: [
                    new Model\Parameter(name: 'days', in: 'query', schema: ['type' => 'integer']),
                ],
            ),
        ),
        new Get(
            uriTemplate: '/ledger/mining/stats/by-type',
            provider: MiningStatsByTypeProvider::class,
            output: MiningStatsByTypeResource::class,
            openapi: new Model\Operation(
                summary: 'Get mining statistics by ore type',
                parameters: [
                    new Model\Parameter(name: 'days', in: 'query', schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class MiningStatsResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'mining-stats';

    /** @var array<string, mixed> */
    public array $period = [];

    /** @var array<string, float> */
    public array $totals = [];

    /** @var array<string, array<string, mixed>> */
    public array $byUsage = [];

    public float $iskPerDay = 0.0;
}
