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

    /** @var list<array{typeId: int, sell: float|null, buy: float|null, hub: float|null, paid: float|null}> */
    public array $prices = [];

    /** @var array{structureId: int, structureName: string, hasData: bool} */
    public array $hub = ['structureId' => 0, 'structureName' => '', 'hasData' => false];

    /** @var list<array{name: string, commandCenter: array{cpu: int, pg: int}, launchpad: array{cpu: int, pg: int, capacity: float}, facilities: array{basic: array{cpu: int, pg: int}|null, advanced: array{cpu: int, pg: int}|null, highTech: array{cpu: int, pg: int}|null}}> */
    public array $planetTypes = [];

    /** @var list<array{typeId: int, planetTypes: list<string>}> */
    public array $extractionPlanetTypes = [];
}
