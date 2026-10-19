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
        $stepsByProduct = $this->productionStepsByProduct($project);
        if ($stepsByProduct === []) {
            return [];
        }

        $productionSteps = array_merge(...$stepsByProduct);
        $blueprintTypeIds = array_map(static fn (IndustryProjectStep $step): int => $step->getBlueprintTypeId(), $productionSteps);
        $activityIds = array_map(static fn (IndustryProjectStep $step): int => SplitGroupMaterials::activityIdOf($step->getActivityType()), $productionSteps);
        $materialsByKey = $this->materialRepository->findMaterialEntitiesForBlueprints($blueprintTypeIds, $activityIds);
        $productsByKey = $this->productRepository->findProductsForBlueprints($blueprintTypeIds, $activityIds);

        $pendingConsumersByMaterial = $this->pendingConsumersByMaterial($stepsByProduct, $materialsByKey);
        $readyProducts = array_keys(array_diff_key($stepsByProduct, $pendingConsumersByMaterial));
        $needByProduct = [];
        $processedProductCount = 0;
        $updatedSteps = [];

        while ($readyProducts !== []) {
            $productTypeId = array_shift($readyProducts);
            ++$processedProductCount;

            if (isset($needByProduct[$productTypeId])) {
                array_push($updatedSteps, ...SplitGroupQuantities::applyNeedToSteps(
                    $stepsByProduct[$productTypeId],
                    $needByProduct[$productTypeId],
                    $this->outputPerRunOf($stepsByProduct[$productTypeId][0], $productsByKey),
                ));
            }

            foreach ($stepsByProduct[$productTypeId] as $consumer) {
                foreach ($this->stepMaterialsOf($consumer, $stepsByProduct, $materialsByKey) as $material) {
                    $materialTypeId = $material->getMaterialTypeId();
                    $needByProduct[$materialTypeId] = ($needByProduct[$materialTypeId] ?? 0) + $this->needOfConsumer($consumer, $material);
                }
            }

            foreach ($this->materialProductsOf($stepsByProduct[$productTypeId], $stepsByProduct, $materialsByKey) as $materialTypeId) {
                if (--$pendingConsumersByMaterial[$materialTypeId] === 0) {
                    $readyProducts[] = $materialTypeId;
                }
            }
        }

        if ($processedProductCount !== \count($stepsByProduct)) {
            throw new \LogicException(\sprintf('Project %s has a cycle between the products of its steps: their need cannot be recalculated', $project->getId()));
        }

        if ($updatedSteps !== []) {
            $this->entityManager->flush();
        }

        return $updatedSteps;
    }

    /**
     * @return array<int, list<IndustryProjectStep>>
     */
    private function productionStepsByProduct(IndustryProject $project): array
    {
        $stepsByProduct = [];
        foreach ($project->getSteps() as $step) {
            if ($step->getActivityType() !== 'copy') {
                $stepsByProduct[$step->getProductTypeId()][] = $step;
            }
        }

        return $stepsByProduct;
    }

    /**
     * @param array<int, list<IndustryProjectStep>> $stepsByProduct
     * @param array<string, list<IndustryActivityMaterial>> $materialsByKey
     * @return array<int, int>
     */
    private function pendingConsumersByMaterial(array $stepsByProduct, array $materialsByKey): array
    {
        $pendingConsumersByMaterial = [];
        foreach ($stepsByProduct as $consumerSteps) {
            foreach ($this->materialProductsOf($consumerSteps, $stepsByProduct, $materialsByKey) as $materialTypeId) {
                $pendingConsumersByMaterial[$materialTypeId] = ($pendingConsumersByMaterial[$materialTypeId] ?? 0) + 1;
            }
        }

        return $pendingConsumersByMaterial;
    }

    /**
     * @param list<IndustryProjectStep> $consumerSteps
     * @param array<int, list<IndustryProjectStep>> $stepsByProduct
     * @param array<string, list<IndustryActivityMaterial>> $materialsByKey
     * @return list<int>
     */
    private function materialProductsOf(array $consumerSteps, array $stepsByProduct, array $materialsByKey): array
    {
        $materialTypeIds = [];
        foreach ($consumerSteps as $consumer) {
            foreach ($this->stepMaterialsOf($consumer, $stepsByProduct, $materialsByKey) as $material) {
                $materialTypeIds[] = $material->getMaterialTypeId();
            }
        }

        return $materialTypeIds;
    }

    /**
     * @param array<int, list<IndustryProjectStep>> $stepsByProduct
     * @param array<string, list<IndustryActivityMaterial>> $materialsByKey
     * @return array<int, IndustryActivityMaterial>
     */
    private function stepMaterialsOf(IndustryProjectStep $consumer, array $stepsByProduct, array $materialsByKey): array
    {
        return array_filter(
            $materialsByKey[$this->blueprintKeyOf($consumer)] ?? [],
            static fn (IndustryActivityMaterial $material): bool => isset($stepsByProduct[$material->getMaterialTypeId()]),
        );
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
        $product = $productsByKey[$this->blueprintKeyOf($step)] ?? null;

        return $product?->getQuantity() ?? 1;
    }

    private function blueprintKeyOf(IndustryProjectStep $step): string
    {
        return $step->getBlueprintTypeId().'-'.SplitGroupMaterials::activityIdOf($step->getActivityType());
    }
}
