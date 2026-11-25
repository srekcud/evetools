<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Exception\PlanetaryTierMissingFromSdeException;
use App\Exception\PlanetSchematicIncompleteException;
use App\Exception\TypeMissingFromSdeException;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\PlanetSchematicRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\JitaMarketService;

final readonly class PlanetaryPlannerCatalog
{
    private const string UNPRODUCED_INPUT_TIER = 'P0';

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
        $recipes = $this->buildRecipes();
        $typeIds = $this->citedTypeIds($recipes);
        $types = $this->buildTypes($recipes, $typeIds);

        return [
            'recipes' => $recipes,
            'types' => $types,
            'prices' => $this->buildPrices(array_column($types, 'typeId')),
        ];
    }

    /**
     * @return list<array{outputTypeId: int, outputQuantity: int, cycleSeconds: int, inputs: list<array{typeId: int, quantity: int}>}>
     */
    private function buildRecipes(): array
    {
        $cycleSecondsBySchematic = [];
        foreach ($this->schematicRepository->findAll() as $schematic) {
            $cycleSecondsBySchematic[$schematic->getSchematicId()] = $schematic->getCycleTime();
        }

        $schematicMap = $this->schematicTypeRepository->getSchematicMap();
        foreach (array_keys($cycleSecondsBySchematic) as $schematicId) {
            if (!isset($schematicMap[$schematicId])) {
                throw new PlanetSchematicIncompleteException($schematicId);
            }
        }

        $recipes = [];
        foreach ($schematicMap as $schematicId => $schematic) {
            if (!isset($cycleSecondsBySchematic[$schematicId]) || $cycleSecondsBySchematic[$schematicId] <= 0) {
                throw new PlanetSchematicIncompleteException($schematicId);
            }
            if ($schematic['output'] === null || $schematic['inputs'] === []) {
                throw new PlanetSchematicIncompleteException($schematicId);
            }

            $inputs = $schematic['inputs'];
            ksort($inputs);
            $recipes[] = [
                'outputTypeId' => $schematic['output']['typeId'],
                'outputQuantity' => $schematic['output']['quantity'],
                'cycleSeconds' => $cycleSecondsBySchematic[$schematicId],
                'inputs' => array_map(
                    static fn (int $typeId, int $quantity): array => ['typeId' => $typeId, 'quantity' => $quantity],
                    array_keys($inputs),
                    array_values($inputs),
                ),
            ];
        }

        usort($recipes, static fn (array $left, array $right): int => $left['outputTypeId'] <=> $right['outputTypeId']);

        return $recipes;
    }

    /**
     * @param list<array{outputTypeId: int, outputQuantity: int, cycleSeconds: int, inputs: list<array{typeId: int, quantity: int}>}> $recipes
     * @return list<int>
     */
    private function citedTypeIds(array $recipes): array
    {
        $typeIds = [];
        foreach ($recipes as $recipe) {
            $typeIds[$recipe['outputTypeId']] = $recipe['outputTypeId'];
            foreach ($recipe['inputs'] as $input) {
                $typeIds[$input['typeId']] = $input['typeId'];
            }
        }
        ksort($typeIds);

        return array_values($typeIds);
    }

    /**
     * @param list<array{outputTypeId: int, outputQuantity: int, cycleSeconds: int, inputs: list<array{typeId: int, quantity: int}>}> $recipes
     * @param list<int> $typeIds
     * @return list<array{typeId: int, name: string, tier: string, volume: float|null}>
     */
    private function buildTypes(array $recipes, array $typeIds): array
    {
        if ($typeIds === []) {
            return [];
        }

        $invTypes = $this->invTypeRepository->findByTypeIds($typeIds);
        $missingTypeIds = array_values(array_diff($typeIds, array_keys($invTypes)));
        if ($missingTypeIds !== []) {
            throw new TypeMissingFromSdeException($missingTypeIds);
        }

        $producedTypeIds = array_flip(array_column($recipes, 'outputTypeId'));

        return array_map(
            fn (int $typeId): array => [
                'typeId' => $typeId,
                'name' => $invTypes[$typeId]->getTypeName(),
                'tier' => isset($producedTypeIds[$typeId]) ? $this->producedTier($typeId) : self::UNPRODUCED_INPUT_TIER,
                'volume' => $invTypes[$typeId]->getVolume(),
            ],
            $typeIds,
        );
    }

    private function producedTier(int $typeId): string
    {
        return $this->productionCalculator->findProductTier($typeId) ?? throw new PlanetaryTierMissingFromSdeException($typeId);
    }

    /**
     * @param list<int> $typeIds
     * @return list<array{typeId: int, sell: float|null, buy: float|null}>
     */
    private function buildPrices(array $typeIds): array
    {
        if ($typeIds === []) {
            return [];
        }

        $sellPrices = $this->jitaMarketService->getPrices($typeIds);
        $buyPrices = $this->jitaMarketService->getBuyPrices($typeIds);

        return array_map(
            static fn (int $typeId): array => ['typeId' => $typeId, 'sell' => $sellPrices[$typeId], 'buy' => $buyPrices[$typeId]],
            $typeIds,
        );
    }
}
