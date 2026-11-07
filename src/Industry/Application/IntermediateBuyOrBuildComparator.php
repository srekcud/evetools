<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Intermediate;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\PartialPrice;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Runs;

final readonly class IntermediateBuyOrBuildComparator
{
    public const float IMPORT_ISK_PER_CUBIC_METER_JUMP = StructureTotalCostComparator::TRANSPORT_ISK_PER_CUBIC_METER_JUMP;

    private const int JITA_SOLAR_SYSTEM_ID = 30_000_142;

    public function __construct(
        private IntermediateBuildCosts $buildCosts,
        private MarketPrices $marketPrices,
        private ItemVolumes $itemVolumes,
        private JumpDistances $jumpDistances,
    ) {
    }

    /**
     * @param list<ProductionStructure> $structures
     *
     * @return list<IntermediateComparison>
     */
    public function compare(Plan $plan, array $structures, FavoriteSystems $favoriteSystems): array
    {
        $builtIntermediates = array_filter(
            $plan->intermediates,
            static fn (Intermediate $intermediate): bool => [] !== $intermediate->jobs,
        );
        if ([] === $builtIntermediates) {
            return [];
        }

        $jitaPrices = $this->marketPrices->unitPricesFor(array_map(
            static fn (Intermediate $intermediate): float => $intermediate->demand->value,
            $builtIntermediates,
        ));

        $comparisons = [];
        foreach ($builtIntermediates as $typeId => $intermediate) {
            $comparisons[] = $this->comparisonOf($typeId, $intermediate, $structures, $favoriteSystems, $jitaPrices[$typeId] ?? null);
        }

        return $comparisons;
    }

    /**
     * @param list<ProductionStructure> $structures
     */
    private function comparisonOf(int $typeId, Intermediate $intermediate, array $structures, FavoriteSystems $favoriteSystems, ?WeightedPrice $jitaPrice): IntermediateComparison
    {
        $firstJob = $intermediate->jobs[0];
        $activity = $firstJob->activity;
        $demand = $intermediate->demand->value;

        $buildCost = $this->buildCosts->totalCostOf(
            $typeId,
            new Runs($intermediate->totalRuns()),
            new BlueprintEfficiency($firstJob->materialEfficiency, $firstJob->timeEfficiency),
            array_keys($firstJob->materials),
            $structures,
            $favoriteSystems,
        );
        $jitaCost = self::jitaCostOf($typeId, $demand, $jitaPrice);
        $volumePerUnit = $this->itemVolumes->volumeOf($typeId);
        $jumps = $this->jumpsToWorkshopOf($favoriteSystems->forActivity($activity));
        $importCost = self::importCostOf($volumePerUnit, $demand, $jumps, $typeId);
        $buyCost = $jitaCost->plus($importCost);
        $choice = self::choiceOf($buildCost, $buyCost);

        return new IntermediateComparison(
            $typeId,
            $activity,
            $intermediate->totalRuns(),
            $intermediate->demand,
            $intermediate->quantityProduced,
            $intermediate->surplus,
            $buildCost,
            $jitaCost,
            $importCost,
            $volumePerUnit,
            $jumps,
            $choice,
            self::choiceOf($buildCost, $jitaCost),
            self::savingOf($choice, $buildCost, $buyCost),
            self::savingIfSurplusReusedOf($choice, $intermediate, $buildCost, $buyCost),
        );
    }

    private function jumpsToWorkshopOf(?int $workshopSolarSystemId): ?int
    {
        if (null === $workshopSolarSystemId) {
            return null;
        }

        return $this->jumpDistances->jumpsBetween(self::JITA_SOLAR_SYSTEM_ID, $workshopSolarSystemId);
    }

    private static function jitaCostOf(int $typeId, int $demand, ?WeightedPrice $jitaPrice): Cost
    {
        if (null === $jitaPrice) {
            return Cost::unknown(MissingData::marketPrice($typeId));
        }

        $cost = Cost::known(new Isk($demand * $jitaPrice->unitPrice->amount));

        return $jitaPrice->coverage->isPartial()
            ? $cost->withPartialPrice(new PartialPrice($typeId, $jitaPrice->coverage))
            : $cost;
    }

    private static function importCostOf(?float $volumePerUnit, int $demand, ?int $jumps, int $typeId): Cost
    {
        $missingData = [];
        if (null === $jumps) {
            $missingData[] = MissingData::jumpRoute();
        }
        if (null === $volumePerUnit) {
            $missingData[] = MissingData::itemVolume($typeId);
        }
        if ([] !== $missingData) {
            return Cost::unknown(...$missingData);
        }

        return Cost::known(new Isk($volumePerUnit * $demand * $jumps * self::IMPORT_ISK_PER_CUBIC_METER_JUMP));
    }

    private static function choiceOf(Cost $buildCost, Cost $buyCost): BuyOrBuildChoice
    {
        if (!$buildCost->isKnown() || !$buyCost->isKnown()) {
            return BuyOrBuildChoice::Unknown;
        }

        return $buildCost->amount()->isAtMost($buyCost->amount())
            ? BuyOrBuildChoice::Build
            : BuyOrBuildChoice::Buy;
    }

    private static function savingOf(BuyOrBuildChoice $choice, Cost $buildCost, Cost $buyCost): ?Isk
    {
        if (BuyOrBuildChoice::Unknown === $choice) {
            return null;
        }

        return new Isk(abs($buyCost->amount()->amount - $buildCost->amount()->amount));
    }

    private static function savingIfSurplusReusedOf(BuyOrBuildChoice $choice, Intermediate $intermediate, Cost $buildCost, Cost $buyCost): ?Isk
    {
        if (BuyOrBuildChoice::Buy !== $choice) {
            return null;
        }

        $buildCostIfSurplusReused = $buildCost->amount()->amount * $intermediate->demand->value / $intermediate->quantityProduced->value;
        if ($buildCostIfSurplusReused >= $buyCost->amount()->amount) {
            return null;
        }

        return new Isk($buyCost->amount()->amount - $buildCostIfSurplusReused);
    }
}
