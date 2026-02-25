<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Enum\IndustryActivityType;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recalculates step quantities and runs within an existing project.
 *
 * Iterates depth by depth (0 -> max): for each step, looks up SDE materials
 * and accumulates how much each child step needs to produce. Then updates
 * child step quantity/runs. Handles split groups by redistributing proportionally.
 */
class IndustryStepCalculator
{
    public function __construct(
        private readonly IndustryCalculationService $calculationService,
        private readonly IndustryActivityMaterialRepository $materialRepository,
        private readonly IndustryActivityProductRepository $productRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Recalculate step quantities based on current ME/structure values.
     *
     * @return IndustryProjectStep[] Steps whose quantity/runs changed
     */
    public function recalculateStepQuantities(IndustryProject $project): array
    {
        $steps = $project->getSteps()->toArray();
        if (empty($steps)) {
            return [];
        }

        // Build lookup: productTypeId -> steps (handles split groups)
        $stepsByProduct = [];
        foreach ($steps as $step) {
            if ($step->getActivityType() === 'copy') {
                continue;
            }
            $stepsByProduct[$step->getProductTypeId()][] = $step;
        }

        // Find max depth
        $maxDepth = 0;
        foreach ($steps as $step) {
            $maxDepth = max($maxDepth, $step->getDepth());
        }

        // Preload all materials and products for all non-copy steps in batch
        $allBlueprintTypeIds = [];
        $allActivityIds = [];
        foreach ($steps as $step) {
            if ($step->getActivityType() === 'copy') {
                continue;
            }
            $activityId = $step->getActivityType() === 'reaction'
                ? IndustryActivityType::Reaction->value
                : IndustryActivityType::Manufacturing->value;
            $allBlueprintTypeIds[] = $step->getBlueprintTypeId();
            $allActivityIds[] = $activityId;
        }
        $materialsByKey = $this->materialRepository->findMaterialEntitiesForBlueprints($allBlueprintTypeIds, $allActivityIds);
        $productsByKey = $this->productRepository->findProductsForBlueprints($allBlueprintTypeIds, $allActivityIds);

        $updatedSteps = [];

        // Process depth by depth: accumulate material needs, update child steps
        for ($depth = 0; $depth < $maxDepth; $depth++) {
            // Collect material needs from all steps at this depth
            $neededByTypeId = [];

            foreach ($steps as $step) {
                if ($step->getDepth() !== $depth || $step->getActivityType() === 'copy') {
                    continue;
                }

                $activityId = $step->getActivityType() === 'reaction'
                    ? IndustryActivityType::Reaction->value
                    : IndustryActivityType::Manufacturing->value;

                $materialKey = $step->getBlueprintTypeId() . '-' . $activityId;
                $materials = $materialsByKey[$materialKey] ?? [];

                $structureData = $this->calculationService->getStructureBonusForStep($step);
                $materialBonus = $structureData['materialBonus'];

                foreach ($materials as $material) {
                    $materialTypeId = $material->getMaterialTypeId();

                    // Only process materials that have corresponding steps
                    if (!isset($stepsByProduct[$materialTypeId])) {
                        continue;
                    }

                    $needed = $this->calculationService->calculateMaterialQuantity(
                        $material->getQuantity(),
                        $step->getRuns(),
                        $step->getMeLevel(),
                        $materialBonus['base'],
                        $materialBonus['rig'],
                    );

                    $neededByTypeId[$materialTypeId] = ($neededByTypeId[$materialTypeId] ?? 0) + $needed;
                }
            }

            // Update child steps at depth+1
            foreach ($neededByTypeId as $typeId => $newTotalQuantity) {
                $childSteps = $stepsByProduct[$typeId] ?? [];
                if (empty($childSteps)) {
                    continue;
                }

                // Get output per run from preloaded products
                $firstChild = $childSteps[0];
                $childActivityId = $firstChild->getActivityType() === 'reaction'
                    ? IndustryActivityType::Reaction->value
                    : IndustryActivityType::Manufacturing->value;
                $productKey = $firstChild->getBlueprintTypeId() . '-' . $childActivityId;
                $product = $productsByKey[$productKey] ?? null;
                $outputPerRun = $product?->getQuantity() ?? 1;

                $newTotalRuns = (int) ceil($newTotalQuantity / $outputPerRun);

                // Single step (no split)
                if (count($childSteps) === 1) {
                    $child = $childSteps[0];
                    if ($child->getQuantity() !== $newTotalQuantity || $child->getRuns() !== $newTotalRuns) {
                        $child->setQuantity($newTotalQuantity);
                        $child->setRuns($newTotalRuns);
                        $updatedSteps[] = $child;
                    }
                    continue;
                }

                // Split group: redistribute runs proportionally
                $oldTotalRuns = 0;
                foreach ($childSteps as $child) {
                    $oldTotalRuns += $child->getRuns();
                }

                if ($oldTotalRuns === 0) {
                    $oldTotalRuns = 1;
                }

                $assignedRuns = 0;
                foreach ($childSteps as $i => $child) {
                    $isLast = ($i === count($childSteps) - 1);

                    if ($isLast) {
                        $childRuns = $newTotalRuns - $assignedRuns;
                    } else {
                        $childRuns = (int) round($newTotalRuns * $child->getRuns() / $oldTotalRuns);
                    }
                    $childRuns = max(1, $childRuns);
                    $childQuantity = $childRuns * $outputPerRun;
                    $assignedRuns += $childRuns;

                    if ($child->getQuantity() !== $childQuantity || $child->getRuns() !== $childRuns) {
                        $child->setQuantity($childQuantity);
                        $child->setRuns($childRuns);
                        $child->setTotalGroupRuns($newTotalRuns);
                        $updatedSteps[] = $child;
                    }
                }
            }
        }

        if (!empty($updatedSteps)) {
            $this->entityManager->flush();
        }

        return $updatedSteps;
    }
}
