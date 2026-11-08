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
#[Group('issue-411')]
final class ProductionPlanningOwnedBlueprintTest extends TestCase
{
    private const int TARGET = 7001;
    private const int INTERMEDIATE = 7101;
    private const int REACTION_TARGET = 7103;
    private const int LEAF = 7201;
    private const int SECOND_LEAF = 7202;
    private const int REACTION_LEAF = 7203;
    private const int UNRELATED_PRODUCT = 7999;

    private const int TARGET_MAX_PRODUCTION_LIMIT = 5;
    private const int INTERMEDIATE_MAX_PRODUCTION_LIMIT = 4;
    private const int REACTION_MAX_PRODUCTION_LIMIT = 3;
    private const int INTERMEDIATE_UNITS_PER_TARGET_RUN = 30;

    public function testTargetUsesTheEfficiencyOfTheOwnedBlueprintInsteadOfTheRequestedOne(): void
    {
        $plan = self::planOf([self::target(self::TARGET, 4, 0, 0)], [self::TARGET => self::owned(8, 16, false)]);

        self::assertSame([[4, 8, 16]], self::jobsOf($plan, self::TARGET));
    }

    public function testIntermediateUsesTheEfficiencyOfTheOwnedBlueprintInsteadOfTheDefaultOne(): void
    {
        $plan = self::planOf([self::target(self::TARGET, 1, 0, 0)], [self::INTERMEDIATE => self::owned(5, 10, false)]);

        foreach (self::jobsOf($plan, self::INTERMEDIATE) as [$runs, $materialEfficiency, $timeEfficiency]) {
            self::assertSame([5, 10], [$materialEfficiency, $timeEfficiency]);
        }
        self::assertNotSame([], self::jobsOf($plan, self::INTERMEDIATE));
    }

    public function testOwnedOriginalRemovesTheRunCapOfTheTarget(): void
    {
        $plan = self::planOf([self::target(self::TARGET, 12, 0, 0)], [self::TARGET => self::owned(9, 18, true)]);

        self::assertSame([[12, 9, 18]], self::jobsOf($plan, self::TARGET));
    }

    public function testOwnedCopyKeepsTheRunCapOfTheTarget(): void
    {
        $plan = self::planOf([self::target(self::TARGET, 12, 0, 0)], [self::TARGET => self::owned(8, 16, false)]);

        self::assertSame([[4, 8, 16], [4, 8, 16], [4, 8, 16]], self::jobsOf($plan, self::TARGET));
    }

    public function testOwnedOriginalRemovesTheRunCapOfAnIntermediate(): void
    {
        $withoutOwnedBlueprint = self::planOf([self::target(self::TARGET, 1, 0, 0)], []);
        $withOwnedOriginal = self::planOf([self::target(self::TARGET, 1, 0, 0)], [self::INTERMEDIATE => self::owned(10, 20, true)]);

        self::assertCount(8, self::jobsOf($withoutOwnedBlueprint, self::INTERMEDIATE));
        self::assertSame([[self::INTERMEDIATE_UNITS_PER_TARGET_RUN, 10, 20]], self::jobsOf($withOwnedOriginal, self::INTERMEDIATE));
    }

    public function testTargetsOfTheSameProductMergeOnceTheOwnedEfficiencyReplacesTheRequestedOnes(): void
    {
        $plan = self::planOf(
            [self::target(self::TARGET, 6, 0, 0), self::target(self::TARGET, 6, 5, 10)],
            [self::TARGET => self::owned(8, 16, false)],
        );

        self::assertSame([[4, 8, 16], [4, 8, 16], [4, 8, 16]], self::jobsOf($plan, self::TARGET));
    }

    public function testReactionKeepsZeroEfficiencyButItsOwnedOriginalRemovesTheRunCap(): void
    {
        $plan = self::planOf([self::target(self::REACTION_TARGET, 7, 0, 0)], [self::REACTION_TARGET => self::owned(3, 6, true)]);

        self::assertSame([[7, 0, 0]], self::jobsOf($plan, self::REACTION_TARGET));
    }

    public function testProductWithoutOwnedBlueprintKeepsTheRequestedEfficiencyAndTheRunCap(): void
    {
        $plan = self::planOf([self::target(self::TARGET, 12, 3, 6)], [self::UNRELATED_PRODUCT => self::owned(10, 20, true)]);

        self::assertSame([[4, 3, 6], [4, 3, 6], [4, 3, 6]], self::jobsOf($plan, self::TARGET));
        foreach (self::jobsOf($plan, self::INTERMEDIATE) as [$runs, $materialEfficiency, $timeEfficiency]) {
            self::assertSame([10, 20], [$materialEfficiency, $timeEfficiency]);
        }
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
     * @return list<array{int, int, int}> runs, material efficiency, time efficiency
     */
    private static function jobsOf(Plan $plan, int $productTypeId): array
    {
        return array_map(
            static fn (Job $job): array => [$job->runs->value, $job->materialEfficiency->value, $job->timeEfficiency->value],
            $plan->jobsFor($productTypeId),
        );
    }

    private static function owned(int $materialEfficiency, int $timeEfficiency, bool $original): OwnedBlueprintUse
    {
        return new OwnedBlueprintUse(new BlueprintEfficiency(new MaterialEfficiency($materialEfficiency), new TimeEfficiency($timeEfficiency)), $original);
    }

    private static function target(int $productTypeId, int $runs, int $materialEfficiency, int $timeEfficiency): PlanTarget
    {
        return new PlanTarget(
            $productTypeId,
            new Runs($runs),
            new BlueprintEfficiency(new MaterialEfficiency($materialEfficiency), new TimeEfficiency($timeEfficiency)),
        );
    }

    private static function catalog(): InMemoryBlueprintCatalog
    {
        return new InMemoryBlueprintCatalog(
            self::recipe(self::TARGET, ActivityKind::Manufacturing, self::TARGET_MAX_PRODUCTION_LIMIT, [self::LEAF => 100, self::INTERMEDIATE => self::INTERMEDIATE_UNITS_PER_TARGET_RUN]),
            self::recipe(self::INTERMEDIATE, ActivityKind::Manufacturing, self::INTERMEDIATE_MAX_PRODUCTION_LIMIT, [self::SECOND_LEAF => 2]),
            self::recipe(self::REACTION_TARGET, ActivityKind::Reaction, self::REACTION_MAX_PRODUCTION_LIMIT, [self::REACTION_LEAF => 10]),
        );
    }

    /**
     * @param array<int, int> $baseQuantitiesPerRun
     */
    private static function recipe(int $productTypeId, ActivityKind $activity, int $maxProductionLimit, array $baseQuantitiesPerRun): Recipe
    {
        $materials = [];
        foreach ($baseQuantitiesPerRun as $materialTypeId => $baseQuantityPerRun) {
            $materials[] = new RecipeMaterial($materialTypeId, new Quantity($baseQuantityPerRun));
        }

        return new Recipe($productTypeId, $activity, new OutputPerRun(1), $materials, new Runs($maxProductionLimit), 3_600);
    }
}
