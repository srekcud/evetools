<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\PlanRequest;
use App\Industry\Domain\PlanTarget;
use App\Industry\Domain\ProductionPlanner;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Domain\Double\ActivityMaterialModifiers;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use App\Tests\Unit\Industry\Domain\Double\RecipeReadCountingCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductionPlanner::class)]
final class ProductionPlannerRecipeReadsTest extends TestCase
{
    private const string NOMAD_GOLDEN = __DIR__.'/../../../Fixtures/Golden/nomad-1run-me10-no-structure.ravworks.json';
    private const int NOMAD = 28846;
    private const int NOMAD_BOM_TYPE_COUNT = 129;

    private const int TARGET = 7001;
    private const int SECOND_TARGET = 7002;
    private const int FIRST_INTERMEDIATE = 7101;
    private const int SECOND_INTERMEDIATE = 7102;
    private const int THIRD_INTERMEDIATE = 7103;
    private const int FIRST_LEAF = 7201;
    private const int SECOND_LEAF = 7202;
    private const int BLACKLISTED_TYPE = 7301;

    private const int BPO_MAX_PRODUCTION_LIMIT = 300;

    public function testNomadPlanReadsTheRecipeOfEachTypeOfItsBomOnce(): void
    {
        $catalog = new RecipeReadCountingCatalog(InMemoryBlueprintCatalog::fromSdeFixture());

        (new ProductionPlanner($catalog))->plan(self::nomadRequest());

        $this->assertCount(self::NOMAD_BOM_TYPE_COUNT, $catalog->readsByTypeId());
        $this->assertSame(self::oneReadForEachTypeOfTheNomadBom(), $catalog->readsByTypeId());
    }

    public function testNomadMarginalCostReadsTheRecipeOfEachTypeOfItsBomOnce(): void
    {
        $catalog = new RecipeReadCountingCatalog(InMemoryBlueprintCatalog::fromSdeFixture());

        (new ProductionPlanner($catalog))->marginalCost(self::nomadRequest());

        $this->assertCount(self::NOMAD_BOM_TYPE_COUNT, $catalog->readsByTypeId());
        $this->assertSame(self::oneReadForEachTypeOfTheNomadBom(), $catalog->readsByTypeId());
    }

    public function testIntermediateConsumedAtTwoDepthsIsReadOnce(): void
    {
        $catalog = self::countingCatalog(
            self::recipe(self::TARGET, 1, [self::FIRST_INTERMEDIATE => 3, self::SECOND_INTERMEDIATE => 11]),
            self::recipe(self::FIRST_INTERMEDIATE, 2, [self::SECOND_INTERMEDIATE => 4]),
            self::recipe(self::SECOND_INTERMEDIATE, 5, [self::FIRST_LEAF => 9]),
        );

        (new ProductionPlanner($catalog))->plan(self::requestFor([self::target(self::TARGET, 2)]));

        $this->assertSame([
            self::TARGET => 1,
            self::FIRST_INTERMEDIATE => 1,
            self::SECOND_INTERMEDIATE => 1,
            self::FIRST_LEAF => 1,
        ], $catalog->readsByTypeId());
    }

    public function testLeafWithoutRecipeConsumedByThreeIntermediatesIsReadOnceInPlanMode(): void
    {
        $catalog = self::catalogWithALeafUnderThreeIntermediates();

        (new ProductionPlanner($catalog))->plan(self::requestFor([self::target(self::TARGET, 3)]));

        $this->assertSame(self::oneReadForTheTargetTheThreeIntermediatesAndTheLeaf(), $catalog->readsByTypeId());
    }

    public function testLeafWithoutRecipeConsumedByThreeIntermediatesIsReadOnceInMarginalCostMode(): void
    {
        $catalog = self::catalogWithALeafUnderThreeIntermediates();

        (new ProductionPlanner($catalog))->marginalCost(self::requestFor([self::target(self::TARGET, 3)]));

        $this->assertSame(self::oneReadForTheTargetTheThreeIntermediatesAndTheLeaf(), $catalog->readsByTypeId());
    }

