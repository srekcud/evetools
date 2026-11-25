<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\PlanetSchematicRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\JitaMarketService;

final readonly class PlanetaryPlannerCatalog
{
    public function __construct(
        private PlanetSchematicRepository $schematicRepository,
        private PlanetSchematicTypeRepository $schematicTypeRepository,
        private InvTypeRepository $invTypeRepository,
        private PlanetaryProductionCalculator $productionCalculator,
        private JitaMarketService $jitaMarketService,
    ) {
    }

    /**
     * @return array{
     *     recipes: list<array{outputTypeId: int, outputQuantity: int, cycleSeconds: int, inputs: list<array{typeId: int, quantity: int}>}>,
     *     types: list<array{typeId: int, name: string, tier: string, volume: float|null}>,
     *     prices: list<array{typeId: int, sell: float|null, buy: float|null}>
     * }
     */
    public function build(): array
    {
        return ['recipes' => [], 'types' => [], 'prices' => []];
    }
}
