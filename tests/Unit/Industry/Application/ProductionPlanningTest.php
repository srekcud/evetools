<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\CostMode;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\LeafCost;
use App\Industry\Application\ProductionCostCalculator;
use App\Industry\Application\ProductionCostRequest;
use App\Industry\Application\ProductionPlanning;
use App\Industry\Application\ProductionPlanRequest;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintCatalog;
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
use App\Industry\Domain\SecurityClass;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Application\Double\InMemoryAdjustedPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryInventionCatalog;
use App\Tests\Unit\Industry\Application\Double\InMemoryMarketPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use App\Tests\Unit\Industry\Domain\Double\RecipeReadCountingCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductionPlanning::class)]
final class ProductionPlanningTest extends TestCase
{
    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int FERNITE_CARBIDE = 16673;

    private const int TARGET = 7001;
    private const int MANUFACTURED_INTERMEDIATE = 7101;
    private const int REACTION_INTERMEDIATE = 7102;
    private const int FIRST_LEAF = 7201;
    private const int SECOND_LEAF = 7202;
    private const int THIRD_LEAF = 7203;
    private const int FOURTH_LEAF = 7204;
    private const int PRODUCT_WITHOUT_RECIPE = 7999;
    private const int FIRST_CYCLE_PRODUCT = 7301;
    private const int SECOND_CYCLE_PRODUCT = 7302;

    private const int TARGET_MAX_PRODUCTION_LIMIT = 50;
    private const int MANUFACTURED_INTERMEDIATE_MAX_PRODUCTION_LIMIT = 7;
    private const int REACTION_INTERMEDIATE_MAX_PRODUCTION_LIMIT = 4;

    public function testTargetOfSeveralUnitsPerRunKeepsItsRunsAndBuysTheMaterialsOfAllOfThem(): void
    {
        $plan = self::planningOn(InMemoryBlueprintCatalog::fromSdeFixture())->planOf(self::requestFor([self::target(self::NITROGEN_FUEL_BLOCK, 25, 10, 20)]));

        $this->assertSame([[ActivityKind::Manufacturing, 25, 10, 20]], self::jobsWithoutMaterials($plan, self::NITROGEN_FUEL_BLOCK));
        $this->assertSame(
            [44 => 90, 3683 => 495, 3689 => 90, 9832 => 203, 9848 => 25, 16272 => 3825, 16273 => 7875, 16275 => 450, 17888 => 10125],
            self::leaves($plan),
        );
    }

    public function testTargetAboveTheMaxProductionLimitOfItsBlueprintIsSplitEvenlyWithMaterialsRecomputedJobByJob(): void
    {
        $plan = self::planningOn(InMemoryBlueprintCatalog::fromSdeFixture())->planOf(self::requestFor([self::target(self::NITROGEN_FUEL_BLOCK, 250, 10, 20)]));

        $this->assertSame(
            [[ActivityKind::Manufacturing, 125, 10, 20], [ActivityKind::Manufacturing, 125, 10, 20]],
            self::jobsWithoutMaterials($plan, self::NITROGEN_FUEL_BLOCK),
        );
        $this->assertSame(
            [44 => 900, 3683 => 4950, 3689 => 900, 9832 => 2026, 9848 => 250, 16272 => 38250, 16273 => 78750, 16275 => 4500, 17888 => 101250],
            self::leaves($plan),
        );
    }

    public function testManufacturedIntermediateAboveItsMaxProductionLimitIsSplitEvenlyWithMaterialsRecomputedJobByJob(): void
    {
        $plan = self::planningOn(self::handwrittenCatalog())->planOf(self::requestFor([self::target(self::TARGET, 12, 10, 20)]));

        $this->assertSame(
            [
                [ActivityKind::Manufacturing, 6, 10, 20, [self::SECOND_LEAF => 222, self::THIRD_LEAF => 71]],
                [ActivityKind::Manufacturing, 6, 10, 20, [self::SECOND_LEAF => 222, self::THIRD_LEAF => 71]],
                [ActivityKind::Manufacturing, 5, 10, 20, [self::SECOND_LEAF => 185, self::THIRD_LEAF => 59]],
                [ActivityKind::Manufacturing, 5, 10, 20, [self::SECOND_LEAF => 185, self::THIRD_LEAF => 59]],
            ],
            self::jobs($plan, self::MANUFACTURED_INTERMEDIATE),
        );
        $this->assertSame(
            [self::FIRST_LEAF => 3564, self::SECOND_LEAF => 814, self::THIRD_LEAF => 386, self::FOURTH_LEAF => 238],
            self::leaves($plan),
        );
    }

