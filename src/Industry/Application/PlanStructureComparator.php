<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintCatalog;
use App\Industry\Domain\Isk;
use App\Industry\Domain\Job;
use App\Industry\Domain\Recipe;

final readonly class PlanStructureComparator
{
    public function __construct(
        private BlueprintCatalog $blueprints,
        private RigCategories $rigCategories,
        private StructureTotalCostComparator $totalCostComparator,
    ) {
    }

    /**
     * @param list<ProductionStructure> $structures
     *
     * @return list<JobStructureComparison>
     */
    public function compare(PlannedProduction $planned, array $structures, FavoriteSystems $favoriteSystems): array
    {
        $jobsByGroup = [];
        foreach ($planned->plan->jobs as $job) {
            if (ActivityKind::Manufacturing === $job->activity || ActivityKind::Reaction === $job->activity) {
                $jobsByGroup[PlannedProduction::choiceKey($job->productTypeId, $job->activity)][] = $job;
            }
        }

        return array_values(array_map(
            fn (array $jobs): JobStructureComparison => $this->groupOf($jobs, $planned, $structures, $favoriteSystems),
            $jobsByGroup,
        ));
    }

    /**
     * @param non-empty-list<Job>       $jobs
     * @param list<ProductionStructure> $structures
     */
    private function groupOf(array $jobs, PlannedProduction $planned, array $structures, FavoriteSystems $favoriteSystems): JobStructureComparison
    {
        $firstJob = $jobs[0];
        $activity = $firstJob->activity;
        $recipe = $this->blueprints->recipeFor($firstJob->productTypeId)
            ?? throw new \LogicException(\sprintf('Job of product %d has no recipe.', $firstJob->productTypeId));
        $category = $this->rigCategories->categoryOf($firstJob->productTypeId, $activity);
        $favoriteSolarSystemId = $favoriteSystems->forActivity($activity);

        $candidates = [];
        foreach ($structures as $structure) {
            if ($structure->canRun($activity)) {
                $candidates[] = [$structure, $this->breakdownOf($jobs, $recipe, $category, $structure, $favoriteSolarSystemId)];
            }
        }
        usort($candidates, static fn (array $left, array $right): int => [!$favoriteSystems->contains($left[0], $activity), self::comparableTotalOf($left[1]), $left[0]->id] <=> [!$favoriteSystems->contains($right[0], $activity), self::comparableTotalOf($right[1]), $right[0]->id]);

        $choice = $planned->structureChoiceFor($firstJob->productTypeId, $activity) ?? StructureChoice::notConfigured();
        $chosenId = $choice->structure?->id;
        $totals = array_map(static fn (array $candidate): float => self::comparableTotalOf($candidate[1]), $candidates);
        $chosenIndex = null === $chosenId ? null : array_find_key($candidates, static fn (array $candidate): bool => $candidate[0]->id === $chosenId);
        $chosenTotal = null === $chosenIndex || \INF === $totals[$chosenIndex] ? null : $totals[$chosenIndex];
        $lowestTotal = [] === $totals ? \INF : min($totals);
        $cheapestIndex = \INF === $lowestTotal ? null : array_key_first(array_filter($totals, static fn (float $total): bool => $total === $lowestTotal));

        $rows = [];
        foreach ($candidates as $index => [$structure, $breakdown]) {
            $rows[] = new StructureComparisonRow(
                $structure,
                $favoriteSystems->contains($structure, $activity),
                $structure->materialMultiplierFor($activity, $category),
                $breakdown,
                null !== $chosenTotal && \INF !== $totals[$index] ? $totals[$index] - $chosenTotal : null,
            );
        }

        return new JobStructureComparison(
            $firstJob->productTypeId,
            $activity,
            \count($jobs),
            array_sum(array_map(static fn (Job $job): int => $job->runs->value, $jobs)),
            $chosenId,
            $choice->status,
            $rows,
            null === $cheapestIndex ? null : $candidates[$cheapestIndex][0]->id,
            null === $chosenTotal || $chosenTotal - $lowestTotal <= 0.0 ? null : new Isk($chosenTotal - $lowestTotal),
        );
    }

    /**
     * @param non-empty-list<Job> $jobs
     */
    private function breakdownOf(array $jobs, Recipe $recipe, ?string $category, ProductionStructure $structure, ?int $favoriteSolarSystemId): StructureCostBreakdown
    {
        $breakdownOfJob = fn (Job $job): StructureCostBreakdown => $this->totalCostComparator->breakdownOf($recipe, $job->runs, $category, $structure, $favoriteSolarSystemId, false);
        $breakdown = $breakdownOfJob($jobs[0]);
        foreach (\array_slice($jobs, 1) as $job) {
            $breakdown = $breakdown->plus($breakdownOfJob($job));
        }

        return $breakdown;
    }

    private static function comparableTotalOf(StructureCostBreakdown $breakdown): float
    {
        $total = $breakdown->total();

        return $total->isKnown() ? $total->amount()->amount : \INF;
    }
}
