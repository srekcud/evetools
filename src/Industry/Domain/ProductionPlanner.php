<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Turns targets into jobs, intermediates and leaves (spec R3, R9, R12, R13).
 *
 * Every intermediate is processed after all its consumers, so its demand is the sum of R1 over jobs already split.
 */
final readonly class ProductionPlanner
{
    /** Current default of the application for manufactured intermediates (spec R3). */
    private const int MANUFACTURED_INTERMEDIATE_ME = 10;
    private const int MANUFACTURED_INTERMEDIATE_TE = 20;

    /** Reaction formulas cannot be researched (spec R2). */
    private const int REACTION_EFFICIENCY_LEVEL = 0;

    private const int PERCENT = 100;

    public function __construct(private BlueprintCatalog $catalog)
    {
    }

    /**
     * Plan mode: whole runs per intermediate, BPC run cap, explicit surplus.
     */
    public function plan(PlanRequest $request): Plan
    {
        $jobs = [];
        foreach ($request->targets as $target) {
            $recipe = $this->recipeOfTarget($target);
            array_push($jobs, ...self::splitIntoJobs($recipe, $target->runs->value, $target->efficiency, $request));
        }
        $demand = [];
        self::addMaterialsOf($jobs, $demand);

        $intermediates = [];
        foreach ($this->intermediatesInProductionOrder($request) as $productTypeId => $recipe) {
            if (!isset($demand[$productTypeId])) {
                // Every consumer was covered by stock upstream: no demand, no subtree.
                continue;
            }
            $demanded = $demand[$productTypeId];
            $consumedStock = min(self::stockOf($productTypeId, $request), $demanded);
            $toBuild = $demanded - $consumedStock;
            $runs = (int) ceil($toBuild / $recipe->outputPerRun->value);
            $quantityProduced = $runs * $recipe->outputPerRun->value;

            $intermediateJobs = 0 === $runs ? [] : self::splitIntoJobs($recipe, $runs, self::intermediateEfficiency($recipe, $request), $request);
            self::addMaterialsOf($intermediateJobs, $demand);
            array_push($jobs, ...$intermediateJobs);

            $intermediates[$productTypeId] = new Intermediate(
                demand: new Quantity($demanded),
                consumedStock: new Quantity($consumedStock),
                quantityProduced: new Quantity($quantityProduced),
                surplus: new Quantity($quantityProduced - $toBuild),
                jobs: $intermediateJobs,
            );
        }

        $leaves = [];
        foreach (array_diff_key($demand, $intermediates) as $typeId => $demanded) {
            $toBuy = $demanded - min(self::stockOf($typeId, $request), $demanded);
            if ($toBuy > 0) {
                $leaves[$typeId] = new Quantity($toBuy);
            }
        }

        return new Plan($jobs, $leaves, $intermediates);
    }

    /**
     * Marginal cost mode (D4b): an intermediate is paid pro rata of its demand. No ceil, no round, no one-unit-per-run
     * minimum, no BPC cap, on the targets too.
     */
    public function marginalCost(PlanRequest $request): MarginalCostPlan
    {
        $demand = [];
        foreach ($request->targets as $target) {
            $recipe = $this->recipeOfTarget($target);
            self::addFractionalMaterials($demand, $recipe, $target->runs->value, $target->efficiency, $request);
        }

        $fractionalRuns = [];
        foreach ($this->intermediatesInProductionOrder($request) as $productTypeId => $recipe) {
            if (!isset($demand[$productTypeId])) {
                continue;
            }
            $demanded = $demand[$productTypeId];
            $runs = ($demanded - min(self::stockOf($productTypeId, $request), $demanded)) / $recipe->outputPerRun->value;
            $fractionalRuns[$productTypeId] = $runs;
            self::addFractionalMaterials($demand, $recipe, $runs, self::intermediateEfficiency($recipe, $request), $request);
        }

        $leaves = [];
        foreach (array_diff_key($demand, $fractionalRuns) as $typeId => $demanded) {
            $toBuy = $demanded - min(self::stockOf($typeId, $request), $demanded);
            if ($toBuy > 0) {
                $leaves[$typeId] = $toBuy;
            }
        }

        return new MarginalCostPlan($leaves, $fractionalRuns);
    }

    private function recipeOfTarget(PlanTarget $target): Recipe
    {
        return $this->catalog->recipeFor($target->productTypeId)
            ?? throw new \DomainException(\sprintf('Target %d has no blueprint nor reaction: it cannot be built.', $target->productTypeId));
    }

    private function buildableRecipe(int $typeId, PlanRequest $request): ?Recipe
    {
        if (\in_array($typeId, $request->blacklist, true)) {
            return null;
        }

        return $this->catalog->recipeFor($typeId);
    }

    /**
     * Topological order of the intermediates: reverse post-order of a depth-first walk from the targets.
     *
     * @return array<int, Recipe> by product typeId, every consumer before its materials
     */
    private function intermediatesInProductionOrder(PlanRequest $request): array
    {
        $finished = [];
        foreach ($request->targets as $target) {
            $this->walkMaterials($this->recipeOfTarget($target), $request, [], $finished);
        }

        return array_reverse($finished, true);
    }

    /**
     * @param array<int, true>   $path     intermediates being expanded down to $recipe; a target met again is caught one level below
     * @param array<int, Recipe> $finished intermediates whose materials are all finished, in post-order
     */
    private function walkMaterials(Recipe $recipe, PlanRequest $request, array $path, array &$finished): void
    {
        foreach ($recipe->materials as $material) {
            $materialRecipe = $this->buildableRecipe($material->typeId, $request);
            if (null === $materialRecipe || isset($finished[$material->typeId])) {
                continue;
            }
            if (isset($path[$material->typeId])) {
                throw new \DomainException(\sprintf('Product %d is needed to build itself: the production graph has a cycle.', $material->typeId));
            }
            $this->walkMaterials($materialRecipe, $request, $path + [$material->typeId => true], $finished);
            $finished[$material->typeId] = $materialRecipe;
        }
    }

    private static function intermediateEfficiency(Recipe $recipe, PlanRequest $request): BlueprintEfficiency
    {
        if (isset($request->intermediateEfficiencies[$recipe->productTypeId])) {
            return $request->intermediateEfficiencies[$recipe->productTypeId];
        }

        return ActivityKind::Reaction === $recipe->activity
            ? new BlueprintEfficiency(new MaterialEfficiency(self::REACTION_EFFICIENCY_LEVEL), new TimeEfficiency(self::REACTION_EFFICIENCY_LEVEL))
            : new BlueprintEfficiency(new MaterialEfficiency(self::MANUFACTURED_INTERMEDIATE_ME), new TimeEfficiency(self::MANUFACTURED_INTERMEDIATE_TE));
    }

    private static function stockOf(int $typeId, PlanRequest $request): int
    {
        if (!isset($request->startingStock[$typeId])) {
            return 0;
        }

        return $request->startingStock[$typeId]->value;
    }

    /**
     * One job per BPC when a run cap applies (D2): ceil(runs / cap) jobs, runs spread evenly, the first ones taking
     * the remainder. Materials are recomputed for each job (R13).
     *
     * @return list<Job>
     */
    private static function splitIntoJobs(Recipe $recipe, int $runs, BlueprintEfficiency $efficiency, PlanRequest $request): array
    {
        $jobCount = isset($request->blueprintCopyMaxRuns[$recipe->productTypeId])
            ? (int) ceil($runs / $request->blueprintCopyMaxRuns[$recipe->productTypeId]->value)
            : 1;
        $runsPerJob = intdiv($runs, $jobCount);
        $jobsWithOneMoreRun = $runs % $jobCount;
        $materialModifier = $request->materialModifiers->forJob($recipe);

        $jobs = [];
        for ($jobIndex = 0; $jobIndex < $jobCount; ++$jobIndex) {
            $jobRuns = new Runs($jobIndex < $jobsWithOneMoreRun ? $runsPerJob + 1 : $runsPerJob);
            $jobs[] = new Job(
                productTypeId: $recipe->productTypeId,
                activity: $recipe->activity,
                runs: $jobRuns,
                materialEfficiency: $efficiency->materialEfficiency,
                timeEfficiency: $efficiency->timeEfficiency,
                materials: self::jobMaterials($recipe, $jobRuns, $efficiency->materialEfficiency, $materialModifier),
            );
        }

        return $jobs;
    }

    /**
     * @return array<int, Quantity> by material typeId
     */
    private static function jobMaterials(Recipe $recipe, Runs $runs, MaterialEfficiency $materialEfficiency, Multiplier $materialModifier): array
    {
        $materials = [];
        foreach ($recipe->materials as $material) {
            $materials[$material->typeId] = ActivityKind::Reaction === $recipe->activity
                ? MaterialQuantity::forReactionJob($material->baseQuantityPerRun, $runs, $materialModifier)
                : MaterialQuantity::forManufacturingJob($material->baseQuantityPerRun, $runs, $materialEfficiency, $materialModifier);
        }

        return $materials;
    }

    /**
     * @param list<Job>       $jobs
     * @param array<int, int> $demand units consumed by material typeId
     */
    private static function addMaterialsOf(array $jobs, array &$demand): void
    {
        foreach ($jobs as $job) {
            foreach ($job->materials as $materialTypeId => $quantity) {
                $demand[$materialTypeId] = isset($demand[$materialTypeId]) ? $demand[$materialTypeId] + $quantity->value : $quantity->value;
            }
        }
    }

    /**
     * @param array<int, float> $demand fractional units consumed by material typeId
     */
    private static function addFractionalMaterials(array &$demand, Recipe $recipe, float $runs, BlueprintEfficiency $efficiency, PlanRequest $request): void
    {
        $materialEfficiency = ActivityKind::Reaction === $recipe->activity
            ? self::REACTION_EFFICIENCY_LEVEL
            : $efficiency->materialEfficiency->value;
        $materialModifier = $request->materialModifiers->forJob($recipe)->value;

        foreach ($recipe->materials as $material) {
            $quantity = $runs * $material->baseQuantityPerRun->value * (1 - $materialEfficiency / self::PERCENT) * $materialModifier;
            $demand[$material->typeId] = isset($demand[$material->typeId]) ? $demand[$material->typeId] + $quantity : $quantity;
        }
    }
}
