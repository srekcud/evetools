<?php

declare(strict_types=1);

namespace App\ApiResource\Planetary;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Planetary\PlanetaryPlannerProvider;

#[ApiResource(
    shortName: 'PlanetaryPlanner',
    description: 'Planetary Interaction schematics, types and Jita prices feeding the production planner',
    operations: [
        new Get(
            uriTemplate: '/planetary/planner',
            provider: PlanetaryPlannerProvider::class,
            openapi: new Model\Operation(summary: 'Get the PI recipes from the SDE with the types and Jita prices they involve', tags: ['Planetary']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
final class PlanetaryPlannerResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'planner';

    /** @var list<array{outputTypeId: int, outputQuantity: int, cycleSeconds: int, inputs: list<array{typeId: int, quantity: int}>}> */
    public array $recipes = [];

    /** @var list<array{typeId: int, name: string, tier: string, volume: float|null}> */
    public array $types = [];

    /** @var list<array{typeId: int, sell: float|null, buy: float|null}> */
    public array $prices = [];
}
