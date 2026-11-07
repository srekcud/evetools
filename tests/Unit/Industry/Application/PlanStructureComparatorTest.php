<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\JobStructureComparison;
use App\Industry\Application\PlanStructureComparator;
use App\Industry\Application\PlannedProduction;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureChoice;
use App\Industry\Application\StructureChoiceStatus;
use App\Industry\Application\StructureComparisonRow;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Application\Double\InMemoryItemVolumes;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanStructureComparator::class)]
#[Group('issue-341')]
final class PlanStructureComparatorTest extends TestCase
{
    private const int FERNITE_COMPOSITE_TYPE_ID = 16670;
    private const float ISK_TOLERANCE = 0.01;
    private const int MANUFACTURING_RUNS = 10;
    private const int REACTION_RUNS = 5;
    private const int UNKNOWN_PRODUCT_TYPE_ID = 999_341_001;

    public function testTwoJobsOfTheSameProductAreOneGroupWithTheirRunsAndCostsSummed(): void
    {
        $planned = $this->plannedWith(
            [$this->manufacturingJob(6), $this->manufacturingJob(4)],
            $this->manufacturingChoice(Example::workshopStructureWithoutRig(), StructureChoiceStatus::BestInFavoriteSystem),
        );

        $groups = $this->compare($planned, [Example::workshopStructureWithoutRig(), Example::structureC()]);

        $this->assertCount(1, $groups);
        $group = $groups[0];
        $this->assertSame([Example::PRODUCT_TYPE_ID, ActivityKind::Manufacturing, 2, 10], [$group->productTypeId, $group->activity, $group->jobs, $group->runs]);
        $this->assertSame(['A', 'C'], $this->structureIdsOf($group));
        $workshop = $group->rows[0];
        $this->assertEqualsWithDelta(10_000_000.0, $workshop->breakdown->materials->amount()->amount, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(900_000.0, $workshop->breakdown->installation->amount()->amount, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(0.0, $workshop->breakdown->transport->amount()->amount, self::ISK_TOLERANCE);
        $rigged = $group->rows[1];
        $this->assertEqualsWithDelta(9_000_000.0, $rigged->breakdown->materials->amount()->amount, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(850_000.0, $rigged->breakdown->installation->amount()->amount, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(900_000.0, $rigged->breakdown->transport->amount()->amount, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(10_750_000.0, $this->totalOf($rigged), self::ISK_TOLERANCE);
    }

    public function testCopyingAndInventionJobsAreLeftOutOfTheGroups(): void
    {
        $planned = $this->plannedWith(
            [
                $this->jobOf(Example::PRODUCT_TYPE_ID, ActivityKind::Copying, 3),
                $this->jobOf(Example::PRODUCT_TYPE_ID, ActivityKind::Invention, 2),
                $this->manufacturingJob(4),
            ],
            $this->manufacturingChoice(Example::workshopStructureWithoutRig(), StructureChoiceStatus::BestInFavoriteSystem),
        );

        $groups = $this->compare($planned, [Example::workshopStructureWithoutRig()]);

        $this->assertCount(1, $groups);
        $this->assertSame([ActivityKind::Manufacturing, 1, 4], [$groups[0]->activity, $groups[0]->jobs, $groups[0]->runs]);
    }

    public function testReactionAndManufacturingAreComparedAmongTheStructuresAbleToRunThemInTheOrderOfThePlan(): void
    {
        $planned = $this->plannedWith(
            [$this->jobOf(self::FERNITE_COMPOSITE_TYPE_ID, ActivityKind::Reaction, self::REACTION_RUNS), $this->manufacturingJob(self::MANUFACTURING_RUNS)],
            $this->manufacturingChoice(Example::workshopStructureWithoutRig(), StructureChoiceStatus::BestInFavoriteSystem),
            [PlannedProduction::choiceKey(self::FERNITE_COMPOSITE_TYPE_ID, ActivityKind::Reaction) => new StructureChoice(Example::workshopRefinery(), StructureChoiceStatus::BestInFavoriteSystem, Multiplier::one())],
        );

        $groups = $this->compare($planned, [Example::workshopStructureWithoutRig(), Example::structureC(), Example::riggedRefinery(), Example::workshopRefinery()]);

        $this->assertCount(2, $groups);
        $this->assertSame([ActivityKind::Reaction, ActivityKind::Manufacturing], [$groups[0]->activity, $groups[1]->activity]);
        $this->assertSame(['A-refinery', 'C-refinery'], $this->structureIdsOf($groups[0]));
        $this->assertEqualsWithDelta(5_450_000.0, $this->totalOf($groups[0]->rows[0]), self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(5_375_000.0, $this->totalOf($groups[0]->rows[1]), self::ISK_TOLERANCE);
        $this->assertSame(['A', 'C'], $this->structureIdsOf($groups[1]));
        $this->assertSame('C-refinery', $groups[0]->cheapestStructureId);
        $this->assertEqualsWithDelta(75_000.0, $groups[0]->savingIfCheapest->amount, self::ISK_TOLERANCE);
    }

    public function testRowsListTheStructureOfTheFavoriteSystemFirstThenTheKnownTotalsAscendingThenUnknownTotalsLastAndTiesById(): void
    {
        $planned = $this->plannedWith(
            [$this->manufacturingJob(self::MANUFACTURING_RUNS)],
            $this->manufacturingChoice(Example::workshopStructureWithoutRig(), StructureChoiceStatus::BestInFavoriteSystem),
        );
        $structures = [
            Example::riggedStructure('unreachable', Example::UNREACHABLE_SYSTEM_ID),
            Example::structureD(),
            Example::structureC(),
            Example::riggedStructure('B', Example::SECOND_THREE_JUMPS_SYSTEM_ID),
            Example::workshopStructureWithoutRig(),
        ];

        $groups = $this->compare($planned, $structures);

        $this->assertCount(1, $groups);
        $group = $groups[0];
        $this->assertSame(['A', 'B', 'C', 'D', 'unreachable'], $this->structureIdsOf($group));
        $this->assertSame([true, false, false, false, false], array_map(static fn (StructureComparisonRow $row): bool => $row->inFavoriteSystem, $group->rows));
        $this->assertEqualsWithDelta(1.0, $group->rows[0]->materialMultiplier->value, 1e-9);
        $this->assertEqualsWithDelta(0.9, $group->rows[1]->materialMultiplier->value, 1e-9);
        $this->assertFalse($group->rows[4]->breakdown->total()->isKnown());
        $this->assertNull($group->rows[4]->delta);
    }

    public function testGapToTheRetainedStructureAndSavingIfTheCheapestIsRetainedInsteadOfTheFavoriteStructure(): void
    {
        $planned = $this->plannedWith(
            [$this->manufacturingJob(self::MANUFACTURING_RUNS)],
            $this->manufacturingChoice(Example::workshopStructureWithoutRig(), StructureChoiceStatus::BestInFavoriteSystem),
        );

        $groups = $this->compare($planned, [Example::workshopStructureWithoutRig(), Example::structureC(), Example::structureD()]);

        $this->assertCount(1, $groups);
        $group = $groups[0];
        $this->assertSame(['A', 'C', 'D'], $this->structureIdsOf($group));
        $this->assertSame(['A', StructureChoiceStatus::BestInFavoriteSystem], [$group->chosenStructureId, $group->choiceStatus]);
        $this->assertEqualsWithDelta(0.0, $group->rows[0]->delta, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(-150_000.0, $group->rows[1]->delta, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(1_350_000.0, $group->rows[2]->delta, self::ISK_TOLERANCE);
        $this->assertSame('C', $group->cheapestStructureId);
        $this->assertEqualsWithDelta(150_000.0, $group->savingIfCheapest->amount, self::ISK_TOLERANCE);
    }

    public function testNoSavingIsOfferedWhenTheCheapestStructureIsTheRetainedOneAndTheFavoriteStructureCostsMore(): void
    {
        $planned = $this->plannedWith(
            [$this->manufacturingJob(self::MANUFACTURING_RUNS)],
            new StructureChoice(Example::structureC(), StructureChoiceStatus::BestByTotalCost, new Multiplier(0.9)),
        );

        $groups = $this->compare($planned, [Example::workshopStructureWithoutRig(), Example::structureC(), Example::structureD()]);

        $this->assertCount(1, $groups);
        $group = $groups[0];
        $this->assertSame(['C', StructureChoiceStatus::BestByTotalCost], [$group->chosenStructureId, $group->choiceStatus]);
        $this->assertSame('C', $group->cheapestStructureId);
        $this->assertNull($group->savingIfCheapest);
        $this->assertSame(['A', 'C', 'D'], $this->structureIdsOf($group));
        $this->assertEqualsWithDelta(150_000.0, $group->rows[0]->delta, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(0.0, $group->rows[1]->delta, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(1_500_000.0, $group->rows[2]->delta, self::ISK_TOLERANCE);
    }

    public function testRetainedStructureAndItsStatusAreReadFromThePlanNeverRecomputed(): void
    {
        $planned = $this->plannedWith(
            [$this->manufacturingJob(self::MANUFACTURING_RUNS)],
            new StructureChoice(Example::structureD(), StructureChoiceStatus::Assigned, new Multiplier(0.9)),
        );

        $groups = $this->compare($planned, [Example::workshopStructureWithoutRig(), Example::structureC(), Example::structureD()]);

        $this->assertCount(1, $groups);
        $group = $groups[0];
        $this->assertSame(['D', StructureChoiceStatus::Assigned], [$group->chosenStructureId, $group->choiceStatus]);
        $this->assertEqualsWithDelta(-1_350_000.0, $group->rows[0]->delta, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(-1_500_000.0, $group->rows[1]->delta, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(0.0, $group->rows[2]->delta, self::ISK_TOLERANCE);
        $this->assertSame('C', $group->cheapestStructureId);
        $this->assertEqualsWithDelta(1_500_000.0, $group->savingIfCheapest->amount, self::ISK_TOLERANCE);
    }

    public function testGroupWithoutCandidateStructureHasNoRowNoRetainedStructureAndNoCheapestOne(): void
    {
        $planned = $this->plannedWith(
            [$this->manufacturingJob(self::MANUFACTURING_RUNS)],
            StructureChoice::notConfigured(),
        );

        $groups = $this->compare($planned, [Example::workshopRefinery()]);

        $this->assertCount(1, $groups);
        $group = $groups[0];
        $this->assertSame([], $group->rows);
        $this->assertSame([null, StructureChoiceStatus::NotConfigured, null, null], [$group->chosenStructureId, $group->choiceStatus, $group->cheapestStructureId, $group->savingIfCheapest]);
        $this->assertSame([1, self::MANUFACTURING_RUNS], [$group->jobs, $group->runs]);
    }

    public function testWithoutFavoriteSystemTotalsAreUnknownAndNoStructureIsTheCheapestWhileTheRetainedOneComesFromThePlan(): void
    {
        $planned = $this->plannedWith(
            [$this->manufacturingJob(self::MANUFACTURING_RUNS)],
            new StructureChoice(Example::workshopStructureWithoutRig(), StructureChoiceStatus::BestConfigured, Multiplier::one()),
        );

        $groups = $this->compare($planned, [Example::structureD(), Example::workshopStructureWithoutRig(), Example::structureC()], FavoriteSystems::none());

        $this->assertCount(1, $groups);
        $group = $groups[0];
        $this->assertSame(['A', 'C', 'D'], $this->structureIdsOf($group));
        $this->assertSame(
            [false, false, false],
            array_map(static fn (StructureComparisonRow $row): bool => $row->breakdown->total()->isKnown(), $group->rows),
        );
        $this->assertSame([false, false, false], array_map(static fn (StructureComparisonRow $row): bool => $row->inFavoriteSystem, $group->rows));
        $this->assertEqualsWithDelta(10_000_000.0, $group->rows[0]->breakdown->materials->amount()->amount, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(9_000_000.0, $group->rows[1]->breakdown->materials->amount()->amount, self::ISK_TOLERANCE);
        $this->assertSame([null, null, null], array_map(static fn (StructureComparisonRow $row): mixed => $row->delta, $group->rows));
        $this->assertSame(['A', StructureChoiceStatus::BestConfigured, null, null], [$group->chosenStructureId, $group->choiceStatus, $group->cheapestStructureId, $group->savingIfCheapest]);
    }

    public function testJobOfAProductWithoutRecipeIsAnErrorNamingTheProduct(): void
    {
        $planned = $this->plannedWith(
            [$this->jobOf(self::UNKNOWN_PRODUCT_TYPE_ID, ActivityKind::Manufacturing, 3)],
            StructureChoice::notConfigured(),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf('Job of product %d has no recipe.', self::UNKNOWN_PRODUCT_TYPE_ID));

        $this->compare($planned, [Example::workshopStructureWithoutRig()]);
    }

    /**
     * @param list<ProductionStructure> $structures
     *
     * @return list<JobStructureComparison>
     */
    private function compare(PlannedProduction $planned, array $structures, ?FavoriteSystems $favoriteSystems = null): array
    {
        $comparator = new PlanStructureComparator(
            new InMemoryBlueprintCatalog(Example::recipe(), $this->ferniteCompositeReaction()),
            new InMemoryRigCategories(
                [Example::PRODUCT_TYPE_ID => Example::PRODUCT_CATEGORY],
                [self::FERNITE_COMPOSITE_TYPE_ID => Example::PRODUCT_CATEGORY],
            ),
            Example::comparator(itemVolumes: new InMemoryItemVolumes([
                Example::MATERIAL_TYPE_ID => Example::MATERIAL_CUBIC_METERS,
                Example::PRODUCT_TYPE_ID => Example::PRODUCT_CUBIC_METERS,
                self::FERNITE_COMPOSITE_TYPE_ID => Example::PRODUCT_CUBIC_METERS,
            ])),
        );

        return $comparator->compare(
            $planned,
            $structures,
            $favoriteSystems ?? new FavoriteSystems(Example::WORKSHOP_SYSTEM_ID, Example::WORKSHOP_SYSTEM_ID),
        );
    }

    /**
     * @param list<Job>                      $jobs
     * @param array<string, StructureChoice> $otherChoicesByKey
     */
    private function plannedWith(array $jobs, StructureChoice $manufacturingChoice, array $otherChoicesByKey = []): PlannedProduction
    {
        return new PlannedProduction(new Plan($jobs, [], []), [
            PlannedProduction::choiceKey(Example::PRODUCT_TYPE_ID, ActivityKind::Manufacturing) => $manufacturingChoice,
            ...$otherChoicesByKey,
        ]);
    }

    private function manufacturingChoice(ProductionStructure $structure, StructureChoiceStatus $status): StructureChoice
    {
        return new StructureChoice($structure, $status, Multiplier::one());
    }

    private function manufacturingJob(int $runs): Job
    {
        return $this->jobOf(Example::PRODUCT_TYPE_ID, ActivityKind::Manufacturing, $runs);
    }

    private function jobOf(int $productTypeId, ActivityKind $activity, int $runs): Job
    {
        return new Job(
            $productTypeId,
            $activity,
            new Runs($runs),
            new MaterialEfficiency(10),
            new TimeEfficiency(20),
            [Example::MATERIAL_TYPE_ID => new Quantity($runs * Example::MATERIAL_BASE_QUANTITY_PER_RUN)],
        );
    }

    private function ferniteCompositeReaction(): Recipe
    {
        return new Recipe(
            self::FERNITE_COMPOSITE_TYPE_ID,
            ActivityKind::Reaction,
            new OutputPerRun(1),
            [new RecipeMaterial(Example::MATERIAL_TYPE_ID, new Quantity(Example::MATERIAL_BASE_QUANTITY_PER_RUN))],
            new Runs(300),
            3600,
        );
    }

    /**
     * @return list<string>
     */
    private function structureIdsOf(JobStructureComparison $group): array
    {
        return array_map(static fn (StructureComparisonRow $row): string => $row->structure->id, $group->rows);
    }

    private function totalOf(StructureComparisonRow $row): float
    {
        return $row->breakdown->total()->amount()->amount;
    }
}
