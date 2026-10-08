<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintCatalog;
use App\Industry\Domain\Cost;
use App\Industry\Domain\EivMaterial;
use App\Industry\Domain\EstimatedItemValue;
use App\Industry\Domain\InstallCost;
use App\Industry\Domain\InventionAttempt;
use App\Industry\Domain\InventionChance;
use App\Industry\Domain\InventionExpectation;
use App\Industry\Domain\InventionOutcome;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\PlanRequest;
use App\Industry\Domain\PlanTarget;
use App\Industry\Domain\ProductionPlanner;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;

/**
 * Production cost of a product (spec R4 to R12): plan or marginal cost plan, structure and install cost of each job,
 * market cost of the bought items, and for a T2 the expected invention.
 */
final readonly class ProductionCostCalculator
{
    /** Install tax of an NPC station, used when no structure of the user can run the job (spec R6). */
    private const float NPC_STATION_FACILITY_TAX_RATE = 0.0025;

    /** One T1 blueprint copy of one run is consumed per invention attempt (spec R6). */
    private const int COPY_RUNS_PER_ATTEMPT = 1;

    /** One decryptor is consumed per invention attempt. */
    private const float DECRYPTORS_PER_ATTEMPT = 1.0;

    private ProductionPlanner $planner;

    public function __construct(
        private BlueprintCatalog $blueprints,
        private InventionCatalog $inventions,
        private MarketPrices $marketPrices,
        private AdjustedPrices $adjustedPrices,
        private SystemCostIndices $costIndices,
    ) {
        $this->planner = new ProductionPlanner($blueprints);
    }

    public function costOf(ProductionCostRequest $request): ProductionCost
    {
        $recipe = $this->recipeOf($request->productTypeId);
        $selector = new StructureSelector($request->structures, $request->favoriteSystems);
        $planRequest = new PlanRequest(
            [new PlanTarget($request->productTypeId, $request->runs, $request->efficiency)],
            new StructureMaterialModifiers($selector, $request->productCategories),
            blacklist: $request->blacklist,
        );

        [$leafQuantities, $jobs] = match ($request->mode) {
            CostMode::Plan => $this->planned($planRequest, $selector, $request),
            CostMode::MarginalCost => $this->marginal($planRequest, $recipe, $selector, $request),
        };

        $leaves = $this->leafCosts($leafQuantities);
        $materialCost = self::sum(array_map(static fn (LeafCost $leaf): Cost => $leaf->cost, $leaves));
        $installCost = self::sum(array_map(static fn (JobCost $job): Cost => $job->installCost->total, $jobs));
        $invention = $this->inventionCost($request, $selector);

        $totalCost = $materialCost->plus($installCost);
        if (null !== $invention) {
            $totalCost = $totalCost->plus($invention->total);
        }
        $producedQuantity = new Quantity($request->runs->value * $recipe->outputPerRun->value);

        return new ProductionCost(
            $leaves,
            $jobs,
            $materialCost,
            $installCost,
            $invention,
            $totalCost,
            $producedQuantity,
            $totalCost->times(1 / $producedQuantity->value),
        );
    }

    /**
     * Plan mode (R3): one JobCost per Job of the plan, whole runs.
     *
     * @return array{array<int, float>, list<JobCost>}
     */
    private function planned(PlanRequest $planRequest, StructureSelector $selector, ProductionCostRequest $request): array
    {
        $plan = $this->planner->plan($planRequest);

        $jobs = [];
        foreach ($plan->jobs as $job) {
            $recipe = $this->recipeOf($job->productTypeId);
            $jobs[] = $this->jobCost(
                $recipe,
                $job->runs->value,
                EstimatedItemValue::of($job->runs, $this->eivMaterials($recipe)),
                self::structureChoiceOf($recipe, $selector, $request),
                $request,
            );
        }

        return [array_map(static fn (Quantity $quantity): float => $quantity->value, $plan->leaves), $jobs];
    }

    /**
     * Marginal cost mode (D4b): the target for the requested runs, each intermediate pro rata of its fractional runs.
     *
     * @return array{array<int, float>, list<JobCost>}
     */
    private function marginal(PlanRequest $planRequest, Recipe $targetRecipe, StructureSelector $selector, ProductionCostRequest $request): array
    {
        $marginalCostPlan = $this->planner->marginalCost($planRequest);

        $jobs = [$this->jobCost(
            $targetRecipe,
            $request->runs->value,
            EstimatedItemValue::of($request->runs, $this->eivMaterials($targetRecipe)),
            self::structureChoiceOf($targetRecipe, $selector, $request),
            $request,
        )];
        foreach ($marginalCostPlan->fractionalRuns as $productTypeId => $fractionalRuns) {
            $recipe = $this->recipeOf($productTypeId);
            $jobs[] = $this->jobCost(
                $recipe,
                $fractionalRuns,
                EstimatedItemValue::of(new Runs(1), $this->eivMaterials($recipe))->times($fractionalRuns),
                self::structureChoiceOf($recipe, $selector, $request),
                $request,
            );
        }

        return [$marginalCostPlan->leaves, $jobs];
    }

    /**
     * Same choice as the material modifier of the job; the planner already refused a product without rig category.
     */
    private static function structureChoiceOf(Recipe $recipe, StructureSelector $selector, ProductionCostRequest $request): StructureChoice
    {
        return $selector->select($recipe->activity, $request->productCategories[$recipe->productTypeId]);
    }

    private function jobCost(Recipe $recipe, float $runs, Cost $estimatedItemValue, StructureChoice $structureChoice, ProductionCostRequest $request): JobCost
    {
        $solarSystemId = self::solarSystemOf($structureChoice, $recipe->activity, $request->favoriteSystems);

        return new JobCost(
            $recipe->productTypeId,
            $recipe->activity,
            $runs,
            $structureChoice,
            $solarSystemId,
            $this->installCost($recipe->activity, $estimatedItemValue, $structureChoice, $solarSystemId, $request->alphaClone),
        );
    }

    /**
     * Expected invention of the T2 blueprint copies (R8); null when the product is not invented.
     */
    private function inventionCost(ProductionCostRequest $request, StructureSelector $selector): ?InventionCost
    {
        $inventionRecipe = $this->inventions->inventionOf($request->productTypeId);
        if (null === $inventionRecipe) {
            return null;
        }
        $settings = $request->invention
            ?? throw new \DomainException(\sprintf('Product %d is invented: its invention settings are required.', $request->productTypeId));

        $datacoresCost = self::sum($this->bought(self::quantitiesOf($inventionRecipe->datacores)));
        $decryptorCost = null === $settings->decryptor
            ? Cost::known(new Isk(0.0))
            : self::sum($this->bought([$settings->decryptor->typeId => self::DECRYPTORS_PER_ATTEMPT]));

        // Copying and invention pay on the manufacturing EIV of the copied, or invented, blueprint (R5).
        $inventionInstallCost = $this->installCostOutsidePlan(
            ActivityKind::Invention,
            EstimatedItemValue::of(new Runs(1), $this->eivMaterials($this->recipeOf($request->productTypeId))),
            $selector,
            $request,
        );
        $copyInstallCost = $this->installCostOutsidePlan(
            ActivityKind::Copying,
            EstimatedItemValue::of(new Runs(self::COPY_RUNS_PER_ATTEMPT), $this->eivMaterials($this->recipeOf($inventionRecipe->t1ProductTypeId))),
            $selector,
            $request,
        );
        $attemptCost = InventionAttempt::cost($datacoresCost, $decryptorCost, $inventionInstallCost)->plus($copyInstallCost->total);

        $chance = null === $inventionRecipe->baseProbability
            ? null
            : InventionChance::of(
                $inventionRecipe->baseProbability,
                $settings->firstScienceSkillLevel,
                $settings->secondScienceSkillLevel,
                $settings->encryptionSkillLevel,
                $settings->decryptor,
            );
        $expectation = InventionExpectation::of(
            $inventionRecipe->t1BlueprintTypeId,
            $chance,
            InventionOutcome::of($inventionRecipe->baseRuns, $settings->decryptor),
            $attemptCost,
        );

        return new InventionCost(
            $datacoresCost,
            $decryptorCost,
            $inventionInstallCost,
            $copyInstallCost,
            $attemptCost,
            $expectation->expectedAttempts($request->runs),
            $expectation->costForRuns($request->runs),
        );
    }

    /**
     * Install cost of a copying or invention job: no material at stake, so no product category in the selection.
     */
    private function installCostOutsidePlan(ActivityKind $activity, Cost $estimatedItemValue, StructureSelector $selector, ProductionCostRequest $request): InstallCost
    {
        $structureChoice = $selector->select($activity, null);

        return $this->installCost(
            $activity,
            $estimatedItemValue,
            $structureChoice,
            self::solarSystemOf($structureChoice, $activity, $request->favoriteSystems),
            $request->alphaClone,
        );
    }

    /**
     * Without structure, the job runs in an NPC station: NPC tax, no cost role bonus (R6).
     */
    private function installCost(ActivityKind $activity, Cost $estimatedItemValue, StructureChoice $structureChoice, ?int $solarSystemId, bool $alphaClone): InstallCost
    {
        $structure = $structureChoice->structure;
        $costIndex = null === $solarSystemId ? null : $this->costIndices->costIndexOf($solarSystemId, $activity);

        return null === $structure
            ? InstallCost::forJob($activity, $estimatedItemValue, $costIndex, Multiplier::one(), self::NPC_STATION_FACILITY_TAX_RATE, $alphaClone)
            : InstallCost::forJob($activity, $estimatedItemValue, $costIndex, $structure->costRoleBonus, $structure->facilityTaxRate, $alphaClone);
    }

    /**
     * System of the chosen structure, otherwise the favorite system of the activity; null when neither is known.
     */
    private static function solarSystemOf(StructureChoice $structureChoice, ActivityKind $activity, FavoriteSystems $favoriteSystems): ?int
    {
        return $structureChoice->structure->solarSystemId ?? $favoriteSystems->forActivity($activity);
    }

    /**
     * The bought items, priced in one call to the market (R10).
     *
     * @param array<int, float> $quantities by typeId
     *
     * @return array<int, LeafCost> by typeId
     */
    private function leafCosts(array $quantities): array
    {
        $unitPrices = $this->marketPrices->unitPricesFor($quantities);

        $leaves = [];
        foreach ($quantities as $typeId => $quantity) {
            $leaves[$typeId] = \array_key_exists($typeId, $unitPrices)
                ? new LeafCost($typeId, $quantity, $unitPrices[$typeId], Cost::known(new Isk($quantity * $unitPrices[$typeId]->amount)))
                : new LeafCost($typeId, $quantity, null, Cost::unknown(MissingData::marketPrice($typeId)));
        }

        return $leaves;
    }

    /**
     * @param array<int, float> $quantities by typeId
     *
     * @return array<int, Cost> by typeId
     */
    private function bought(array $quantities): array
    {
        return array_map(static fn (LeafCost $leaf): Cost => $leaf->cost, $this->leafCosts($quantities));
    }

    /**
     * @return list<EivMaterial>
     */
    private function eivMaterials(Recipe $recipe): array
    {
        return array_map(
            fn (RecipeMaterial $material): EivMaterial => new EivMaterial(
                $material->typeId,
                $material->baseQuantityPerRun,
                $this->adjustedPrices->adjustedPriceOf($material->typeId),
            ),
            $recipe->materials,
        );
    }

    private function recipeOf(int $productTypeId): Recipe
    {
        return $this->blueprints->recipeFor($productTypeId)
            ?? throw new \DomainException(\sprintf('Product %d has no blueprint nor reaction: it cannot be built.', $productTypeId));
    }

    /**
     * @param list<RecipeMaterial> $materials
     *
     * @return array<int, float> by typeId
     */
    private static function quantitiesOf(array $materials): array
    {
        $quantities = [];
        foreach ($materials as $material) {
            $quantities[$material->typeId] = $material->baseQuantityPerRun->value;
        }

        return $quantities;
    }

    /**
     * @param array<Cost> $costs
     */
    private static function sum(array $costs): Cost
    {
        return array_reduce(
            $costs,
            static fn (Cost $sum, Cost $cost): Cost => $sum->plus($cost),
            Cost::known(new Isk(0.0)),
        );
    }
}
