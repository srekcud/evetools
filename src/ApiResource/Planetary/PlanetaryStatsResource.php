<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\Planetary\PlanetaryStatsProvider;

#[ApiResource(
    shortName: 'PlanetaryStats',
    description: 'Planetary Interaction statistics',
    operations: [
        new Get(
            uriTemplate: '/planetary/stats',
            provider: PlanetaryStatsProvider::class,
            openapiContext: ['summary' => 'Get PI statistics and KPIs'],
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class PlanetaryStatsResource
{
    #[\ApiPlatform\Metadata\ApiProperty(identifier: true)]
    public string $id = 'stats';

    public int $totalColonies = 0;

    public int $totalExtractors = 0;

    public int $activeExtractors = 0;

    public int $expiringExtractors = 0;

    public int $expiredExtractors = 0;

    public int $totalFactories = 0;

    public float $estimatedDailyIsk = 0.0;

    public ?string $nearestExpiry = null;
}
