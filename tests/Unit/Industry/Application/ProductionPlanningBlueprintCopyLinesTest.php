<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\OwnedBlueprintUse;
use App\Industry\Application\ProductionPlanning;
use App\Industry\Application\ProductionPlanRequest;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\Plan;
use App\Industry\Domain\PlanTarget;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductionPlanning::class)]
#[Group('issue-1170')]
final class ProductionPlanningBlueprintCopyLinesTest extends TestCase
{
    private const int TARGET = 7001;
    private const int LEAF = 7201;
    private const int BASE_QUANTITY_PER_RUN = 330;
    private const int MAX_PRODUCTION_LIMIT = 50;
    private const int COPY_RUNS = 5;
    private const int COPY_COUNT = 10;
    private const int MATERIAL_EFFICIENCY = 10;
    private const int TIME_EFFICIENCY = 20;

    public function testTenOwnedCopiesOfFiveRunsPlanTenJobsOfFiveRunsWithTheMaterialsRoundedJobByJob(): void
    {
        $plan = self::planOf(self::copyLines(self::COPY_COUNT, self::COPY_RUNS), [self::TARGET => self::owned(false)]);

        self::assertSame(
            array_fill(0, self::COPY_COUNT, [self::COPY_RUNS, [self::LEAF => 1485]]),
            self::jobsOf($plan, self::TARGET),
        );
    }

    public function testLinesOfDifferentRunsUnderAnOwnedCopyAreBoundByTheSmallestOne(): void
    {
        $plan = self::planOf([self::target(5), self::target(10)], [self::TARGET => self::owned(false)]);

        self::assertSame([5, 5, 5], self::runsOf($plan, self::TARGET));
    }

    public function testSingleLineAboveTheMaxProductionLimitUnderAnOwnedCopyKeepsTheLimitOfTheSde(): void
    {
        $plan = self::planOf([self::target(120)], [self::TARGET => self::owned(false)]);

        self::assertSame([40, 40, 40], self::runsOf($plan, self::TARGET));
    }

    public function testLinesUnderAnOwnedOriginalMergeWithoutAnyRunCap(): void
    {
        $plan = self::planOf(self::copyLines(self::COPY_COUNT, self::COPY_RUNS), [self::TARGET => self::owned(true)]);

        self::assertSame([50], self::runsOf($plan, self::TARGET));
    }

    public function testLinesWithoutOwnedBlueprintEachKeepTheirOwnJob(): void
    {
        $plan = self::planOf(self::copyLines(self::COPY_COUNT, self::COPY_RUNS), []);

        self::assertSame(array_fill(0, self::COPY_COUNT, self::COPY_RUNS), self::runsOf($plan, self::TARGET));
    }

    /**
     * @return list<PlanTarget>
     */
    private static function copyLines(int $count, int $runs): array
    {
        return array_fill(0, $count, self::target($runs));
    }

    /**
     * @param list<PlanTarget>              $targets
     * @param array<int, OwnedBlueprintUse> $ownedBlueprints
     */
    private static function planOf(array $targets, array $ownedBlueprints): Plan
    {
        $planning = new ProductionPlanning(self::catalog(), InMemorySystemCostIndices::none(), new InMemoryRigCategories());

        return $planning->planOf(new ProductionPlanRequest($targets, [], FavoriteSystems::none(), ownedBlueprints: $ownedBlueprints));
    }

    /**
     * @return list<array{int, array<int, int>}> runs, materials by typeId
     */
    private static function jobsOf(Plan $plan, int $productTypeId): array
    {
        return array_map(
            static fn (Job $job): array => [$job->runs->value, array_map(static fn (Quantity $quantity): int => $quantity->value, $job->materials)],
            $plan->jobsFor($productTypeId),
        );
    }

    /**
     * @return list<int>
     */
    private static function runsOf(Plan $plan, int $productTypeId): array
    {
        return array_map(static fn (Job $job): int => $job->runs->value, $plan->jobsFor($productTypeId));
    }

    private static function owned(bool $original): OwnedBlueprintUse
    {
        return new OwnedBlueprintUse(self::efficiency(), $original);
    }

    private static function target(int $runs): PlanTarget
    {
        return new PlanTarget(self::TARGET, new Runs($runs), self::efficiency());
    }

    private static function efficiency(): BlueprintEfficiency
    {
        return new BlueprintEfficiency(new MaterialEfficiency(self::MATERIAL_EFFICIENCY), new TimeEfficiency(self::TIME_EFFICIENCY));
    }

    private static function catalog(): InMemoryBlueprintCatalog
    {
        return new InMemoryBlueprintCatalog(
            new Recipe(
                self::TARGET,
                ActivityKind::Manufacturing,
                new OutputPerRun(1),
                [new RecipeMaterial(self::LEAF, new Quantity(self::BASE_QUANTITY_PER_RUN))],
                new Runs(self::MAX_PRODUCTION_LIMIT),
                3_600,
            ),
        );
    }
}