    public function testTargetThatIsAlsoAMaterialOfAnotherTargetIsReadOnce(): void
    {
        $catalog = self::countingCatalog(
            self::recipe(self::TARGET, 1, [self::SECOND_TARGET => 2, self::FIRST_LEAF => 5]),
            self::recipe(self::SECOND_TARGET, 3, [self::SECOND_LEAF => 8]),
        );

        (new ProductionPlanner($catalog))->plan(self::requestFor([
            self::target(self::TARGET, 4),
            self::target(self::SECOND_TARGET, 6),
        ]));

        $this->assertSame([
            self::TARGET => 1,
            self::SECOND_TARGET => 1,
            self::FIRST_LEAF => 1,
            self::SECOND_LEAF => 1,
        ], $catalog->readsByTypeId());
    }

    public function testSameTargetTwiceIsReadOnce(): void
    {
        $catalog = self::countingCatalog(self::recipe(self::TARGET, 1, [self::FIRST_LEAF => 12]));

        (new ProductionPlanner($catalog))->plan(self::requestFor([
            self::target(self::TARGET, 2),
            self::target(self::TARGET, 7),
        ]));

        $this->assertSame([self::TARGET => 1, self::FIRST_LEAF => 1], $catalog->readsByTypeId());
    }

    public function testTargetNeededByItsOwnIntermediateIsReadOnceBeforeTheCycleIsRefused(): void
    {
        $catalog = self::countingCatalog(
            self::recipe(self::TARGET, 1, [self::FIRST_INTERMEDIATE => 2]),
            self::recipe(self::FIRST_INTERMEDIATE, 4, [self::TARGET => 1]),
        );

        $refusal = null;
        try {
            (new ProductionPlanner($catalog))->plan(self::requestFor([self::target(self::TARGET, 1)]));
        } catch (\DomainException $exception) {
            $refusal = $exception;
        }

        $this->assertNotNull($refusal);
        $this->assertSame('Product 7101 is needed to build itself: the production graph has a cycle.', $refusal->getMessage());
        $this->assertSame([self::TARGET => 1, self::FIRST_INTERMEDIATE => 1], $catalog->readsByTypeId());
    }

    public function testBlacklistedMaterialOfTwoIntermediatesIsNeverRead(): void
    {
        $catalog = self::countingCatalog(
            self::recipe(self::TARGET, 1, [self::FIRST_INTERMEDIATE => 3, self::SECOND_INTERMEDIATE => 4]),
            self::recipe(self::FIRST_INTERMEDIATE, 2, [self::BLACKLISTED_TYPE => 6, self::FIRST_LEAF => 2]),
            self::recipe(self::SECOND_INTERMEDIATE, 5, [self::BLACKLISTED_TYPE => 10, self::FIRST_LEAF => 3]),
            self::recipe(self::BLACKLISTED_TYPE, 1, [self::SECOND_LEAF => 15]),
        );

        (new ProductionPlanner($catalog))->plan(self::requestFor([self::target(self::TARGET, 2)], blacklist: [self::BLACKLISTED_TYPE]));

        $this->assertSame([
            self::TARGET => 1,
            self::FIRST_INTERMEDIATE => 1,
            self::SECOND_INTERMEDIATE => 1,
            self::FIRST_LEAF => 1,
        ], $catalog->readsByTypeId());
    }

    public function testLeafUnderAnIntermediateCoveredByStartingStockIsStillReadOnce(): void
    {
        $catalog = self::countingCatalog(
            self::recipe(self::TARGET, 1, [self::FIRST_INTERMEDIATE => 4, self::SECOND_LEAF => 7]),
            self::recipe(self::FIRST_INTERMEDIATE, 2, [self::FIRST_LEAF => 9]),
        );

        $plan = (new ProductionPlanner($catalog))->plan(self::requestFor(
            [self::target(self::TARGET, 1)],
            startingStock: [self::FIRST_INTERMEDIATE => new Quantity(4)],
        ));

        $this->assertSame([], $plan->jobsFor(self::FIRST_INTERMEDIATE));
        $this->assertArrayNotHasKey(self::FIRST_LEAF, $plan->leaves);
        $this->assertSame([
            self::TARGET => 1,
            self::FIRST_INTERMEDIATE => 1,
            self::FIRST_LEAF => 1,
            self::SECOND_LEAF => 1,
        ], $catalog->readsByTypeId());
    }

