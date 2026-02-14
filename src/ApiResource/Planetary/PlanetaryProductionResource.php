<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Planetary\PlanetaryProductionProvider;

#[ApiResource(
    shortName: 'PlanetaryProduction',
    description: 'Planetary Interaction production breakdown by tier',
    operations: [
        new Get(
            uriTemplate: '/planetary/production',
            provider: PlanetaryProductionProvider::class,
            openapi: new Model\Operation(summary: 'Get PI production breakdown by tier'),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class PlanetaryProductionResource
{
    #[\ApiPlatform\Metadata\ApiProperty(identifier: true)]
    public string $id = 'production';

    public array $tiers = [];

    public float $totalDailyIsk = 0.0;

    public float $totalMonthlyIsk = 0.0;
}
