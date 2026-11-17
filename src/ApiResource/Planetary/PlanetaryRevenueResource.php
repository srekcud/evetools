<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Planetary\PlanetaryRevenueProvider;

#[ApiResource(
    shortName: 'PlanetaryRevenue',
    description: 'Realised Planetary Interaction revenue from wallet sales, next to the theoretical production',
    operations: [
        new Get(
            uriTemplate: '/planetary/revenue',
            provider: PlanetaryRevenueProvider::class,
            openapi: new Model\Operation(summary: 'Get realised PI revenue by tier and the production estimate', tags: ['Planetary']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
final class PlanetaryRevenueResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'revenue';

    public string $periodEnd = '';

    public int $days = 0;

    /** @var list<array{tier: string, realisedIsk: float, change: float|null, daily: list<array{date: string, amount: float}>, contributors: list<array{characterId: int, characterName: string, value: float}>}> */
    public array $tiers = [];

    public float $realisedIsk = 0.0;

    public ?float $realisedChange = null;

    /** @var list<array{date: string, amount: float}> */
    public array $realisedDaily = [];

    /** @var list<array{characterId: int, characterName: string, value: float}> */
    public array $realisedContributors = [];

    /** @var array{dailyIsk: float, periodIsk: float, contributors: list<array{characterId: int, characterName: string, value: float}>} */
    public array $estimate = ['dailyIsk' => 0.0, 'periodIsk' => 0.0, 'contributors' => []];

    /** @var list<array{characterId: int, characterName: string, planetId: int, planetName: string|null, solarSystemName: string|null}> */
    public array $toFix = [];
}