    public function testReactionAboveTheMaxProductionLimitOfItsFormulaIsSplitEvenlyWithMaterialsRecomputedJobByJob(): void
    {
        $plan = self::planningOn(self::handwrittenCatalog())->planOf(self::requestFor([self::target(self::TARGET, 12, 10, 20)]));

        $this->assertSame(
            [
                [ActivityKind::Reaction, 4, 0, 0, [self::FOURTH_LEAF => 68, self::THIRD_LEAF => 36]],
                [ActivityKind::Reaction, 4, 0, 0, [self::FOURTH_LEAF => 68, self::THIRD_LEAF => 36]],
                [ActivityKind::Reaction, 3, 0, 0, [self::FOURTH_LEAF => 51, self::THIRD_LEAF => 27]],
                [ActivityKind::Reaction, 3, 0, 0, [self::FOURTH_LEAF => 51, self::THIRD_LEAF => 27]],
            ],
            self::jobs($plan, self::REACTION_INTERMEDIATE),
        );
    }

    public function testTargetAtTheMaxProductionLimitOfItsBlueprintStaysInOneJob(): void
    {
        $plan = self::planningOn(self::handwrittenCatalog())->planOf(self::requestFor([self::target(self::TARGET, self::TARGET_MAX_PRODUCTION_LIMIT, 10, 20)]));

        $this->assertSame(
            [[ActivityKind::Manufacturing, 50, 10, 20, [self::FIRST_LEAF => 14850, self::MANUFACTURED_INTERMEDIATE => 270, self::REACTION_INTERMEDIATE => 1125]]],
            self::jobs($plan, self::TARGET),
        );
    }

    public function testTargetOneRunAboveTheMaxProductionLimitOfItsBlueprintIsSplitIntoTwoJobsTheFirstTakingTheRemainder(): void
    {
        $plan = self::planningOn(self::handwrittenCatalog())->planOf(self::requestFor([self::target(self::TARGET, 51, 10, 20)]));

        $this->assertSame(
            [
                [ActivityKind::Manufacturing, 26, 10, 20, [self::FIRST_LEAF => 7722, self::MANUFACTURED_INTERMEDIATE => 141, self::REACTION_INTERMEDIATE => 585]],
                [ActivityKind::Manufacturing, 25, 10, 20, [self::FIRST_LEAF => 7425, self::MANUFACTURED_INTERMEDIATE => 135, self::REACTION_INTERMEDIATE => 563]],
            ],
            self::jobs($plan, self::TARGET),
        );
        $this->assertSame([7, 7, 7, 7, 7, 7, 7, 7, 6, 6, 6, 6, 6, 6], self::runsOf($plan, self::MANUFACTURED_INTERMEDIATE));
    }

    public function testBothPassesOfThePlanReadTheRecipeOfEachTypeOnce(): void
    {
        $catalog = new RecipeReadCountingCatalog(self::handwrittenCatalog());

        self::planningOn($catalog)->planOf(self::requestFor([self::target(self::TARGET, 12, 10, 20)]));

        $this->assertSame(
            [
                self::TARGET => 1,
                self::MANUFACTURED_INTERMEDIATE => 1,
                self::REACTION_INTERMEDIATE => 1,
                self::FIRST_LEAF => 1,
                self::SECOND_LEAF => 1,
                self::THIRD_LEAF => 1,
                self::FOURTH_LEAF => 1,
            ],
            $catalog->readsByTypeId(),
        );
    }

