<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\OwnedBlueprintUse;
use App\Industry\Application\ProductionPlanning;
use App\Industry\Application\ProductionPlanRequest;
use App\Industry\Application\ShipProducts;
use App\Industry\Application\WorkTypeClassifier;
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
#[Group('issue-1348')]
final class ProductionPlanningOwnedCopyRunsTest extends TestCase
{
    private const int HOBGOBLIN = 2_454;
    private const int WASP = 2_470;
    private const int HAMMERHEAD = 2_206;
    private const int FERNITE_CARBIDE = 16_673;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int HYDROGEN = 16_672;

    private const int HOBGOBLIN_MAX_PRODUCTION_LIMIT = 5;
    private const int WASP_MAX_PRODUCTION_LIMIT = 6;
    private const int HAMMERHEAD_MAX_PRODUCTION_LIMIT = 4;
    private const int FERNITE_CARBIDE_MAX_PRODUCTION_LIMIT = 3;

    public function testSmallestOwnedCopyTargetRunsCapTheJobsOfEachProductSeparately(): void
    {
        $plan = $this->planOf(
            [
                self::target(self::HOBGOBLIN, 2),
                self::target(self::HOBGOBLIN, 6),
                self::target(self::WASP, 3),
                self::target(self::WASP, 9),
            ],
            [self::HOBGOBLIN => self::ownedCopy(), self::WASP => self::ownedCopy()],
        );

        self::assertSame([2, 2, 2, 2], self::jobRunsOf($plan, self::HOBGOBLIN));
        self::assertSame([3, 3, 3, 3], self::jobRunsOf($plan, self::WASP));
    }

    public function testLargerOwnedCopyTargetRunsComingFirstDoNotRaiseTheCap(): void
    {
        $plan = $this->planOf(
            [self::target(self::HOBGOBLIN, 6), self::target(self::HOBGOBLIN, 2)],
            [self::HOBGOBLIN => self::ownedCopy()],
        );

        self::assertSame([2, 2, 2, 2], self::jobRunsOf($plan, self::HOBGOBLIN));
    }

    public function testTargetWithoutOwnedBlueprintListedBeforeAnOwnedCopyDoesNotStopTheCapOfTheCopy(): void
    {
        $plan = $this->planOf(
            [self::target(self::HAMMERHEAD, 7), self::target(self::HOBGOBLIN, 2), self::target(self::HOBGOBLIN, 6)],
            [self::HOBGOBLIN => self::ownedCopy()],
        );

        self::assertSame([2, 2, 2, 2], self::jobRunsOf($plan, self::HOBGOBLIN));
        self::assertSame([4, 3], self::jobRunsOf($plan, self::HAMMERHEAD));
    }

    public function testOwnedOriginalListedBeforeAnOwnedCopyDoesNotStopTheCapOfTheCopy(): void
    {
        $plan = $this->planOf(
            [self::target(self::HAMMERHEAD, 7), self::target(self::HOBGOBLIN, 2), self::target(self::HOBGOBLIN, 6)],
            [self::HAMMERHEAD => self::ownedOriginal(), self::HOBGOBLIN => self::ownedCopy()],
        );

        self::assertSame([7], self::jobRunsOf($plan, self::HAMMERHEAD));
        self::assertSame([2, 2, 2, 2], self::jobRunsOf($plan, self::HOBGOBLIN));
    }

    public function testOwnedCopyOfAReactionIsNotCappedByTheSmallestRequestedRunsOfItsTargets(): void
    {
        $plan = $this->planOf(
            [self::target(self::FERNITE_CARBIDE, 2), self::target(self::FERNITE_CARBIDE, 6)],
            [self::FERNITE_CARBIDE => self::ownedCopy()],
        );

        self::assertSame([2, 3, 3], self::jobRunsOf($plan, self::FERNITE_CARBIDE));
    }

    public function testWorkTypeClassifierIsNotConsultedWhenNoWorkStructureIsRequested(): void
    {
        $shipProducts = new class implements ShipProducts {
            public int $lookups = 0;

            public function isShip(int $typeId): bool
            {
                ++$this->lookups;

                return false;
            }
        };
        $planning = new ProductionPlanning(
            self::catalog(),
            InMemorySystemCostIndices::none(),
            new InMemoryRigCategories(),
            null,
            new WorkTypeClassifier($shipProducts),
        );

        $planned = $planning->planWithStructuresOf(new ProductionPlanRequest([self::target(self::WASP, 3)], [], FavoriteSystems::none()));

        self::assertSame(0, $shipProducts->lookups);
        self::assertSame([3], self::jobRunsOf($planned->plan, self::WASP));
    }

    /**
     * @param list<PlanTarget>              $targets
     * @param array<int, OwnedBlueprintUse> $ownedBlueprints
     */
    private function planOf(array $targets, array $ownedBlueprints): Plan
    {
        $planning = new ProductionPlanning(self::catalog(), InMemorySystemCostIndices::none(), new InMemoryRigCategories());

        return $planning->planOf(new ProductionPlanRequest($targets, [], FavoriteSystems::none(), ownedBlueprints: $ownedBlueprints));
    }

    /**
     * @return list<int>
     */
    private static function jobRunsOf(Plan $plan, int $productTypeId): array
    {
        return array_map(static fn (Job $job): int => $job->runs->value, $plan->jobsFor($productTypeId));
    }

    private static function ownedCopy(): OwnedBlueprintUse
    {
        return new OwnedBlueprintUse(new BlueprintEfficiency(new MaterialEfficiency(8), new TimeEfficiency(16)), false);
    }

    private static function ownedOriginal(): OwnedBlueprintUse
    {
        return new OwnedBlueprintUse(new BlueprintEfficiency(new MaterialEfficiency(10), new TimeEfficiency(20)), true);
    }

    private static function target(int $productTypeId, int $runs): PlanTarget
    {
        return new PlanTarget($productTypeId, new Runs($runs), new BlueprintEfficiency(new MaterialEfficiency(0), new TimeEfficiency(0)));
    }

    private static function catalog(): InMemoryBlueprintCatalog
    {
        return new InMemoryBlueprintCatalog(
            self::recipe(self::HOBGOBLIN, ActivityKind::Manufacturing, self::HOBGOBLIN_MAX_PRODUCTION_LIMIT, [self::TRITANIUM => 100]),
            self::recipe(self::WASP, ActivityKind::Manufacturing, self::WASP_MAX_PRODUCTION_LIMIT, [self::PYERITE => 60, self::HAMMERHEAD => 2]),
            self::recipe(self::HAMMERHEAD, ActivityKind::Manufacturing, self::HAMMERHEAD_MAX_PRODUCTION_LIMIT, [self::MEXALLON => 40]),
            self::recipe(self::FERNITE_CARBIDE, ActivityKind::Reaction, self::FERNITE_CARBIDE_MAX_PRODUCTION_LIMIT, [self::HYDROGEN => 20]),
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
