<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\Sde\IndustryActivityProduct;
use App\Enum\IndustryActivityType;
use App\Exception\BlueprintProductMissingFromSdeException;
use App\Exception\ProducerMissingFromSdeException;
use App\Repository\Sde\IndustryActivityProductRepository;

final class SplitGroupMaterials
{
    /**
     * @param list<int> $jobRuns
     * @param array{total: float, base: float, rig: float} $materialBonus
     */
    public static function materialsOfJobs(IndustryCalculationService $calculationService, int $baseQuantityPerRun, array $jobRuns, int $meLevel, array $materialBonus): int
    {
        return array_sum(array_map(
            static fn (int $runs): int => $calculationService->calculateMaterialQuantity($baseQuantityPerRun, $runs, $meLevel, $materialBonus['base'], $materialBonus['rig']),
            $jobRuns,
        ));
    }

    public static function outputPerRunOf(IndustryActivityProductRepository $productRepository, int $blueprintTypeId, string $activityType): int
    {
        return self::outputPerRunOfProduct(
            $productRepository->findOneBy([
                'typeId' => $blueprintTypeId,
                'activityId' => self::activityIdOf($activityType),
            ]),
            $blueprintTypeId,
        );
    }

    public static function outputPerRunOfProduct(?IndustryActivityProduct $product, int $blueprintTypeId): int
    {
        if ($product === null) {
            throw new BlueprintProductMissingFromSdeException($blueprintTypeId);
        }

        return $product->getQuantity();
    }

    public static function producerOf(IndustryActivityProductRepository $productRepository, int $productTypeId): IndustryActivityProduct
    {
        return $productRepository->findBlueprintForProduct($productTypeId, IndustryActivityType::Manufacturing->value)
            ?? $productRepository->findBlueprintForProduct($productTypeId, IndustryActivityType::Reaction->value)
            ?? throw new ProducerMissingFromSdeException($productTypeId);
    }

    public static function activityIdOf(string $activityType): int
    {
        return $activityType === 'reaction'
            ? IndustryActivityType::Reaction->value
            : IndustryActivityType::Manufacturing->value;
    }
}