    public function testIntermediateDemandIsAggregatedOverItsThreeConsumersBeforeRoundingWithoutStock(): void
    {
        $plan = self::planningOn(InMemoryBlueprintCatalog::fromSdeFixture())->planOf(self::requestFor([self::target(self::FERNITE_CARBIDE, 1, 10, 20)]));

        $this->assertArrayHasKey(self::HYDROGEN_FUEL_BLOCK, $plan->intermediates);
        $hydrogenFuelBlock = $plan->intermediates[self::HYDROGEN_FUEL_BLOCK];
        $this->assertSame(
            [15, 0, 40, 25, [1]],
            [
                $hydrogenFuelBlock->demand->value,
                $hydrogenFuelBlock->consumedStock->value,
                $hydrogenFuelBlock->quantityProduced->value,
                $hydrogenFuelBlock->surplus->value,
                array_map(static fn (Job $job): int => $job->runs->value, $hydrogenFuelBlock->jobs),
            ],
        );
        $this->assertSame(
            [44 => 4, 3683 => 20, 3689 => 4, 9832 => 9, 9848 => 1, 16272 => 153, 16273 => 315, 16275 => 18, 16635 => 100, 16636 => 100, 16639 => 100, 16642 => 100, 17889 => 405],
            self::leaves($plan),
        );
    }

    #[Group('issue-69')]
    public function testPartialStockOfAnIntermediateIsConsumedBeforeItsRunsAndOnlyTheRestIsSplitUnderItsMaxProductionLimit(): void
    {
        $plan = self::planningOn(self::handwrittenCatalog())->planOf(self::requestFor(
            [self::target(self::TARGET, 12, 10, 20)],
            startingStock: [self::MANUFACTURED_INTERMEDIATE => new Quantity(20)],
        ));

        $manufacturedIntermediate = $plan->intermediates[self::MANUFACTURED_INTERMEDIATE];
        $this->assertSame([65, 20], [$manufacturedIntermediate->demand->value, $manufacturedIntermediate->consumedStock->value]);
        $this->assertSame(
            [
                [ActivityKind::Manufacturing, 5, 10, 20, [self::SECOND_LEAF => 185, self::THIRD_LEAF => 59]],
                [ActivityKind::Manufacturing, 5, 10, 20, [self::SECOND_LEAF => 185, self::THIRD_LEAF => 59]],
                [ActivityKind::Manufacturing, 5, 10, 20, [self::SECOND_LEAF => 185, self::THIRD_LEAF => 59]],
            ],
            self::jobs($plan, self::MANUFACTURED_INTERMEDIATE),
        );
        $this->assertSame(
            [self::FIRST_LEAF => 3564, self::SECOND_LEAF => 555, self::THIRD_LEAF => 303, self::FOURTH_LEAF => 238],
            self::leaves($plan),
        );
    }

    #[Group('issue-69')]
    public function testStockAboveTheDemandOfAnIntermediateCoversItWithoutAnyJobNorMaterialBelowIt(): void
    {
        $plan = self::planningOn(self::handwrittenCatalog())->planOf(self::requestFor(
            [self::target(self::TARGET, 12, 10, 20)],
            startingStock: [self::MANUFACTURED_INTERMEDIATE => new Quantity(80)],
        ));

        $manufacturedIntermediate = $plan->intermediates[self::MANUFACTURED_INTERMEDIATE];
        $this->assertSame([65, 65, 0], [$manufacturedIntermediate->demand->value, $manufacturedIntermediate->consumedStock->value, $manufacturedIntermediate->surplus->value]);
        $this->assertSame([], self::jobs($plan, self::MANUFACTURED_INTERMEDIATE));
        $this->assertSame(
            [self::FIRST_LEAF => 3564, self::THIRD_LEAF => 126, self::FOURTH_LEAF => 238],
            self::leaves($plan),
        );
    }

