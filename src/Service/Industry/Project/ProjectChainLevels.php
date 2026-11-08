<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Industry\Application\PlannedProduction;

final readonly class ProjectChainLevels
{
    private const int LOWEST_LEVEL = 1;

    /**
     * @return array<int, int> manufacturing level by product typeId
     */
    public function of(PlannedProduction $planned): array
    {
        $producedMaterialTypeIdsByProduct = [];
        foreach ($planned->plan->jobs as $job) {
            $producedMaterialTypeIdsByProduct[$job->productTypeId] = [
                ...$producedMaterialTypeIdsByProduct[$job->productTypeId] ?? [],
                ...array_keys($job->materials),
            ];
        }

        $levels = [];
        foreach (array_keys($producedMaterialTypeIdsByProduct) as $productTypeId) {
            $this->levelOf($productTypeId, $producedMaterialTypeIdsByProduct, $levels, []);
        }

        return $levels;
    }

    /**
     * @param array<int, list<int>> $materialTypeIdsByProduct
     * @param array<int, int>       $levels
     * @param array<int, true>      $visiting
     */
    private function levelOf(int $productTypeId, array $materialTypeIdsByProduct, array &$levels, array $visiting): int
    {
        if (isset($levels[$productTypeId])) {
            return $levels[$productTypeId];
        }
        if (isset($visiting[$productTypeId])) {
            throw new \LogicException(\sprintf('Product %d is among its own materials in the plan.', $productTypeId));
        }
        $visiting[$productTypeId] = true;

        $highestMaterialLevel = 0;
        foreach (array_unique($materialTypeIdsByProduct[$productTypeId]) as $materialTypeId) {
            if (isset($materialTypeIdsByProduct[$materialTypeId])) {
                $highestMaterialLevel = max($highestMaterialLevel, $this->levelOf($materialTypeId, $materialTypeIdsByProduct, $levels, $visiting));
            }
        }

        return $levels[$productTypeId] = 0 === $highestMaterialLevel ? self::LOWEST_LEVEL : $highestMaterialLevel + 1;
    }
}