    public function testTwoSuccessivePlansOnTheSamePlannerEachReadTheirRecipes(): void
    {
        $catalog = self::countingCatalog(
            self::recipe(self::TARGET, 1, [self::FIRST_INTERMEDIATE => 3, self::FIRST_LEAF => 14]),
            self::recipe(self::FIRST_INTERMEDIATE, 2, [self::FIRST_LEAF => 4]),
        );
        $planner = new ProductionPlanner($catalog);
        $oneReadOfEachType = [self::TARGET => 1, self::FIRST_INTERMEDIATE => 1, self::FIRST_LEAF => 1];

        $planner->plan(self::requestFor([self::target(self::TARGET, 2)]));
        $readsOfTheFirstPlan = $catalog->readsByTypeId();
        $catalog->forgetReads();
        $planner->plan(self::requestFor([self::target(self::TARGET, 2)]));

        $this->assertSame($oneReadOfEachType, $readsOfTheFirstPlan);
        $this->assertSame($oneReadOfEachType, $catalog->readsByTypeId());
    }

    private static function nomadRequest(): PlanRequest
    {
        return new PlanRequest(
            targets: [new PlanTarget(self::NOMAD, new Runs(1), self::efficiency(10, 20))],
            materialModifiers: ActivityMaterialModifiers::none(),
        );
    }

    private static function oneReadForEachTypeOfTheNomadBom(): array
    {
        $golden = json_decode((string) file_get_contents(self::NOMAD_GOLDEN), true, 512, \JSON_THROW_ON_ERROR);
        $typeIds = [self::NOMAD];
        foreach ($golden['expected']['intermediates'] as $intermediate) {
            $typeIds[] = $intermediate['typeId'];
        }
        foreach ($golden['expected']['leaves'] as $leaf) {
            $typeIds[] = $leaf['typeId'];
        }
        $reads = array_fill_keys($typeIds, 1);
        ksort($reads);

        return $reads;
    }

    private static function catalogWithALeafUnderThreeIntermediates(): RecipeReadCountingCatalog
    {
        return self::countingCatalog(
            self::recipe(self::TARGET, 1, [self::FIRST_INTERMEDIATE => 2, self::SECOND_INTERMEDIATE => 3, self::THIRD_INTERMEDIATE => 5]),
            self::recipe(self::FIRST_INTERMEDIATE, 2, [self::FIRST_LEAF => 7]),
            self::recipe(self::SECOND_INTERMEDIATE, 4, [self::FIRST_LEAF => 13]),
            self::recipe(self::THIRD_INTERMEDIATE, 6, [self::FIRST_LEAF => 17]),
        );
    }

    private static function oneReadForTheTargetTheThreeIntermediatesAndTheLeaf(): array
    {
        return [
            self::TARGET => 1,
            self::FIRST_INTERMEDIATE => 1,
            self::SECOND_INTERMEDIATE => 1,
            self::THIRD_INTERMEDIATE => 1,
            self::FIRST_LEAF => 1,
        ];
    }

    private static function countingCatalog(Recipe ...$recipes): RecipeReadCountingCatalog
    {
        return new RecipeReadCountingCatalog(new InMemoryBlueprintCatalog(...$recipes));
    }

    private static function requestFor(array $targets, array $startingStock = [], array $blacklist = []): PlanRequest
    {
        return new PlanRequest(
            targets: $targets,
            materialModifiers: ActivityMaterialModifiers::none(),
            startingStock: $startingStock,
            blacklist: $blacklist,
        );
    }

    private static function target(int $productTypeId, int $runs): PlanTarget
    {
        return new PlanTarget($productTypeId, new Runs($runs), self::efficiency(0, 0));
    }

    private static function recipe(int $productTypeId, int $outputPerRun, array $baseQuantitiesPerRun): Recipe
    {
        $materials = [];
        foreach ($baseQuantitiesPerRun as $materialTypeId => $baseQuantityPerRun) {
            $materials[] = new RecipeMaterial($materialTypeId, new Quantity($baseQuantityPerRun));
        }

        return new Recipe(
            productTypeId: $productTypeId,
            activity: ActivityKind::Manufacturing,
            outputPerRun: new Quantity($outputPerRun),
            materials: $materials,
            maxProductionLimit: new Runs(self::BPO_MAX_PRODUCTION_LIMIT),
            baseTimeSeconds: 600,
        );
    }

    private static function efficiency(int $materialEfficiency, int $timeEfficiency): BlueprintEfficiency
    {
        return new BlueprintEfficiency(new MaterialEfficiency($materialEfficiency), new TimeEfficiency($timeEfficiency));
    }
}