    public function testBlacklistedIntermediateIsBoughtAsALeafWithoutItsSubtree(): void
    {
        $plan = self::planningOn(self::handwrittenCatalog())->planOf(self::requestFor(
            [self::target(self::TARGET, 12, 10, 20)],
            blacklist: [self::MANUFACTURED_INTERMEDIATE],
        ));

        $this->assertSame([], self::jobs($plan, self::MANUFACTURED_INTERMEDIATE));
        $this->assertSame(
            [self::MANUFACTURED_INTERMEDIATE => 65, self::FIRST_LEAF => 3564, self::THIRD_LEAF => 126, self::FOURTH_LEAF => 238],
            self::leaves($plan),
        );
    }

    public function testBlacklistedTargetIsStillBuilt(): void
    {
        $plan = self::planningOn(self::handwrittenCatalog())->planOf(self::requestFor(
            [self::target(self::TARGET, 12, 10, 20)],
            blacklist: [self::TARGET],
        ));

        $this->assertSame(
            [[ActivityKind::Manufacturing, 12, 10, 20, [self::FIRST_LEAF => 3564, self::MANUFACTURED_INTERMEDIATE => 65, self::REACTION_INTERMEDIATE => 270]]],
            self::jobs($plan, self::TARGET),
        );
        $this->assertSame(
            [self::FIRST_LEAF => 3564, self::SECOND_LEAF => 814, self::THIRD_LEAF => 386, self::FOURTH_LEAF => 238],
            self::leaves($plan),
        );
    }

    public function testStructureOfTheUserReducesTheMaterialsOfEachJobByTheBonusForItsOwnProduct(): void
    {
        $planning = new ProductionPlanning(
            self::handwrittenCatalog(),
            InMemorySystemCostIndices::none(),
            new InMemoryRigCategories(manufacturing: [self::MANUFACTURED_INTERMEDIATE => 'ammunition']),
        );

        $plan = $planning->planOf(new ProductionPlanRequest(
            [self::target(self::TARGET, 12, 10, 20)],
            [ProductionStructures::raitaru('raitaru-nullsec', SecurityClass::NullSec, null, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.0, 'ammunition'))],
            FavoriteSystems::none(),
        ));

