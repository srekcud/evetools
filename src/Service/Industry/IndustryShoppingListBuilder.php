<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\User;

/**
 * Builds the raw-material shopping list for an industry project.
 *
 * Traverses the production tree, skips buildable intermediates (unless
 * purchased/in-stock), and computes extra quantities when steps use
 * suboptimal structures.
 */
class IndustryShoppingListBuilder
{
    public function __construct(
        private readonly IndustryTreeService $treeService,
        private readonly IndustryBlacklistService $blacklistService,
        private readonly IndustryBonusService $bonusService,
        private readonly IndustryCalculationService $calculationService,
    ) {
    }

    /**
     * Get a shopping list of raw materials from the production tree.
     * Includes extraQuantity per material when steps use suboptimal structures.
     *
     * @return array{materials: list<array<string, mixed>>, failedProducts: list<array{typeId: int, reason: string}>}
     */
    public function getShoppingList(IndustryProject $project): array
    {
        $user = $project->getUser();
        $excludedTypeIds = $this->blacklistService->resolveBlacklistedTypeIds($user);

        $purchasedTypeIds = [];
        $inStockQuantities = [];
        foreach ($project->getSteps() as $step) {
            if ($step->getActivityType() === 'copy') {
                continue;
            }
            $typeId = $step->getProductTypeId();
            $inStockQuantities[$typeId] = ($inStockQuantities[$typeId] ?? 0) + $step->getInStockQuantity();
            if ($step->isPurchased()) {
                $purchasedTypeIds[] = $typeId;
            }
        }

        $rootProducts = [];
        foreach ($project->getSteps() as $step) {
            if ($step->getDepth() === 0 && $step->getActivityType() !== 'copy') {
                $key = $step->getProductTypeId();
                if (!isset($rootProducts[$key])) {
                    $rootProducts[$key] = [
                        'typeId' => $step->getProductTypeId(),
                        'runs' => $step->getRuns(),
                        'meLevel' => $step->getMeLevel(),
                    ];
                } else {
                    // Accumulate runs across multiple steps of the same product
                    $rootProducts[$key]['runs'] += $step->getRuns();
                }
            }
        }

        if (empty($rootProducts)) {
            $rootProducts[$project->getProductTypeId()] = [
                'typeId' => $project->getProductTypeId(),
                'runs' => $project->getRuns(),
                'meLevel' => $project->getMeLevel(),
            ];
        }

        // Build structure bonus overrides from actual step configs
        $structureBonusOverrides = [];
        $hasSuboptimal = false;
        foreach ($project->getSteps() as $step) {
            if ($step->getActivityType() === 'copy') {
                continue;
            }
            $productTypeId = $step->getProductTypeId();
            if (isset($structureBonusOverrides[$productTypeId])) {
                continue; // Already computed for this product
            }
            $structureData = $this->calculationService->getStructureBonusForStep($step);
            $actualBonus = $structureData['materialBonus'];
            $structureBonusOverrides[$productTypeId] = $actualBonus['total'];

            // Check if this differs from the best available
            $isReaction = $step->getActivityType() === 'reaction';
            $bestData = $this->bonusService->findBestStructureForProduct($user, $productTypeId, $isReaction);
            if (abs($actualBonus['total'] - $bestData['bonus']['total']) > 0.001) {
                $hasSuboptimal = true;
            }
        }

        $rawMaterials = [];
        $failedProducts = [];
        $optimalStockQty = $inStockQuantities;

        foreach ($rootProducts as $typeId => $product) {
            try {
                $tree = $this->treeService->buildProductionTree(
                    $product['typeId'],
                    $product['runs'],
                    $product['meLevel'],
                    $excludedTypeIds,
                    $user,
                );
                $this->collectRawMaterials($rawMaterials, $tree, $purchasedTypeIds, $optimalStockQty, $excludedTypeIds, $user, []);
            } catch (\RuntimeException $exception) {
                $failedProducts[$typeId] = ['typeId' => $product['typeId'], 'reason' => $exception->getMessage()];
            }
        }
        ksort($failedProducts);

        // If any step uses a suboptimal structure, compute actual quantities and delta
        if ($hasSuboptimal) {
            $actualRawMaterials = [];
            $actualStockQty = $inStockQuantities;

            foreach (array_diff_key($rootProducts, $failedProducts) as $product) {
                $actualTree = $this->treeService->buildProductionTree(
                    $product['typeId'],
                    $product['runs'],
                    $product['meLevel'],
                    $excludedTypeIds,
                    $user,
                    $structureBonusOverrides,
                );
                $this->collectRawMaterials($actualRawMaterials, $actualTree, $purchasedTypeIds, $actualStockQty, $excludedTypeIds, $user, $structureBonusOverrides);
            }

            // Build lookup: typeId => actual quantity
            $actualByType = [];
            foreach ($actualRawMaterials as $mat) {
                $actualByType[$mat['typeId']] = ($actualByType[$mat['typeId']] ?? 0) + $mat['quantity'];
            }

            // Compute extraQuantity as delta
            foreach ($rawMaterials as &$mat) {
                $actualQty = $actualByType[$mat['typeId']] ?? $mat['quantity'];
                $mat['extraQuantity'] = max(0, $actualQty - $mat['quantity']);
            }
            unset($mat);
        }

        usort($rawMaterials, fn (array $a, array $b) => strcasecmp($a['typeName'], $b['typeName']));

        return ['materials' => $rawMaterials, 'failedProducts' => array_values($failedProducts)];
    }

