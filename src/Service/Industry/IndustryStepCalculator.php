<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use Doctrine\ORM\EntityManagerInterface;

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
     * @return IndustryProjectStep[] Steps whose quantity/runs changed
     */
    public function recalculateStepQuantities(IndustryProject $project): array
    {
        $stepsByProduct = ProductionStepGraph::productionStepsByProduct($project);
        $productionSteps = array_merge(...$stepsByProduct);
        $blueprintTypeIds = array_map(static fn (IndustryProjectStep $step): int => $step->getBlueprintTypeId(), $productionSteps);
        $activityIds = array_map(static fn (IndustryProjectStep $step): int => SplitGroupMaterials::activityIdOf($step->getActivityType()), $productionSteps);
        $materialsByKey = $this->materialRepository->findMaterialEntitiesForBlueprints($blueprintTypeIds, $activityIds);
        $productsByKey = $this->productRepository->findProductsForBlueprints($blueprintTypeIds, $activityIds);

        $needByProduct = [];
        $updatedSteps = [];

        foreach (ProductionStepGraph::productsFromConsumersToMaterials($project, $stepsByProduct, $materialsByKey) as $productTypeId) {
            if (isset($needByProduct[$productTypeId])) {
                array_push($updatedSteps, ...SplitGroupQuantities::applyNeedToSteps(
                    $stepsByProduct[$productTypeId],
                    $needByProduct[$productTypeId],
                    $this->outputPerRunOf($stepsByProduct[$productTypeId][0], $productsByKey),
                ));
            }

            foreach ($stepsByProduct[$productTypeId] as $consumer) {
                foreach (ProductionStepGraph::stepMaterialsOf($consumer, $stepsByProduct, $materialsByKey) as $material) {
                    $materialTypeId = $material->getMaterialTypeId();
                    $needByProduct[$materialTypeId] = ($needByProduct[$materialTypeId] ?? 0) + $this->needOfConsumer($consumer, $material);
                }
            }
        }

        if ($updatedSteps !== []) {
            $this->entityManager->flush();
        }

        return $updatedSteps;
    }

    private function needOfConsumer(IndustryProjectStep $consumer, IndustryActivityMaterial $material): int
    {
        $materialBonus = $this->calculationService->getStructureBonusForStep($consumer)['materialBonus'];

        return $this->calculationService->calculateMaterialQuantity(
            $material->getQuantity(),
            $consumer->getRuns(),
            $consumer->getMeLevel(),
            $materialBonus['base'],
            $materialBonus['rig'],
        );
    }

    /**
     * @param array<string, IndustryActivityProduct> $productsByKey
     */
    private function outputPerRunOf(IndustryProjectStep $step, array $productsByKey): int
    {
        $product = $productsByKey[ProductionStepGraph::blueprintKeyOf($step)] ?? null;

        return $product?->getQuantity() ?? 1;
    }
}
