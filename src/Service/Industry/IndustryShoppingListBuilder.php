<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;

class IndustryShoppingListBuilder
{
    private const float BONUS_TOLERANCE = 0.001;

    public function __construct(
        private readonly ProductionStepNeedsResolver $needsResolver,
        private readonly IndustryBonusService $bonusService,
    ) {
    }

    /**
     * @return array{materials: list<array<string, mixed>>, failedProducts: list<array{typeId: int, reason: string}>}
     */
    public function getShoppingList(IndustryProject $project): array
    {
        $needs = $this->needsResolver->resolve($project);
        $user = $project->getUser();

        $bestMaterialBonusByStep = [];
        $hasSuboptimalStructure = false;
        foreach ($needs->productionSteps as $step) {
            $materialBonus = $needs->materialBonusOf($step);
            $bestMaterialBonusByStep[spl_object_id($step)] = $this->bestMaterialBonusOf($user, $step);
            if (abs($materialBonus['total'] - $bestMaterialBonusByStep[spl_object_id($step)]['total']) > self::BONUS_TOLERANCE) {
                $hasSuboptimalStructure = true;
            }
        }

        $bestQuantities = [];
        $actualQuantities = [];

        foreach ($needs->needsByProduct as $productTypeId => $productNeed) {
            if ($productNeed->isBought() && $productNeed->quantityToProvide > 0) {
                $bestQuantities[$productTypeId] = $productNeed->quantityToProvide;
                $actualQuantities[$productTypeId] = $productNeed->quantityToProvide;
            }

            foreach ($productNeed->jobs as $index => $job) {
                $effectiveRuns = $productNeed->effectiveRuns[$index];
                if ($productNeed->isFailed || $effectiveRuns === 0) {
                    continue;
                }

                $materialBonus = $needs->materialBonusOf($job);
                $bestMaterialBonus = $bestMaterialBonusByStep[spl_object_id($job)];

                foreach ($needs->materialsByKey[ProductionStepGraph::blueprintKeyOf($job)] ?? [] as $material) {
                    $materialTypeId = $material->getMaterialTypeId();
                    if (isset($needs->stepsByProduct[$materialTypeId])) {
                        continue;
                    }

                    $bestQuantities[$materialTypeId] = ($bestQuantities[$materialTypeId] ?? 0) + $this->needsResolver->materialQuantityOf($material, $job, $effectiveRuns, $bestMaterialBonus);
                    $actualQuantities[$materialTypeId] = ($actualQuantities[$materialTypeId] ?? 0) + $this->needsResolver->materialQuantityOf($material, $job, $effectiveRuns, $materialBonus);
                }
            }
        }

        $materials = [];
        foreach ($bestQuantities as $typeId => $quantity) {
            $material = ['typeId' => $typeId, 'typeName' => $needs->typeNames[$typeId], 'quantity' => $quantity];
            if ($hasSuboptimalStructure) {
                $material['extraQuantity'] = max(0, $actualQuantities[$typeId] - $quantity);
            }
            $materials[] = $material;
        }
        usort($materials, static fn (array $a, array $b): int => strcasecmp($a['typeName'], $b['typeName']));

        return ['materials' => $materials, 'failedProducts' => array_values($needs->failedProducts)];
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
     * @return array{total: float, base: float, rig: float}
     */
    private function bestMaterialBonusOf(User $user, IndustryProjectStep $step): array
    {
        return $this->bonusService->findBestStructureForProduct($user, $step->getProductTypeId(), $step->getActivityType() === 'reaction')['bonus'];
    }
}