        $this->assertSame(
            [[ActivityKind::Manufacturing, 12, 10, 20, [self::FIRST_LEAF => 3529, self::MANUFACTURED_INTERMEDIATE => 65, self::REACTION_INTERMEDIATE => 268]]],
            self::jobs($plan, self::TARGET),
        );
        $this->assertSame(
            [
                [ActivityKind::Manufacturing, 6, 10, 20, [self::SECOND_LEAF => 210, self::THIRD_LEAF => 67]],
                [ActivityKind::Manufacturing, 6, 10, 20, [self::SECOND_LEAF => 210, self::THIRD_LEAF => 67]],
                [ActivityKind::Manufacturing, 5, 10, 20, [self::SECOND_LEAF => 175, self::THIRD_LEAF => 56]],
                [ActivityKind::Manufacturing, 5, 10, 20, [self::SECOND_LEAF => 175, self::THIRD_LEAF => 56]],
            ],
            self::jobs($plan, self::MANUFACTURED_INTERMEDIATE),
        );
        $this->assertSame(
            [self::FIRST_LEAF => 3529, self::SECOND_LEAF => 770, self::THIRD_LEAF => 372, self::FOURTH_LEAF => 238],
            self::leaves($plan),
        );
    }

    public function testTwoTargetsOfTheSameProductAtDifferentMeEachGetTheirOwnJob(): void
    {
        $plan = self::planningOn(self::handwrittenCatalog())->planOf(self::requestFor([
            self::target(self::TARGET, 12, 10, 20),
            self::target(self::TARGET, 9, 4, 8),
        ]));

        $this->assertSame(
            [
                [ActivityKind::Manufacturing, 12, 10, 20, [self::FIRST_LEAF => 3564, self::MANUFACTURED_INTERMEDIATE => 65, self::REACTION_INTERMEDIATE => 270]],
                [ActivityKind::Manufacturing, 9, 4, 8, [self::FIRST_LEAF => 2852, self::MANUFACTURED_INTERMEDIATE => 52, self::REACTION_INTERMEDIATE => 216]],
            ],
            self::jobs($plan, self::TARGET),
        );
        $this->assertSame(
            [self::FIRST_LEAF => 6416, self::SECOND_LEAF => 1443, self::THIRD_LEAF => 684, self::FOURTH_LEAF => 425],
            self::leaves($plan),
        );
    }

    public function testTargetWithoutRecipeThrowsADomainExceptionNamingIt(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/\b'.self::PRODUCT_WITHOUT_RECIPE.'\b/');

        self::planningOn(self::handwrittenCatalog())->planOf(self::requestFor([
            self::target(self::TARGET, 12, 10, 20),
            self::target(self::PRODUCT_WITHOUT_RECIPE, 3, 0, 0),
        ]));
    }

    public function testCatalogAnsweringTheRecipeOfAnotherProductForATargetThrowsALogicExceptionNamingTheJobProductWithoutRecipe(): void
    {
        $catalog = new class(self::TARGET, self::recipe(self::PRODUCT_WITHOUT_RECIPE, ActivityKind::Manufacturing, 2, 30, [self::FIRST_LEAF => 11])) implements BlueprintCatalog {
            public function __construct(private readonly int $targetTypeId, private readonly Recipe $recipeOfAnotherProduct)
            {
            }

            public function recipeFor(int $productTypeId): ?Recipe
            {
                return $this->targetTypeId === $productTypeId ? $this->recipeOfAnotherProduct : null;
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Job of product '.self::PRODUCT_WITHOUT_RECIPE.' has no recipe.');

        self::planningOn($catalog)->planOf(self::requestFor([self::target(self::TARGET, 4, 10, 20)]));
    }

    public function testCycleInTheProductionGraphThrowsADomainExceptionNamingTheProductNeededToBuildItself(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/\b'.self::SECOND_CYCLE_PRODUCT.'\b/');

        self::planningOn(new InMemoryBlueprintCatalog(
            self::recipe(self::FIRST_CYCLE_PRODUCT, ActivityKind::Manufacturing, 2, 30, [self::SECOND_CYCLE_PRODUCT => 4, self::FIRST_LEAF => 11]),
            self::recipe(self::SECOND_CYCLE_PRODUCT, ActivityKind::Manufacturing, 5, 60, [self::FIRST_CYCLE_PRODUCT => 3]),
        ))->planOf(self::requestFor([self::target(self::FIRST_CYCLE_PRODUCT, 2, 10, 20)]));
    }

    #[DataProvider('severalUnitsPerRunTargetProvider')]
    public function testLeavesAreThoseOfTheProductionCostCalculatorInPlanModeForTheSameTarget(int $productTypeId, int $runs, array $expectedLeaves): void
    {
        $catalog = InMemoryBlueprintCatalog::fromSdeFixture();

        $plan = self::planningOn($catalog)->planOf(self::requestFor([self::target($productTypeId, $runs, 10, 20)]));
        $productionCost = (new ProductionCostCalculator(
            $catalog,
            new InMemoryInventionCatalog(),
            new InMemoryMarketPrices([]),
            new InMemoryAdjustedPrices([]),
            InMemorySystemCostIndices::none(),
            new InMemoryRigCategories(),
        ))->costOf(new ProductionCostRequest(
            productTypeId: $productTypeId,
            runs: new Runs($runs),
            efficiency: new BlueprintEfficiency(new MaterialEfficiency(10), new TimeEfficiency(20)),
            mode: CostMode::Plan,
            structures: [],
            favoriteSystems: FavoriteSystems::none(),
        ));

        $calculatorLeaves = array_map(static fn (LeafCost $leaf): int => (int) $leaf->quantity, $productionCost->leaves);
        ksort($calculatorLeaves);
        $this->assertSame($expectedLeaves, $calculatorLeaves);
        $this->assertSame($calculatorLeaves, self::leaves($plan));
    }

    public static function severalUnitsPerRunTargetProvider(): iterable
    {
        yield 'twenty-five runs of Nitrogen Fuel Block, forty units per run' => [
            self::NITROGEN_FUEL_BLOCK,
            25,
            [44 => 90, 3683 => 495, 3689 => 90, 9832 => 203, 9848 => 25, 16272 => 3825, 16273 => 7875, 16275 => 450, 17888 => 10125],
        ];
        yield 'one run of Fernite Carbide, ten thousand units per run' => [
            self::FERNITE_CARBIDE,
            1,
            [44 => 4, 3683 => 20, 3689 => 4, 9832 => 9, 9848 => 1, 16272 => 153, 16273 => 315, 16275 => 18, 16635 => 100, 16636 => 100, 16639 => 100, 16642 => 100, 17889 => 405],
        ];
    }

    private static function planningOn(BlueprintCatalog $catalog): ProductionPlanning
    {
        return new ProductionPlanning($catalog, InMemorySystemCostIndices::none(), new InMemoryRigCategories());
    }

    private static function requestFor(array $targets, array $blacklist = [], array $startingStock = []): ProductionPlanRequest
    {
        return new ProductionPlanRequest($targets, [], FavoriteSystems::none(), $blacklist, $startingStock);
    }

    private static function target(int $productTypeId, int $runs, int $materialEfficiency, int $timeEfficiency): PlanTarget
    {
        return new PlanTarget(
            $productTypeId,
            new Runs($runs),
            new BlueprintEfficiency(new MaterialEfficiency($materialEfficiency), new TimeEfficiency($timeEfficiency)),
        );
    }

    private static function handwrittenCatalog(): InMemoryBlueprintCatalog
    {
        return new InMemoryBlueprintCatalog(
            self::recipe(self::TARGET, ActivityKind::Manufacturing, 1, self::TARGET_MAX_PRODUCTION_LIMIT, [
                self::FIRST_LEAF => 330,
                self::MANUFACTURED_INTERMEDIATE => 6,
                self::REACTION_INTERMEDIATE => 25,
            ]),
            self::recipe(self::MANUFACTURED_INTERMEDIATE, ActivityKind::Manufacturing, 3, self::MANUFACTURED_INTERMEDIATE_MAX_PRODUCTION_LIMIT, [
                self::SECOND_LEAF => 41,
                self::THIRD_LEAF => 13,
            ]),
            self::recipe(self::REACTION_INTERMEDIATE, ActivityKind::Reaction, 20, self::REACTION_INTERMEDIATE_MAX_PRODUCTION_LIMIT, [
                self::FOURTH_LEAF => 17,
                self::THIRD_LEAF => 9,
            ]),
        );
    }

    private static function recipe(int $productTypeId, ActivityKind $activity, int $outputPerRun, int $maxProductionLimit, array $baseQuantitiesPerRun): Recipe
    {
        $materials = [];
        foreach ($baseQuantitiesPerRun as $materialTypeId => $baseQuantityPerRun) {
            $materials[] = new RecipeMaterial($materialTypeId, new Quantity($baseQuantityPerRun));
        }

        return new Recipe($productTypeId, $activity, new OutputPerRun($outputPerRun), $materials, new Runs($maxProductionLimit), 3_600);
    }

    private static function jobs(Plan $plan, int $productTypeId): array
    {
        return array_map(
            static fn (Job $job): array => [
                $job->activity,
                $job->runs->value,
                $job->materialEfficiency->value,
                $job->timeEfficiency->value,
                array_map(static fn (Quantity $quantity): int => $quantity->value, $job->materials),
            ],
            $plan->jobsFor($productTypeId),
        );
    }

    private static function jobsWithoutMaterials(Plan $plan, int $productTypeId): array
    {
        return array_map(static fn (array $job): array => \array_slice($job, 0, 4), self::jobs($plan, $productTypeId));
    }

    private static function runsOf(Plan $plan, int $productTypeId): array
    {
        return array_map(static fn (Job $job): int => $job->runs->value, $plan->jobsFor($productTypeId));
    }

    private static function leaves(Plan $plan): array
    {
        $leaves = array_map(static fn (Quantity $quantity): int => $quantity->value, $plan->leaves);
        ksort($leaves);

        return $leaves;
    }
}