    /**
     * @return array<int, int> typeId => quantity
     */
    public function getPurchasedQuantities(IndustryProject $project): array
    {
        $quantities = [];
        foreach ($project->getSteps() as $step) {
            foreach ($step->getPurchases() as $purchase) {
                $typeId = $purchase->getTypeId();
                $quantities[$typeId] = ($quantities[$typeId] ?? 0) + $purchase->getQuantity();
            }
        }

        return $quantities;
    }

    /**
     * @param list<array<string, mixed>> $materials
     * @param array<string, mixed> $node
     * @param list<int> $purchasedTypeIds
     * @param array<int, int> $inStockQuantities
     * @param list<int> $excludedTypeIds
     * @param array<int, float> $structureBonusOverrides
     */
    private function collectRawMaterials(array &$materials, array $node, array $purchasedTypeIds, array &$inStockQuantities, array $excludedTypeIds, User $user, array $structureBonusOverrides): void
    {
        foreach ($node['materials'] as $material) {
            $typeId = (int) $material['typeId'];
            $neededQuantity = (int) $material['quantity'];

            $availableStock = $inStockQuantities[$typeId] ?? 0;
            $isPartlyInStock = false;
            if ($availableStock > 0) {
                if ($availableStock >= $neededQuantity) {
                    $inStockQuantities[$typeId] -= $neededQuantity;
                    continue;
                }
                $neededQuantity -= $availableStock;
                $inStockQuantities[$typeId] = 0;
                $isPartlyInStock = true;
            }

            if (in_array($typeId, $purchasedTypeIds, true)) {
                $this->addToMaterialList($materials, $typeId, $material['typeName'], $neededQuantity);
                continue;
            }

            if (($material['isBuildable'] ?? false) && isset($material['blueprint'])) {
                $subtree = $isPartlyInStock
                    ? $this->treeService->buildIntermediateTree(
                        $typeId,
                        $neededQuantity,
                        $excludedTypeIds,
                        $user,
                        $structureBonusOverrides,
                    )
                    : $material['blueprint'];
                $this->collectRawMaterials($materials, $subtree, $purchasedTypeIds, $inStockQuantities, $excludedTypeIds, $user, $structureBonusOverrides);
            } else {
                $this->addToMaterialList($materials, $typeId, $material['typeName'], $neededQuantity);
            }
        }
    }

    /** @param list<array<string, mixed>> $materials */
    private function addToMaterialList(array &$materials, int $typeId, string $typeName, int $quantity): void
    {
        foreach ($materials as &$mat) {
            if ($mat['typeId'] === $typeId) {
                $mat['quantity'] += $quantity;
                return;
            }
        }
        $materials[] = [
            'typeId' => $typeId,
            'typeName' => $typeName,
            'quantity' => $quantity,
        ];
    }
}
