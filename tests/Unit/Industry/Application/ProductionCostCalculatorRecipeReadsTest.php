<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\CostMode;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\InventionRecipe;
use App\Industry\Application\InventionSettings;
use App\Industry\Application\JobCost;
use App\Industry\Application\ProductionCostCalculator;
use App\Industry\Application\ProductionCostRequest;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Application\Double\InMemoryAdjustedPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryInventionCatalog;
use App\Tests\Unit\Industry\Application\Double\InMemoryMarketPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use App\Tests\Unit\Industry\Domain\Double\RecipeReadCountingCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductionCostCalculator::class)]
final class ProductionCostCalculatorRecipeReadsTest extends TestCase
{
    private const string NOMAD_GOLDEN = __DIR__.'/../../../Fixtures/Golden/nomad-1run-me10-no-structure.ravworks.json';
    private const int NOMAD = 28846;
    private const int NOMAD_BOM_TYPE_COUNT = 129;

    private const int T2_PRODUCT = 8001;
    private const int T1_PRODUCT = 8002;
    private const int T1_BLUEPRINT = 8003;
    private const int INTERMEDIATE = 8101;
    private const int FIRST_LEAF = 8201;
    private const int SECOND_LEAF = 8202;
    private const int FIRST_DATACORE = 8301;
    private const int SECOND_DATACORE = 8302;

    private const int FAVORITE_MANUFACTURING_SYSTEM_ID = 30000142;
    private const int BPO_MAX_PRODUCTION_LIMIT = 300;
    private const int INVENTED_BLUEPRINT_COPY_RUNS = 10;

    #[DataProvider('costModeProvider')]
    public function testNomadCostReadsTheRecipeOfEachTypeOfItsBomOnce(CostMode $mode): void
    {
        $catalog = new RecipeReadCountingCatalog(InMemoryBlueprintCatalog::fromSdeFixture());

        self::calculatorOn($catalog, new InMemoryInventionCatalog())->costOf(new ProductionCostRequest(
            productTypeId: self::NOMAD,
            runs: new Runs(1),
            efficiency: self::efficiency(10, 20),
            mode: $mode,
            structures: [],
            favoriteSystems: FavoriteSystems::none(),
        ));

        $this->assertCount(self::NOMAD_BOM_TYPE_COUNT, $catalog->readsByTypeId());
        $this->assertSame(self::oneReadForEachTypeOfTheNomadBom(), $catalog->readsByTypeId());
    }

    #[DataProvider('costModeProvider')]
    public function testInventedProductCostReadsTheT2AndItsT1RecipesOnce(CostMode $mode): void
    {
        $catalog = self::inventedProductCatalog();

        self::inventedProductCalculator($catalog)->costOf(self::inventedProductRequest($mode));

        $this->assertSame(self::oneReadOfEachTypeOfTheInventedProduct(), $catalog->readsByTypeId());
    }

    public function testProductSplitIntoFourJobsByTheBlueprintCopyMaxRunsReadsItsRecipeOnce(): void
    {
        $catalog = self::inventedProductCatalog();

        $productionCost = self::calculatorOn($catalog, new InMemoryInventionCatalog())->costOf(new ProductionCostRequest(
            productTypeId: self::INTERMEDIATE,
            runs: new Runs(32),
            efficiency: self::efficiency(10, 20),
            mode: CostMode::Plan,
            structures: [],
            favoriteSystems: new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null),
            blueprintCopyMaxRuns: [self::INTERMEDIATE => new Runs(10)],
        ));

