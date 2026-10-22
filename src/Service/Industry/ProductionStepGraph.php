<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\Sde\IndustryActivityMaterial;

final class ProductionStepGraph
{
    /**
     * @return array<int, list<IndustryProjectStep>>
     */
    public static function productionStepsByProduct(IndustryProject $project): array
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
     * @return list<int>
     */
    public static function productsFromConsumersToMaterials(IndustryProject $project, array $stepsByProduct, array $materialsByKey): array
    {
        $pendingConsumersByMaterial = [];
        foreach ($stepsByProduct as $consumerSteps) {
            foreach (self::materialProductsOf($consumerSteps, $stepsByProduct, $materialsByKey) as $materialTypeId) {
                $pendingConsumersByMaterial[$materialTypeId] = ($pendingConsumersByMaterial[$materialTypeId] ?? 0) + 1;
            }
        }

        $readyProducts = array_keys(array_diff_key($stepsByProduct, $pendingConsumersByMaterial));
        $orderedProducts = [];
        while ($readyProducts !== []) {
            $productTypeId = array_shift($readyProducts);
            $orderedProducts[] = $productTypeId;

            foreach (self::materialProductsOf($stepsByProduct[$productTypeId], $stepsByProduct, $materialsByKey) as $materialTypeId) {
                if (--$pendingConsumersByMaterial[$materialTypeId] === 0) {
                    $readyProducts[] = $materialTypeId;
                }
            }
        }

        if (\count($orderedProducts) !== \count($stepsByProduct)) {
            throw new \LogicException(\sprintf('Project %s has a cycle between the products of its steps: their need cannot be recalculated', $project->getId()));
        }

        return $orderedProducts;
    }

    /**
     * @param array<int, list<IndustryProjectStep>> $stepsByProduct
     * @param array<string, list<IndustryActivityMaterial>> $materialsByKey
     * @return array<int, IndustryActivityMaterial>
     */
    public static function stepMaterialsOf(IndustryProjectStep $consumer, array $stepsByProduct, array $materialsByKey): array
    {
        return array_filter(
            $materialsByKey[self::blueprintKeyOf($consumer)] ?? [],
            static fn (IndustryActivityMaterial $material): bool => isset($stepsByProduct[$material->getMaterialTypeId()]),
        );
    }

    public static function blueprintKeyOf(IndustryProjectStep $step): string
    {
        return $step->getBlueprintTypeId().'-'.SplitGroupMaterials::activityIdOf($step->getActivityType());
    }

    /**
     * @param list<IndustryProjectStep> $consumerSteps
     * @param array<int, list<IndustryProjectStep>> $stepsByProduct
     * @param array<string, list<IndustryActivityMaterial>> $materialsByKey
     * @return list<int>
     */
    private static function materialProductsOf(array $consumerSteps, array $stepsByProduct, array $materialsByKey): array
    {
        $materialTypeIds = [];
        foreach ($consumerSteps as $consumer) {
            foreach (self::stepMaterialsOf($consumer, $stepsByProduct, $materialsByKey) as $material) {
                $materialTypeIds[] = $material->getMaterialTypeId();
            }
        }

        return $materialTypeIds;
    }
}
