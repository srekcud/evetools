<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryStockpileImport;
use App\Entity\User;

class StockpileStartingStockReducer
{
    public function __construct(
        private readonly IndustryTreeService $treeService,
    ) {
    }

    /**
     * @param list<IndustryStockpileImport> $imports
     * @param array<int, int> $stockByTypeId
     * @return array{reductionByTypeId: array<int, int>, failedProducts: list<array{typeId: int, reason: string}>}
     */
    public function reduce(array $imports, array $stockByTypeId, User $user): array
    {
        $reductionByTypeId = [];
        $failedProducts = [];

        foreach ($imports as $stockpileImport) {
            $productTypeId = $stockpileImport->getProductTypeId();
            $stockLeftByImport = $stockByTypeId;
            try {
                $fullTree = $this->treeService->buildProductionTree($productTypeId, $stockpileImport->getRuns(), $stockpileImport->getMe(), [], $user);
                $fullNeeds = [];
                $this->collectNeeds($fullTree['materials'], $fullNeeds);
                $reducedNeeds = $this->reducedNeedsOf($fullTree, $stockpileImport, $stockLeftByImport, $user);
            } catch (\RuntimeException $exception) {
                $failedProducts[$productTypeId] = ['typeId' => $productTypeId, 'reason' => $exception->getMessage()];
                continue;
            }

            $stockByTypeId = $stockLeftByImport;
            foreach ($fullNeeds as $typeId => $fullNeed) {
                $reductionByTypeId[$typeId] = ($reductionByTypeId[$typeId] ?? 0) + $fullNeed - ($reducedNeeds[$typeId] ?? 0);
            }
        }

        ksort($failedProducts);

        return ['reductionByTypeId' => $reductionByTypeId, 'failedProducts' => array_values($failedProducts)];
    }

    /**
     * @param array<string, mixed> $fullTree
     * @param array<int, int> $stockByTypeId
     * @return array<int, int>
     */
    private function reducedNeedsOf(array $fullTree, IndustryStockpileImport $stockpileImport, array &$stockByTypeId, User $user): array
    {
        $productTypeId = $stockpileImport->getProductTypeId();
        $demandedQuantity = (int) $fullTree['quantity'];
        $uncoveredQuantity = $this->takeFromStock($stockByTypeId, $productTypeId, $demandedQuantity);

        $reducedNeeds = [];
        if ($uncoveredQuantity === 0) {
            return $reducedNeeds;
        }

        $tree = $uncoveredQuantity === $demandedQuantity
            ? $fullTree
            : $this->treeService->buildProductionTree($productTypeId, $uncoveredQuantity, $stockpileImport->getMe(), [], $user);
        $this->collectReducedNeeds($tree['materials'], $stockByTypeId, $reducedNeeds, $user);

        return $reducedNeeds;
    }

    /**
     * @param list<array<string, mixed>> $materials
     * @param array<int, int> $stockByTypeId
     * @param array<int, int> $needs
     */
    private function collectReducedNeeds(array $materials, array &$stockByTypeId, array &$needs, User $user): void
    {
        foreach ($materials as $material) {
            $typeId = (int) $material['typeId'];
            $demandedQuantity = (int) $material['quantity'];
            $needs[$typeId] = ($needs[$typeId] ?? 0) + $demandedQuantity;

            $uncoveredQuantity = $this->takeFromStock($stockByTypeId, $typeId, $demandedQuantity);
            if ($uncoveredQuantity === 0 || !isset($material['blueprint'])) {
                continue;
            }

            $subtree = $uncoveredQuantity === $demandedQuantity
                ? $material['blueprint']
                : $this->treeService->buildIntermediateTree($typeId, $uncoveredQuantity, [], $user);
            $this->collectReducedNeeds($subtree['materials'], $stockByTypeId, $needs, $user);
        }
    }

    /**
     * @param list<array<string, mixed>> $materials
     * @param array<int, int> $needs
     */
    private function collectNeeds(array $materials, array &$needs): void
    {
        foreach ($materials as $material) {
            $typeId = (int) $material['typeId'];
            $needs[$typeId] = ($needs[$typeId] ?? 0) + (int) $material['quantity'];
            if (isset($material['blueprint'])) {
                $this->collectNeeds($material['blueprint']['materials'], $needs);
            }
        }
    }

    /**
     * @param array<int, int> $stockByTypeId
     */
    private function takeFromStock(array &$stockByTypeId, int $typeId, int $demandedQuantity): int
    {
        $availableStock = $stockByTypeId[$typeId] ?? 0;
        $takenQuantity = min($availableStock, $demandedQuantity);
        $stockByTypeId[$typeId] = $availableStock - $takenQuantity;

        return $demandedQuantity - $takenQuantity;
    }
}
