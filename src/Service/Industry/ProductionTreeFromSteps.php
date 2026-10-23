<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\Sde\IndustryActivityMaterial;

class ProductionTreeFromSteps
{
    public function __construct(
        private readonly ProductionStepNeedsResolver $needsResolver,
        private readonly IndustryBonusService $bonusService,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildTree(IndustryProject $project): ?array
    {
        $needs = $this->needsResolver->resolve($project);
        $rootTypeId = $project->getProductTypeId();
        if (!isset($needs->needsByProduct[$rootTypeId])) {
            return null;
        }

        $copiedBlueprintTypeIds = [];
        foreach ($project->getSteps() as $step) {
            if ($step->getActivityType() === 'copy') {
                $copiedBlueprintTypeIds[$step->getBlueprintTypeId()] = true;
            }
        }

        $placedProductTypeIds = [$rootTypeId => true];

        return $this->nodeOf($rootTypeId, 0, $needs, $copiedBlueprintTypeIds, $placedProductTypeIds);
    }

    /**
     * @param array<int, true> $copiedBlueprintTypeIds
     * @param array<int, true> $placedProductTypeIds
     * @return array<string, mixed>|null
     */
    private function nodeOf(int $productTypeId, int $depth, ProductionStepNeeds $needs, array $copiedBlueprintTypeIds, array &$placedProductTypeIds): ?array
    {
        $productNeed = $needs->needsByProduct[$productTypeId];
        if ($productNeed->isFailed) {
            return null;
        }

        $firstJob = $productNeed->jobs[0];
        $totalRuns = array_sum($productNeed->effectiveRuns);
        $materialRows = [];

        if ($totalRuns > 0) {
            foreach ($needs->materialsByKey[ProductionStepGraph::blueprintKeyOf($firstJob)] ?? [] as $material) {
                $materialTypeId = $material->getMaterialTypeId();
                $materialNeed = $needs->needsByProduct[$materialTypeId] ?? null;
                $buildableNeed = $materialNeed !== null && !$materialNeed->isBlacklisted ? $materialNeed : null;

                $row = [
                    'typeId' => $materialTypeId,
                    'typeName' => $needs->typeNames[$materialTypeId],
                    'quantity' => $this->consumedQuantityOf($material, $productNeed, $needs),
                    'isBuildable' => $buildableNeed !== null,
                    'activityType' => $buildableNeed?->jobs[0]->getActivityType(),
                ];

                if ($buildableNeed !== null && !isset($placedProductTypeIds[$materialTypeId])) {
                    $placedProductTypeIds[$materialTypeId] = true;
                    $subtree = $this->nodeOf($materialTypeId, $depth + 1, $needs, $copiedBlueprintTypeIds, $placedProductTypeIds);
                    if ($subtree === null) {
                        return null;
                    }
                    $row['blueprint'] = $subtree;
                }

                $materialRows[] = $row;
            }
        }

        return [
            'blueprintTypeId' => $firstJob->getBlueprintTypeId(),
            'productTypeId' => $productTypeId,
            'productTypeName' => $needs->typeNames[$productTypeId],
            'quantity' => $productNeed->need,
            'runs' => $totalRuns,
            'outputPerRun' => $needs->productsByKey[ProductionStepGraph::blueprintKeyOf($firstJob)]->getQuantity(),
            'depth' => $depth,
            'activityType' => $firstJob->getActivityType(),
            'hasCopy' => isset($copiedBlueprintTypeIds[$firstJob->getBlueprintTypeId()]),
            'materials' => $materialRows,
            'structureBonus' => $needs->materialBonusOf($firstJob)['total'],
            'structureName' => $needs->structureNameOf($firstJob),
            'productCategory' => $this->bonusService->getCategoryForProduct($productTypeId, $firstJob->getActivityType() === 'reaction'),
        ];
    }

    private function consumedQuantityOf(IndustryActivityMaterial $material, StepProductNeed $consumer, ProductionStepNeeds $needs): int
    {
        $quantity = 0;
        foreach ($consumer->jobs as $index => $job) {
            if ($consumer->effectiveRuns[$index] > 0) {
                $quantity += $this->needsResolver->materialQuantityOf($material, $job, $consumer->effectiveRuns[$index], $needs->materialBonusOf($job));
            }
        }

        return $quantity;
    }
}