        $this->assertSame([8.0, 8.0, 8.0, 8.0], array_map(static fn (JobCost $job): float => $job->runs, $productionCost->jobs));
        $this->assertSame([self::INTERMEDIATE => 1, self::FIRST_LEAF => 1, self::SECOND_LEAF => 1], $catalog->readsByTypeId());
    }

    public function testTwoSuccessiveCostsOnTheSameCalculatorEachReadTheirRecipes(): void
    {
        $catalog = self::inventedProductCatalog();
        $calculator = self::inventedProductCalculator($catalog);

        $calculator->costOf(self::inventedProductRequest(CostMode::Plan));
        $readsOfTheFirstCost = $catalog->readsByTypeId();
        $catalog->forgetReads();
        $calculator->costOf(self::inventedProductRequest(CostMode::Plan));

        $this->assertSame(self::oneReadOfEachTypeOfTheInventedProduct(), $readsOfTheFirstCost);
        $this->assertSame(self::oneReadOfEachTypeOfTheInventedProduct(), $catalog->readsByTypeId());
    }

    public static function costModeProvider(): iterable
    {
        yield 'plan' => [CostMode::Plan];
        yield 'marginal cost' => [CostMode::MarginalCost];
    }

    private static function calculatorOn(RecipeReadCountingCatalog $catalog, InMemoryInventionCatalog $inventions): ProductionCostCalculator
    {
        return new ProductionCostCalculator(
            $catalog,
            $inventions,
            new InMemoryMarketPrices([
                self::FIRST_LEAF => 6.0,
                self::SECOND_LEAF => 450.0,
                self::FIRST_DATACORE => 72000.0,
                self::SECOND_DATACORE => 55000.0,
            ]),
            new InMemoryAdjustedPrices([self::FIRST_LEAF => 5.5, self::SECOND_LEAF => 410.0, self::INTERMEDIATE => 3200.0]),
            new InMemorySystemCostIndices([
                self::FAVORITE_MANUFACTURING_SYSTEM_ID => [
                    ActivityKind::Manufacturing->name => 0.045,
                    ActivityKind::Invention->name => 0.065,
                    ActivityKind::Copying->name => 0.035,
                ],
            ]),
            new InMemoryRigCategories(),
        );
    }

    private static function inventedProductCalculator(RecipeReadCountingCatalog $catalog): ProductionCostCalculator
    {
        return self::calculatorOn($catalog, new InMemoryInventionCatalog([
            self::T2_PRODUCT => new InventionRecipe(
                t1BlueprintTypeId: self::T1_BLUEPRINT,
                t1ProductTypeId: self::T1_PRODUCT,
                baseProbability: 0.3,
                baseRuns: new Runs(self::INVENTED_BLUEPRINT_COPY_RUNS),
                datacores: [
                    new RecipeMaterial(self::FIRST_DATACORE, new Quantity(3)),
                    new RecipeMaterial(self::SECOND_DATACORE, new Quantity(1)),
                ],
            ),
        ]));
    }

    private static function inventedProductRequest(CostMode $mode): ProductionCostRequest
    {
        return new ProductionCostRequest(
            productTypeId: self::T2_PRODUCT,
            runs: new Runs(self::INVENTED_BLUEPRINT_COPY_RUNS),
            efficiency: self::efficiency(2, 4),
            mode: $mode,
            structures: [],
            favoriteSystems: new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null),
            invention: new InventionSettings(firstScienceSkillLevel: 5, secondScienceSkillLevel: 4, encryptionSkillLevel: 3),
        );
    }

    private static function inventedProductCatalog(): RecipeReadCountingCatalog
    {
        return new RecipeReadCountingCatalog(new InMemoryBlueprintCatalog(
            self::recipe(self::T2_PRODUCT, 1, [self::FIRST_LEAF => 300, self::INTERMEDIATE => 4]),
            self::recipe(self::T1_PRODUCT, 1, [self::FIRST_LEAF => 450, self::INTERMEDIATE => 2]),
            self::recipe(self::INTERMEDIATE, 3, [self::FIRST_LEAF => 25, self::SECOND_LEAF => 6]),
        ));
    }

    private static function oneReadOfEachTypeOfTheInventedProduct(): array
    {
        return [
            self::T2_PRODUCT => 1,
            self::T1_PRODUCT => 1,
            self::INTERMEDIATE => 1,
            self::FIRST_LEAF => 1,
            self::SECOND_LEAF => 1,
        ];
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
