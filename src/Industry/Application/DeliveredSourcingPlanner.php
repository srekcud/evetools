<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\BlueprintCatalog;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\Quantity;

final readonly class DeliveredSourcingPlanner
{
    public function __construct(
        private BlueprintCatalog $blueprints,
        private JobInstallationCosts $installationCosts,
        private MarketPrices $marketPrices,
        private StructureMarketPrices $structureMarketPrices,
        private ItemVolumes $itemVolumes,
        private WorkTypeClassifier $workTypeClassifier,
    ) {
    }

    /**
     * @param \Closure(list<int>): PlannedProduction $planWithPurchases
     * @param list<int>                              $targetProductTypeIds
     * @param array<int, bool>                       $buys
     * @param array<int, SourcingSide>               $markets
     */
    public function plan(
        PlannedProduction $allBuiltPlan,
        \Closure $planWithPurchases,
        FavoriteSystems $favoriteSystems,
        ?int $marketStructureId,
        ?int $marketStructureSolarSystemId,
        array $targetProductTypeIds,
        array $buys = [],
        array $markets = [],
    ): DeliveredSourcing {
        $universe = SourcingUniverse::of($allBuiltPlan);
        $valuation = $this->valuationOf($universe, $favoriteSystems, $marketStructureId, $marketStructureSolarSystemId, $buys, $markets);

        $boughtTypeIds = [];
        foreach ($universe->builtTypeIds as $typeId) {
            if ($valuation->decisionOf($typeId)->bought) {
                $boughtTypeIds[] = $typeId;
            }
        }
        sort($boughtTypeIds);

        $recalculated = $planWithPurchases($boughtTypeIds);
        $lines = [];
        foreach ([...$universe->builtTypeIds, ...array_filter($universe->leafTypeIds, static fn (int $typeId): bool => $universe->demandOf($typeId) > 0)] as $typeId) {
            $line = $this->lineOf($typeId, $universe, $valuation, $recalculated, $targetProductTypeIds);
            if (null !== $line) {
                $lines[] = $line;
            }
        }

        return new DeliveredSourcing($lines, $this->totalsOf($lines, $recalculated), $boughtTypeIds);
    }

    /**
     * @param array<int, bool>         $buys
     * @param array<int, SourcingSide> $markets
     */
    private function valuationOf(SourcingUniverse $universe, FavoriteSystems $favoriteSystems, ?int $marketStructureId, ?int $marketSolarSystemId, array $buys, array $markets): SourcingValuation
    {
        $typeIds = $universe->consumedTypeIds();
        $demands = [];
        $volumes = [];
        foreach ($typeIds as $typeId) {
            $demands[$typeId] = (float) $universe->demandOf($typeId);
            $volumes[$typeId] = $this->itemVolumes->volumeOf($typeId);
        }

        return new SourcingValuation(
            $universe,
            $this->blueprints,
            $this->installationCosts,
            $this->marketPrices->unitPricesFor($demands),
            null === $marketStructureId ? [] : $this->structureMarketPrices->unitPricesFor($marketStructureId, $typeIds),
            null === $marketStructureId ? [] : $this->structureMarketPrices->sellVolumesFor($marketStructureId, $typeIds),
            $volumes,
            $favoriteSystems->courierHub,
            $marketStructureId,
            $marketSolarSystemId,
            $buys,
            $markets,
        );
    }

    /**
     * @param list<int> $targetProductTypeIds
     */
    private function lineOf(int $typeId, SourcingUniverse $universe, SourcingValuation $valuation, PlannedProduction $recalculated, array $targetProductTypeIds): ?DeliveredSourcingLine
    {
        $decision = $valuation->decisionOf($typeId);
        $recalculatedIntermediate = $recalculated->plan->intermediates[$typeId] ?? null;
        $isBuiltAgain = null !== $recalculatedIntermediate && [] !== $recalculatedIntermediate->jobs;
        if (isset($recalculated->plan->leaves[$typeId])) {
            $quantity = $recalculated->plan->leaves[$typeId]->value;
        } elseif ($isBuiltAgain) {
            $quantity = $recalculatedIntermediate->demand->value;
        } else {
            return null;
        }

        $options = [];
        foreach ($decision->options as $key => $option) {
            $options[$key] = SourcingChoice::Structure === $option->kind
                ? new DeliveredSourcingOption($option->kind, $option->total, $option->price, $option->installation, $option->transport, $option->total->isKnown() && null !== $option->availableQuantity && $option->availableQuantity >= $quantity, $option->availableQuantity, $option->structureId)
                : $option;
        }

        $bought = $decision->bought;
        $chosen = $bought ? $valuation->buySideOf($typeId, $options) : SourcingChoice::Build;
        $activity = $universe->activityByProduct[$typeId] ?? null;

        return new DeliveredSourcingLine(
            $typeId,
            null === $activity ? null : $this->workTypeClassifier->classify($typeId, $activity, $targetProductTypeIds),
            $activity,
            new Quantity($quantity),
            new Quantity($universe->demandOf($typeId)),
            $isBuiltAgain && !$bought ? $recalculatedIntermediate->totalRuns() : 0,
            $isBuiltAgain && !$bought ? $recalculatedIntermediate->surplus : new Quantity(0),
            array_values(array_filter(array_keys($universe->consumption[$typeId]), static fn (int|string $key): bool => SourcingUniverse::UNKNOWN_STATION !== (string) $key)),
            $options,
            SourcingValuation::recommendedOf($options),
            $chosen,
            $bought,
        );
    }

    /**
     * @param list<DeliveredSourcingLine> $lines
     */
    private function totalsOf(array $lines, PlannedProduction $recalculated): DeliveredSourcingTotals
    {
        $nothing = Cost::known(new Isk(0.0));
        $purchases = $nothing;
        $transport = $nothing;
        foreach ($lines as $line) {
            if ($line->bought) {
                $purchases = $purchases->plus($line->chosenTotal() ?? SourcingValuation::unknownCostOf($line->typeId, $line->options));

                continue;
            }
            $build = $line->option(SourcingChoice::Build);
            if (null !== $build) {
                $transport = $transport->plus($build->transport->times($line->quantity->value / $line->universeDemand->value));
            }
        }

        $installation = $nothing;
        foreach ($recalculated->plan->jobs as $job) {
            $recipe = $this->blueprints->recipeFor($job->productTypeId)
                ?? throw new \LogicException(\sprintf('Job of product %d has no recipe.', $job->productTypeId));
            $installation = $installation->plus($this->installationCosts->installationOf($recipe, $job->runs, $recalculated->structureChoiceFor($job->productTypeId, $job->activity)?->structure));
        }

        return new DeliveredSourcingTotals($purchases, $installation, $transport);
    }
}
