<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Cost;
use App\Industry\Domain\EivMaterial;
use App\Industry\Domain\EstimatedItemValue;
use App\Industry\Domain\InstallCost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\MaterialQuantity;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;

final readonly class StructureTotalCostComparator
{
    public const float TRANSPORT_ISK_PER_CUBIC_METER_JUMP = 150.0;

    private const int NO_MATERIAL_EFFICIENCY = 0;

    public function __construct(
        private MarketPrices $marketPrices,
        private AdjustedPrices $adjustedPrices,
        private SystemCostIndices $costIndices,
        private JumpDistances $jumpDistances,
        private ItemVolumes $itemVolumes,
    ) {
    }

    public function totalCostOf(
        Recipe $recipe,
        Runs $referenceRuns,
        ?string $productCategory,
        ProductionStructure $structure,
        int $favoriteSolarSystemId,
        bool $alphaClone,
    ): Cost {
        return $this->breakdownOf($recipe, $referenceRuns, $productCategory, $structure, $favoriteSolarSystemId, $alphaClone)->total();
    }

    public function breakdownOf(
        Recipe $recipe,
        Runs $referenceRuns,
        ?string $productCategory,
        ProductionStructure $structure,
        ?int $favoriteSolarSystemId,
        bool $alphaClone,
    ): StructureCostBreakdown {
        $structureSolarSystemId = $structure->solarSystemId;
        $materials = $this->materialCost($recipe, $referenceRuns, $productCategory, $structure);
        if (null === $structureSolarSystemId) {
            return new StructureCostBreakdown($materials, Cost::unknown(MissingData::costIndex()), Cost::unknown(MissingData::jumpRoute()), null, null);
        }

        $installation = $this->installCost($recipe, $referenceRuns, $structure, $structureSolarSystemId, $alphaClone);
        if (null === $favoriteSolarSystemId) {
            return new StructureCostBreakdown($materials, $installation, Cost::unknown(MissingData::jumpRoute()), null, null);
        }

        $jumps = $this->jumpDistances->jumpsBetween($favoriteSolarSystemId, $structureSolarSystemId);
        if (null === $jumps) {
            return new StructureCostBreakdown($materials, $installation, Cost::unknown(MissingData::jumpRoute()), null, null);
        }

        $cubicMeters = $this->transportedCubicMeters($recipe, $referenceRuns);
        if ($cubicMeters instanceof Cost) {
            return new StructureCostBreakdown($materials, $installation, $cubicMeters, null, $jumps);
        }

        return new StructureCostBreakdown(
            $materials,
            $installation,
            Cost::known(new Isk($cubicMeters * $jumps * self::TRANSPORT_ISK_PER_CUBIC_METER_JUMP)),
            $cubicMeters,
            $jumps,
        );
    }

    private function materialCost(Recipe $recipe, Runs $referenceRuns, ?string $productCategory, ProductionStructure $structure): Cost
    {
        $multiplier = $structure->materialMultiplierFor($recipe->activity, $productCategory);
        $quantities = [];
        foreach ($recipe->materials as $material) {
            $quantities[$material->typeId] = (float) match ($recipe->activity) {
                ActivityKind::Manufacturing => MaterialQuantity::forManufacturingJob($material->baseQuantityPerRun, $referenceRuns, new MaterialEfficiency(self::NO_MATERIAL_EFFICIENCY), $multiplier)->value,
                ActivityKind::Reaction => MaterialQuantity::forReactionJob($material->baseQuantityPerRun, $referenceRuns, $multiplier)->value,
                ActivityKind::Copying, ActivityKind::Invention, ActivityKind::ResearchMaterial, ActivityKind::ResearchTime => throw new \DomainException(\sprintf('Structures are compared at total cost for manufacturing and reactions only, got %s.', $recipe->activity->name)),
            };
        }

        $unitPrices = $this->marketPrices->unitPricesFor($quantities);
        $cost = Cost::known(new Isk(0.0));
        foreach ($quantities as $typeId => $quantity) {
            $cost = $cost->plus(\array_key_exists($typeId, $unitPrices)
                ? Cost::known(new Isk($quantity * $unitPrices[$typeId]->unitPrice->amount))
                : Cost::unknown(MissingData::marketPrice($typeId)));
        }

        return $cost;
    }

    private function installCost(Recipe $recipe, Runs $referenceRuns, ProductionStructure $structure, int $structureSolarSystemId, bool $alphaClone): Cost
    {
        $estimatedItemValue = EstimatedItemValue::of($referenceRuns, array_map(
            fn (RecipeMaterial $material): EivMaterial => new EivMaterial(
                $material->typeId,
                $material->baseQuantityPerRun,
                $this->adjustedPrices->adjustedPriceOf($material->typeId),
            ),
            $recipe->materials,
        ));

        return InstallCost::forJob(
            $recipe->activity,
            $estimatedItemValue,
            $this->costIndices->costIndexOf($structureSolarSystemId, $recipe->activity),
            $structure->costRoleBonus,
            $structure->facilityTaxRate,
            $alphaClone,
        )->total;
    }

    private function transportedCubicMeters(Recipe $recipe, Runs $referenceRuns): float|Cost
    {
        $transported = [[$recipe->productTypeId, $recipe->outputPerRun->value * $referenceRuns->value]];
        foreach ($recipe->materials as $material) {
            $transported[] = [$material->typeId, $material->baseQuantityPerRun->value * $referenceRuns->value];
        }

        $cubicMeters = 0.0;
        $missingVolumes = [];
        foreach ($transported as [$typeId, $units]) {
            $cubicMetersPerUnit = $this->itemVolumes->volumeOf($typeId);
            if (null === $cubicMetersPerUnit) {
                $missingVolumes[] = MissingData::itemVolume($typeId);

                continue;
            }
            $cubicMeters += $units * $cubicMetersPerUnit;
        }

        return [] === $missingVolumes ? $cubicMeters : Cost::unknown(...$missingVolumes);
    }
}
