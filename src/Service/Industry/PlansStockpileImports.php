<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\ProductionPlanRequest;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Intermediate;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Plan;
use App\Industry\Domain\PlanTarget;
use App\Industry\Domain\ProductionPlanner;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;

trait PlansStockpileImports
{
    private const int STOCKPILE_IMPORT_TIME_EFFICIENCY = 0;

    private function producerOf(int $productTypeId): IndustryActivityProduct
    {
        return $this->activityProductRepository->findBlueprintForProduct($productTypeId, IndustryActivityType::Manufacturing->value)
            ?? $this->activityProductRepository->findBlueprintForProduct($productTypeId, IndustryActivityType::Reaction->value)
            ?? throw new \RuntimeException("No blueprint or reaction found for product type ID {$productTypeId}");
    }

    /**
     * @param array<int, Quantity> $startingStock
     */
    private function planOfImport(User $user, IndustryActivityProduct $producer, int $runs, int $me, array $startingStock = []): Plan
    {
        $isReaction = $producer->getActivityId() === IndustryActivityType::Reaction->value;
        $target = new PlanTarget(
            $producer->getProductTypeId(),
            new Runs($runs),
            new BlueprintEfficiency(
                new MaterialEfficiency($isReaction ? ProductionPlanner::REACTION_EFFICIENCY_LEVEL : $me),
                new TimeEfficiency(self::STOCKPILE_IMPORT_TIME_EFFICIENCY),
            ),
        );
        $userId = $user->getId() ?? throw new \LogicException('A user without id has no configured structure.');

        try {
            return $this->productionPlanning->planOf(new ProductionPlanRequest(
                [$target],
                $this->userStructures->configuredBy($userId->toRfc4122()),
                FavoriteSystems::none(),
                startingStock: $startingStock,
            ));
        } catch (\DomainException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<int, int> the demand of each intermediate, the quantity to buy of each leaf
     */
    private static function needsOf(Plan $plan): array
    {
        return array_map(static fn (Intermediate $intermediate): int => $intermediate->demand->value, $plan->intermediates)
            + array_map(static fn (Quantity $quantity): int => $quantity->value, $plan->leaves);
    }
}
