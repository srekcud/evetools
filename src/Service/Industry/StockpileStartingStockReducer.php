<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryStockpileImport;
use App\Entity\User;
use App\Industry\Application\ProductionPlanning;
use App\Industry\Application\UserProductionStructures;
use App\Industry\Domain\Quantity;
use App\Repository\Sde\IndustryActivityProductRepository;

class StockpileStartingStockReducer
{
    use PlansStockpileImports;

    public function __construct(
        private readonly ProductionPlanning $productionPlanning,
        private readonly UserProductionStructures $userStructures,
        private readonly IndustryActivityProductRepository $activityProductRepository,
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
                $reductionOfImport = $this->reductionOf($stockpileImport, $stockLeftByImport, $user);
            } catch (\RuntimeException $exception) {
                $failedProducts[$productTypeId] = ['typeId' => $productTypeId, 'reason' => $exception->getMessage()];
                continue;
            }

            $stockByTypeId = $stockLeftByImport;
            foreach ($reductionOfImport as $typeId => $reduction) {
                $reductionByTypeId[$typeId] = isset($reductionByTypeId[$typeId]) ? $reductionByTypeId[$typeId] + $reduction : $reduction;
            }
        }

        ksort($failedProducts);

        return ['reductionByTypeId' => $reductionByTypeId, 'failedProducts' => array_values($failedProducts)];
    }

    /**
     * @param array<int, int> $stockByTypeId consumed by the plan of the import
     * @return array<int, int>
     */
    private function reductionOf(IndustryStockpileImport $stockpileImport, array &$stockByTypeId, User $user): array
    {
        $productTypeId = $stockpileImport->getProductTypeId();
        $producer = $this->producerOf($productTypeId);
        $fullPlan = $this->planOfImport($user, $producer, $stockpileImport->getRuns(), $stockpileImport->getMe());

        $demandedQuantity = $stockpileImport->getRuns() * $producer->getQuantity();
        $productStock = $stockByTypeId[$productTypeId] ?? 0;
        $coveredQuantity = min($productStock, $demandedQuantity);
        $uncoveredQuantity = $demandedQuantity - $coveredQuantity;

        $reducedNeeds = [];
        if ($uncoveredQuantity > 0) {
            $intermediateStock = array_map(
                static fn (int $stock): Quantity => new Quantity($stock),
                array_intersect_key($stockByTypeId, $fullPlan->intermediates),
            );
            $reducedPlan = $this->planOfImport($user, $producer, (int) ceil($uncoveredQuantity / $producer->getQuantity()), $stockpileImport->getMe(), $intermediateStock);
            $reducedNeeds = self::needsOf($reducedPlan);
            foreach ($reducedPlan->intermediates as $typeId => $intermediate) {
                if ($intermediate->consumedStock->value > 0) {
                    $stockByTypeId[$typeId] -= $intermediate->consumedStock->value;
                }
            }
        }
        if ($coveredQuantity > 0) {
            $stockByTypeId[$productTypeId] -= $coveredQuantity;
        }

        $reductionByTypeId = [];
        foreach (self::needsOf($fullPlan) as $typeId => $fullNeed) {
            $reductionByTypeId[$typeId] = isset($reducedNeeds[$typeId]) ? $fullNeed - $reducedNeeds[$typeId] : $fullNeed;
        }

        return $reductionByTypeId;
    }
}
