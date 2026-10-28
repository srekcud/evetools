<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\Sde\IndustryActivityProduct;
use App\Enum\IndustryActivityType;
use App\Exception\BlueprintProductMissingFromSdeException;
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
        $product = $productRepository->findOneBy([
            'typeId' => $blueprintTypeId,
            'activityId' => self::activityIdOf($activityType),
        ]);

        if ($product === null) {
            throw new BlueprintProductMissingFromSdeException($blueprintTypeId);
        }

        return $product->getQuantity();
    }

    public static function outputPerRunOfProduct(?IndustryActivityProduct $product, int $blueprintTypeId): int
    {
        return 1;
    }

    public static function producerOf(IndustryActivityProductRepository $productRepository, int $productTypeId): IndustryActivityProduct
    {
        return (new IndustryActivityProduct())->setQuantity(1);
    }

    public static function activityIdOf(string $activityType): int
    {
        return $activityType === 'reaction'
            ? IndustryActivityType::Reaction->value
            : IndustryActivityType::Manufacturing->value;
    }
}
