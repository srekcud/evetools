<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\PlanetaryColony;
use App\Repository\Sde\PlanetSchematicRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\TypeNameResolver;

class PlanetaryFactoryColonyTracker
{
    private const MAX_SEARCHED_CYCLES = 10_000_000;

    public function __construct(
        private readonly PlanetSchematicTypeRepository $schematicTypeRepository,
        private readonly PlanetSchematicRepository $schematicRepository,
        private readonly TypeNameResolver $typeNameResolver,
    ) {
    }

    /**
     * @return array{finalProductTypeId: int, finalProductName: ?string, factoryCount: int, stockedFinalProduct: int, maxProducts: int, remainingSeconds: int}|null
     */
    public function track(PlanetaryColony $colony): ?array
    {
        $recipes = $this->recipesOfFactoryColony($colony);
        if ($recipes === []) {
            return null;
        }

        $final = $this->finalRecipe($recipes);
        $stock = $colony->getStockByType();
        $maxCycles = $this->maxCycles($final, $recipes, $stock);
        $remainingCycles = (int) ceil($maxCycles / $final['factoryCount']);

        return [
            'finalProductTypeId' => $final['outputTypeId'],
            'finalProductName' => $this->typeNameResolver->findName($final['outputTypeId']),
            'factoryCount' => $final['factoryCount'],
            'stockedFinalProduct' => $stock[$final['outputTypeId']] ?? 0,
            'maxProducts' => $maxCycles * $final['outputQuantity'],
            'remainingSeconds' => $remainingCycles * $final['cycleTime'],
        ];
    }

    /**
     * @return array<int, array{outputTypeId: int, outputQuantity: int, inputs: array<int, int>, cycleTime: int, factoryCount: int}> indexed by output typeId, empty unless the colony has no extractor
     */
    private function recipesOfFactoryColony(PlanetaryColony $colony): array
    {
        $factories = [];
        foreach ($colony->getPins() as $pin) {
            if ($pin->isExtractor()) {
                return [];
            }
            if ($pin->isFactory()) {
                $factories[] = $pin;
            }
        }
        if ($factories === []) {
            return [];
        }

        $schematicMap = $this->schematicTypeRepository->getSchematicMap();
        $recipes = [];
        foreach ($factories as $factory) {
            $schematicId = $factory->getSchematicId();
            if ($schematicId === null || !isset($schematicMap[$schematicId])) {
                continue;
            }

            $output = $schematicMap[$schematicId]['output'];
            $schematic = $this->schematicRepository->findBySchematicId($schematicId);
            if ($output === null || $schematic === null || $schematic->getCycleTime() <= 0) {
                continue;
            }

            $recipes[$output['typeId']] ??= [
                'outputTypeId' => $output['typeId'],
                'outputQuantity' => $output['quantity'],
                'inputs' => $schematicMap[$schematicId]['inputs'],
                'cycleTime' => $schematic->getCycleTime(),
                'factoryCount' => 0,
            ];
            ++$recipes[$output['typeId']]['factoryCount'];
        }

        return $recipes;
    }

    /**
     * @param non-empty-array<int, array{outputTypeId: int, outputQuantity: int, inputs: array<int, int>, cycleTime: int, factoryCount: int}> $recipes
     * @return array{outputTypeId: int, outputQuantity: int, inputs: array<int, int>, cycleTime: int, factoryCount: int}
     */
    private function finalRecipe(array $recipes): array
    {
        $consumedTypeIds = [];
        foreach ($recipes as $recipe) {
            foreach (array_keys($recipe['inputs']) as $inputTypeId) {
                $consumedTypeIds[$inputTypeId] = true;
            }
        }

        $candidates = array_filter($recipes, static fn (array $recipe): bool => !isset($consumedTypeIds[$recipe['outputTypeId']]));
        $depths = [];
        foreach ($candidates as $typeId => $recipe) {
            $depths[$typeId] = $this->chainDepth($recipe['outputTypeId'], $recipes);
        }

        uasort($candidates, static fn (array $a, array $b): int => [$depths[$b['outputTypeId']], $b['factoryCount'], $a['outputTypeId']]
            <=> [$depths[$a['outputTypeId']], $a['factoryCount'], $b['outputTypeId']]);

        return array_values($candidates)[0];
    }

    /**
     * @param array<int, array{outputTypeId: int, outputQuantity: int, inputs: array<int, int>, cycleTime: int, factoryCount: int}> $recipes
     */
    private function chainDepth(int $typeId, array $recipes): int
    {
        $deepestInput = 0;
        foreach (array_keys($recipes[$typeId]['inputs']) as $inputTypeId) {
            if (isset($recipes[$inputTypeId])) {
                $deepestInput = max($deepestInput, $this->chainDepth($inputTypeId, $recipes));
            }
        }

        return 1 + $deepestInput;
    }

    /**
     * @param array{outputTypeId: int, outputQuantity: int, inputs: array<int, int>, cycleTime: int, factoryCount: int}                        $final
     * @param array<int, array{outputTypeId: int, outputQuantity: int, inputs: array<int, int>, cycleTime: int, factoryCount: int}> $recipes
     * @param array<int, int>                                                                                                                  $stock
     */
    private function maxCycles(array $final, array $recipes, array $stock): int
    {
        $feasible = function (int $cycles) use ($final, $recipes, $stock): bool {
            $workingStock = $stock;

            return $this->canRunCycles($final, $cycles, $recipes, $workingStock);
        };

        $upper = 1;
        while ($upper <= self::MAX_SEARCHED_CYCLES && $feasible($upper)) {
            $upper *= 2;
        }

        $lower = 0;
        while ($upper - $lower > 1) {
            $middle = intdiv($lower + $upper, 2);
            if ($feasible($middle)) {
                $lower = $middle;
            } else {
                $upper = $middle;
            }
        }

        return $lower;
    }

    /**
     * @param array{outputTypeId: int, outputQuantity: int, inputs: array<int, int>, cycleTime: int, factoryCount: int}                        $recipe
     * @param array<int, array{outputTypeId: int, outputQuantity: int, inputs: array<int, int>, cycleTime: int, factoryCount: int}> $recipes
     * @param array<int, int>                                                                                                                  $stock
     */
    private function canRunCycles(array $recipe, int $cycles, array $recipes, array &$stock): bool
    {
        foreach ($recipe['inputs'] as $inputTypeId => $inputQuantity) {
            if (!$this->canSupply($inputTypeId, $inputQuantity * $cycles, $recipes, $stock)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array{outputTypeId: int, outputQuantity: int, inputs: array<int, int>, cycleTime: int, factoryCount: int}> $recipes
     * @param array<int, int>                                                                                                      $stock
     */
    private function canSupply(int $typeId, int $quantity, array $recipes, array &$stock): bool
    {
        $fromStock = min($stock[$typeId] ?? 0, $quantity);
        $stock[$typeId] = ($stock[$typeId] ?? 0) - $fromStock;
        $missing = $quantity - $fromStock;
        if ($missing === 0) {
            return true;
        }
        if (!isset($recipes[$typeId])) {
            return false;
        }

        $cycles = (int) ceil($missing / $recipes[$typeId]['outputQuantity']);

        return $this->canRunCycles($recipes[$typeId], $cycles, $recipes, $stock);
    }
}
