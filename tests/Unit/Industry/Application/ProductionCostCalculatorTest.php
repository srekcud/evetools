<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\CostMode;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\InventionCost;
use App\Industry\Application\InventionRecipe;
use App\Industry\Application\InventionSettings;
use App\Industry\Application\JobCost;
use App\Industry\Application\LeafCost;
use App\Industry\Application\ProductionCost;
use App\Industry\Application\ProductionCostCalculator;
use App\Industry\Application\ProductionCostRequest;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureChoiceStatus;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintCatalog;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Cost;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Industry\Domain\SecurityClass;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Application\Double\InMemoryAdjustedPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryInventionCatalog;
use App\Tests\Unit\Industry\Application\Double\InMemoryMarketPrices;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Production cost of a product (first caller to migrate: ProfitMarginService, spec §7 rank 1). The Application composes:
 * - the plan, in marginal cost mode (D4b, unit cost of Profit Margins) or plan mode (R3, R12);
 * - the structure of each job (StructureSelector, D3) and its material multiplier (R4);
 * - the cost of the leaves with the market prices (port MarketPrices): a missing price is an unknown cost (R10);
 * - the install cost of each job (R5, R6): EIV from the ESI adjusted prices (port AdjustedPrices), cost index of the
 *   system of the chosen structure, otherwise of the favorite system of the activity (port SystemCostIndices),
 *   cost role bonus and facility tax of the structure; without structure, an NPC station (tax 0.25 %, no bonus);
 * - for a T2, the invention (R8): expected attempts × (datacores + decryptor + invention install + install of the
 *   1-run T1 copy consumed per attempt).
 *
 * Hail L (§4.2, golden hail-l-10runs-me2-raitaru-nullsec.json): 10 runs ME 2 TE 4, Raitaru nullsec with M-Set
 * Ammunition ME II (2.4 %), cost role bonus 0.97, facility tax 1 %, manufacturing cost index 0.05; R.A.M. ME 10 in the
 * same Raitaru, no rig category (×0.99); Fernite Carbide and Fullerides bought. Prices and indices are synthetic.
 * Every expected amount was recomputed by a throwaway script before being written here.
 */
#[CoversClass(ProductionCostCalculator::class)]
#[CoversClass(ProductionCost::class)]
#[CoversClass(LeafCost::class)]
#[CoversClass(JobCost::class)]
#[CoversClass(InventionCost::class)]
final class ProductionCostCalculatorTest extends TestCase
{
    private const int HAIL_L = 12779;
    private const int RAM_AMMUNITION_TECH = 11476;
    private const int FERNITE_CARBIDE = 16673;
    private const int FULLERIDES = 16679;
    private const int MORPHITE = 11399;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int ISOGEN = 37;
    private const int NOCXIUM = 38;

    private const int RAITARU_SYSTEM_ID = 30002187;
    private const int FAVORITE_MANUFACTURING_SYSTEM_ID = 30000142;

    private const float RAITARU_FACILITY_TAX_RATE = 0.01;

    /** Synthetic T2 case: quantities are not those of the SDE. */
    private const int HOBGOBLIN_I = 2454;
    private const int HOBGOBLIN_I_BLUEPRINT = 2455;
    private const int HOBGOBLIN_II = 2456;
    private const int FIRST_DATACORE = 20424;
    private const int SECOND_DATACORE = 20418;
    private const float HOBGOBLIN_II_BASE_PROBABILITY = 0.34;

    private const float ISK_TOLERANCE = 1.0;
    private const float FLOAT_TOLERANCE = 1e-6;

    /**
     * Market unit prices (synthetic).
     */
    private const array HAIL_L_MARKET_PRICES = [
        self::FERNITE_CARBIDE => 60.0,
        self::FULLERIDES => 150.0,
        self::MORPHITE => 9000.0,
        self::TRITANIUM => 4.0,
        self::PYERITE => 8.0,
        self::MEXALLON => 70.0,
        self::ISOGEN => 130.0,
        self::NOCXIUM => 500.0,
    ];

    /**
     * ESI adjusted prices (synthetic), every material of the Hail L and R.A.M. jobs.
     */
    private const array HAIL_L_ADJUSTED_PRICES = [
        self::FERNITE_CARBIDE => 50.0,
        self::FULLERIDES => 120.0,
        self::MORPHITE => 8000.0,
        self::RAM_AMMUNITION_TECH => 1000.0,
        self::TRITANIUM => 3.0,
        self::PYERITE => 7.0,
        self::MEXALLON => 60.0,
        self::ISOGEN => 100.0,
        self::NOCXIUM => 400.0,
    ];

    public function testHailLUnitCostInMarginalCostModeAddsLeavesAndProRataInstallCosts(): void
    {
        $productionCost = self::hailLCalculator()->costOf(self::hailLRequest(CostMode::MarginalCost));

        // Leaves: quantities of ProductionPlannerMarginalCostTest × market prices.
        // 27 639.0576 × 60 + 11 055.62304 × 150 + 138.195288 × 9 000 + R.A.M. minerals = 4 564 546.93.
        $this->assertKnownCost(4564546.93, $productionCost->materialCost);

        // Root job: EIV = 10 × (15 × 8 000 + 1 × 1 000 + 3 000 × 50 + 1 200 × 120) = 4 150 000,
        // × (0.05 × 0.97 + 1 % + 4 %) = 408 775.
        // R.A.M.: EIV per run 556 × 3 + 444 × 7 + 222 × 60 + 82 × 100 + 36 × 400 = 40 696, × 0.092130192 run × 0.0985 = 369.31.
        $this->assertKnownCost(409144.31, $productionCost->installCost);
        $this->assertNull($productionCost->invention);

        // 4 564 546.93 + 409 144.31 = 4 973 691.24 for 10 × 5 000 = 50 000 units.
        $this->assertSame(50000, $productionCost->producedQuantity->value);
        $this->assertKnownCost(4973691.24, $productionCost->totalCost);
        $this->assertTrue($productionCost->costPerUnit->isKnown());
        $this->assertEqualsWithDelta(99.47382486, $productionCost->costPerUnit->amount()->amount, self::FLOAT_TOLERANCE);
        $this->assertSame([], $productionCost->totalCost->missingData);
    }

    public function testHailLLeavesKeepTheirFractionalQuantityUnitPriceAndCost(): void
    {
        $productionCost = self::hailLCalculator()->costOf(self::hailLRequest(CostMode::MarginalCost));

        $expectedLeaves = [
            self::FERNITE_CARBIDE => [27639.0576, 60.0, 1658343.456],
            self::FULLERIDES => [11055.62304, 150.0, 1658343.456],
            self::MORPHITE => [138.195288, 9000.0, 1243757.592],
            self::TRITANIUM => [45.640928596032, 4.0, 182.563714384],
            self::PYERITE => [36.447072475968, 8.0, 291.576579808],
            self::MEXALLON => [18.223536237984, 70.0, 1275.647536659],
            self::ISOGEN => [6.731216087904, 130.0, 875.058091428],
            self::NOCXIUM => [2.955168038592, 500.0, 1477.584019296],
        ];
        $this->assertEqualsCanonicalizing(array_keys($expectedLeaves), array_keys($productionCost->leaves));
        foreach ($expectedLeaves as $typeId => [$expectedQuantity, $expectedUnitPrice, $expectedCost]) {
            $leaf = $productionCost->leaves[$typeId];
            $this->assertSame($typeId, $leaf->typeId);
            $this->assertEqualsWithDelta($expectedQuantity, $leaf->quantity, self::FLOAT_TOLERANCE, "quantity of {$typeId}");
            $this->assertNotNull($leaf->unitPrice, "unit price of {$typeId}");
            $this->assertEqualsWithDelta($expectedUnitPrice, $leaf->unitPrice->amount, self::FLOAT_TOLERANCE, "unit price of {$typeId}");
            $this->assertTrue($leaf->cost->isKnown(), "cost of {$typeId}");
            $this->assertEqualsWithDelta($expectedCost, $leaf->cost->amount()->amount, self::FLOAT_TOLERANCE, "cost of {$typeId}");
        }
    }

    public function testHailLJobsExposeTheirFractionalRunsChosenStructureAndInstallCostDetail(): void
    {
        $productionCost = self::hailLCalculator()->costOf(self::hailLRequest(CostMode::MarginalCost));

        $this->assertCount(2, $productionCost->jobs);
        $rootJob = self::jobOf($productionCost, self::HAIL_L);
        $this->assertSame(ActivityKind::Manufacturing, $rootJob->activity);
        $this->assertEqualsWithDelta(10.0, $rootJob->runs, self::FLOAT_TOLERANCE);
        $this->assertSame('raitaru-nullsec', $rootJob->structureChoice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestConfigured, $rootJob->structureChoice->status);
        $this->assertSame(self::RAITARU_SYSTEM_ID, $rootJob->solarSystemId);
        // R6 on EIV 4 150 000: system 4 150 000 × 0.05 × 0.97, tax 1 %, SCC 4 %, no alpha tax.
        $this->assertKnownCost(201275.0, $rootJob->installCost->systemCost);
        $this->assertKnownCost(41500.0, $rootJob->installCost->facilityTax);
        $this->assertKnownCost(166000.0, $rootJob->installCost->sccSurcharge);
        $this->assertKnownCost(0.0, $rootJob->installCost->alphaCloneTax);
        $this->assertKnownCost(408775.0, $rootJob->installCost->total);

        // D4b: the intermediate is paid pro rata of its demand, 0.092130192 run, not 1 whole run.
        $ramJob = self::jobOf($productionCost, self::RAM_AMMUNITION_TECH);
        $this->assertEqualsWithDelta(0.092130192, $ramJob->runs, self::FLOAT_TOLERANCE);
        $this->assertSame('raitaru-nullsec', $ramJob->structureChoice->structure?->id);
        $this->assertTrue($ramJob->installCost->total->isKnown());
        $this->assertEqualsWithDelta(369.309033923, $ramJob->installCost->total->amount()->amount, self::FLOAT_TOLERANCE);
    }

    public function testHailLInPlanModeBuysTheGoldenLeavesAndPaysWholeRuns(): void
    {
        $productionCost = self::hailLCalculator()->costOf(self::hailLRequest(CostMode::Plan));

        // Golden §4.2 leaves: Fernite Carbide 27 640, Fullerides 11 056, Tritanium 496, Pyerite 396, Mexallon 198,
        // Morphite 139, Isogen 74, Nocxium 33.
        $leafQuantities = array_map(static fn (LeafCost $leaf): float => $leaf->quantity, $productionCost->leaves);
        ksort($leafQuantities);
        $this->assertEquals([
            self::TRITANIUM => 496.0,
            self::PYERITE => 396.0,
            self::MEXALLON => 198.0,
            self::ISOGEN => 74.0,
            self::NOCXIUM => 33.0,
            self::MORPHITE => 139.0,
            self::FERNITE_CARBIDE => 27640.0,
            self::FULLERIDES => 11056.0,
        ], $leafQuantities);
        // 139 × 9 000 + 27 640 × 60 + 11 056 × 150 + 496 × 4 + 396 × 8 + 198 × 70 + 74 × 130 + 33 × 500 = 4 612 932.
        $this->assertKnownCost(4612932.0, $productionCost->materialCost);

        // R.A.M.: 1 whole run (90 in surplus), install 40 696 × 0.0985 = 4 008.56; + 408 775 for the root job.
        $this->assertEqualsWithDelta(1.0, self::jobOf($productionCost, self::RAM_AMMUNITION_TECH)->runs, self::FLOAT_TOLERANCE);
        $this->assertKnownCost(412783.56, $productionCost->installCost);

        // 5 025 715.56 / 50 000 units.
        $this->assertKnownCost(5025715.56, $productionCost->totalCost);
        $this->assertEqualsWithDelta(100.51431112, $productionCost->costPerUnit->amount()->amount, self::FLOAT_TOLERANCE);
    }

    public function testMissingMarketPriceMakesTheCostUnknownAndListsTheTypeId(): void
    {
        // #8: today a material without price costs 0 (ProfitMarginService: $priceData['weightedPrice'] ?? 0.0).
        $marketPricesWithoutMorphite = self::HAIL_L_MARKET_PRICES;
        unset($marketPricesWithoutMorphite[self::MORPHITE]);
        $calculator = self::hailLCalculator(marketPrices: new InMemoryMarketPrices($marketPricesWithoutMorphite));

        $productionCost = $calculator->costOf(self::hailLRequest(CostMode::MarginalCost));

        // R10: the quantity stays computed, the leaf cost is unknown.
        $morphite = $productionCost->leaves[self::MORPHITE];
        $this->assertEqualsWithDelta(138.195288, $morphite->quantity, self::FLOAT_TOLERANCE);
        $this->assertNull($morphite->unitPrice);
        $this->assertFalse($morphite->cost->isKnown());
        $this->assertEquals([MissingData::marketPrice(self::MORPHITE)], $morphite->cost->missingData);
        $this->assertKnownCost(1658343.456, $productionCost->leaves[self::FERNITE_CARBIDE]->cost);

        $this->assertFalse($productionCost->materialCost->isKnown());
        $this->assertKnownCost(409144.31, $productionCost->installCost);
        $this->assertFalse($productionCost->totalCost->isKnown());
        $this->assertEquals([MissingData::marketPrice(self::MORPHITE)], $productionCost->totalCost->missingData);
        $this->assertFalse($productionCost->costPerUnit->isKnown());
        $this->assertEquals([MissingData::marketPrice(self::MORPHITE)], $productionCost->costPerUnit->missingData);
    }

    public function testMissingCostIndexMakesTheInstallCostUnknownNeverZero(): void
    {
        // #7: today EsiCostIndexService returns 0.0 when the system has no index.
        $calculator = self::hailLCalculator(costIndices: InMemorySystemCostIndices::none());

        $productionCost = $calculator->costOf(self::hailLRequest(CostMode::MarginalCost));

        $this->assertKnownCost(4564546.93, $productionCost->materialCost);
        $rootJob = self::jobOf($productionCost, self::HAIL_L);
        $this->assertFalse($rootJob->installCost->systemCost->isKnown());
        // The parts that do not depend on the cost index stay known.
        $this->assertKnownCost(41500.0, $rootJob->installCost->facilityTax);
        $this->assertKnownCost(166000.0, $rootJob->installCost->sccSurcharge);

        $this->assertFalse($productionCost->installCost->isKnown());
        $this->assertEquals([MissingData::costIndex()], $productionCost->installCost->missingData);
        $this->assertFalse($productionCost->totalCost->isKnown());
        $this->assertEquals([MissingData::costIndex()], $productionCost->totalCost->missingData);
        $this->assertFalse($productionCost->costPerUnit->isKnown());
    }

    public function testStructureWithoutKnownSystemAndNoFavoriteSystemGivesAnUnknownCostIndex(): void
    {
        // Only imported structures know their system; the favorite system replaces it. Without either, no index.
        $raitaruWithoutSystem = ProductionStructures::raitaruWithFacilityTax(
            'raitaru-nullsec',
            SecurityClass::NullSec,
            null,
            self::RAITARU_FACILITY_TAX_RATE,
            ProductionStructures::manufacturingRig(2.4, 'ammunition'),
        );

        $productionCost = self::hailLCalculator()->costOf(self::hailLRequest(CostMode::MarginalCost, [$raitaruWithoutSystem]));

        $this->assertNull(self::jobOf($productionCost, self::HAIL_L)->solarSystemId);
        $this->assertKnownCost(4564546.93, $productionCost->materialCost);
        $this->assertFalse($productionCost->installCost->isKnown());
        $this->assertEquals([MissingData::costIndex()], $productionCost->totalCost->missingData);
    }

    public function testT2UnitCostAddsTheExpectedInventionWithTheT1CopyOfEachAttempt(): void
    {
        $productionCost = self::hobgoblinIICalculator(self::HOBGOBLIN_II_BASE_PROBABILITY)->costOf(self::hobgoblinIIRequest());

        // No structure: NPC station of the favorite manufacturing system, tax 0.25 %, no cost role bonus.
        // Leaves: Tritanium 10 × 2 000 × 0.98 = 19 600 × 4, Morphite 10 × 10 × 0.98 = 98 × 9 000 → 960 400.
        $this->assertKnownCost(960400.0, $productionCost->materialCost);
        // EIV 10 × (2 000 × 3 + 10 × 8 000) = 860 000 × (0.04 + 0.25 % + 4 %) = 70 950.
        $this->assertKnownCost(70950.0, $productionCost->installCost);
        $hobgoblinIIJob = self::jobOf($productionCost, self::HOBGOBLIN_II);
        $this->assertSame(StructureChoiceStatus::NotConfigured, $hobgoblinIIJob->structureChoice->status);
        $this->assertSame(self::FAVORITE_MANUFACTURING_SYSTEM_ID, $hobgoblinIIJob->solarSystemId);

        $invention = $productionCost->invention;
        $this->assertNotNull($invention);
        // Datacores: 2 × 80 000 + 2 × 60 000; no decryptor: known 0.
        $this->assertKnownCost(280000.0, $invention->datacoresCost);
        $this->assertKnownCost(0.0, $invention->decryptorCost);
        // Invention: job cost base 2 % × EIV of the T2 manufacturing materials (86 000) = 1 720, × (0.06 + 0.25 % + 4 %) = 176.30.
        $this->assertKnownCost(176.3, $invention->inventionInstallCost->total);
        // 1-run T1 copy per attempt: 2 % × 2 500 × 3 = 150, × (0.03 + 0.25 % + 4 %) = 10.875.
        $this->assertKnownCost(10.875, $invention->copyInstallCost->total);
        $this->assertKnownCost(280187.175, $invention->attemptCost);
        // P = 0.34 × (1 + 8/30 + 4/40) = 0.464666…; 10 T2 runs / (P × 10 runs per BPC) = 2.152080344 attempts.
        $this->assertNotNull($invention->expectedAttempts);
        $this->assertEqualsWithDelta(2.152080344, $invention->expectedAttempts, self::FLOAT_TOLERANCE);
        // 280 187.175 × 2.152080344 = 602 985.31.
        $this->assertKnownCost(602985.31, $invention->total);

        // 960 400 + 70 950 + 602 985.31 = 1 634 335.31 for 10 units.
        $this->assertSame(10, $productionCost->producedQuantity->value);
        $this->assertKnownCost(1634335.31, $productionCost->totalCost);
        $this->assertEqualsWithDelta(163433.531205, $productionCost->costPerUnit->amount()->amount, self::FLOAT_TOLERANCE);
    }

    public function testMissingInventionProbabilityMakesTheT2CostUnknown(): void
    {
        // #74: 8 invention rows of the SDE have no probability.
        $productionCost = self::hobgoblinIICalculator(null)->costOf(self::hobgoblinIIRequest());

        $this->assertKnownCost(960400.0, $productionCost->materialCost);
        $this->assertNotNull($productionCost->invention);
        $this->assertNull($productionCost->invention->expectedAttempts);
        $this->assertKnownCost(280187.175, $productionCost->invention->attemptCost);
        $this->assertFalse($productionCost->invention->total->isKnown());
        $this->assertEquals([MissingData::inventionProbability(self::HOBGOBLIN_I_BLUEPRINT)], $productionCost->totalCost->missingData);
        $this->assertFalse($productionCost->costPerUnit->isKnown());
    }

    private static function hailLCalculator(
        ?InMemoryMarketPrices $marketPrices = null,
        ?InMemorySystemCostIndices $costIndices = null,
    ): ProductionCostCalculator {
        return new ProductionCostCalculator(
            InMemoryBlueprintCatalog::fromSdeFixture(),
            new InMemoryInventionCatalog(),
            $marketPrices ?? new InMemoryMarketPrices(self::HAIL_L_MARKET_PRICES),
            new InMemoryAdjustedPrices(self::HAIL_L_ADJUSTED_PRICES),
            $costIndices ?? new InMemorySystemCostIndices([
                self::RAITARU_SYSTEM_ID => [ActivityKind::Manufacturing->name => 0.05],
            ]),
        );
    }

    /**
     * @param ?list<ProductionStructure> $structures
     */
    private static function hailLRequest(CostMode $mode, ?array $structures = null): ProductionCostRequest
    {
        return new ProductionCostRequest(
            productTypeId: self::HAIL_L,
            runs: new Runs(10),
            efficiency: new BlueprintEfficiency(new MaterialEfficiency(2), new TimeEfficiency(4)),
            mode: $mode,
            structures: $structures ?? [ProductionStructures::raitaruWithFacilityTax(
                'raitaru-nullsec',
                SecurityClass::NullSec,
                self::RAITARU_SYSTEM_ID,
                self::RAITARU_FACILITY_TAX_RATE,
                ProductionStructures::manufacturingRig(2.4, 'ammunition'),
            )],
            favoriteSystems: FavoriteSystems::none(),
            productCategories: [self::HAIL_L => 'ammunition', self::RAM_AMMUNITION_TECH => null],
            blacklist: [self::FERNITE_CARBIDE, self::FULLERIDES],
        );
    }

    /**
     * Synthetic T2: Hobgoblin II from 2 000 Tritanium and 10 Morphite per run, invented from the Hobgoblin I Blueprint
     * (Hobgoblin I: 2 500 Tritanium per run), 10 runs per invented BPC, 2 + 2 datacores per attempt.
     * Favorite manufacturing system indices: manufacturing 0.04, invention 0.06, copying 0.03.
     */
    private static function hobgoblinIICalculator(?float $baseProbability): ProductionCostCalculator
    {
        return new ProductionCostCalculator(
            self::hobgoblinCatalog(),
            new InMemoryInventionCatalog([
                self::HOBGOBLIN_II => new InventionRecipe(
                    t1BlueprintTypeId: self::HOBGOBLIN_I_BLUEPRINT,
                    t1ProductTypeId: self::HOBGOBLIN_I,
                    baseProbability: $baseProbability,
                    baseRuns: new Runs(10),
                    datacores: [
                        new RecipeMaterial(self::FIRST_DATACORE, new Quantity(2)),
                        new RecipeMaterial(self::SECOND_DATACORE, new Quantity(2)),
                    ],
                ),
            ]),
            new InMemoryMarketPrices([
                self::TRITANIUM => 4.0,
                self::MORPHITE => 9000.0,
                self::FIRST_DATACORE => 80000.0,
                self::SECOND_DATACORE => 60000.0,
            ]),
            new InMemoryAdjustedPrices([self::TRITANIUM => 3.0, self::MORPHITE => 8000.0]),
            new InMemorySystemCostIndices([
                self::FAVORITE_MANUFACTURING_SYSTEM_ID => [
                    ActivityKind::Manufacturing->name => 0.04,
                    ActivityKind::Invention->name => 0.06,
                    ActivityKind::Copying->name => 0.03,
                ],
            ]),
        );
    }

    private static function hobgoblinIIRequest(): ProductionCostRequest
    {
        return new ProductionCostRequest(
            productTypeId: self::HOBGOBLIN_II,
            runs: new Runs(10),
            efficiency: new BlueprintEfficiency(new MaterialEfficiency(2), new TimeEfficiency(4)),
            mode: CostMode::MarginalCost,
            structures: [],
            favoriteSystems: new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, null),
            productCategories: [self::HOBGOBLIN_II => null],
            invention: new InventionSettings(
                firstScienceSkillLevel: 4,
                secondScienceSkillLevel: 4,
                encryptionSkillLevel: 4,
                decryptor: null,
            ),
        );
    }

    private static function hobgoblinCatalog(): BlueprintCatalog
    {
        return new InMemoryBlueprintCatalog(
            new Recipe(
                productTypeId: self::HOBGOBLIN_II,
                activity: ActivityKind::Manufacturing,
                outputPerRun: new Quantity(1),
                materials: [
                    new RecipeMaterial(self::TRITANIUM, new Quantity(2000)),
                    new RecipeMaterial(self::MORPHITE, new Quantity(10)),
                ],
                maxProductionLimit: new Runs(10),
                baseTimeSeconds: 600,
            ),
            new Recipe(
                productTypeId: self::HOBGOBLIN_I,
                activity: ActivityKind::Manufacturing,
                outputPerRun: new Quantity(1),
                materials: [new RecipeMaterial(self::TRITANIUM, new Quantity(2500))],
                maxProductionLimit: new Runs(300),
                baseTimeSeconds: 600,
            ),
        );
    }

    private static function jobOf(ProductionCost $productionCost, int $productTypeId): JobCost
    {
        $jobs = array_values(array_filter(
            $productionCost->jobs,
            static fn (JobCost $job): bool => $job->productTypeId === $productTypeId,
        ));
        self::assertCount(1, $jobs, "one job expected for product {$productTypeId}");

        return $jobs[0];
    }

    private function assertKnownCost(float $expectedAmount, Cost $cost): void
    {
        $this->assertTrue($cost->isKnown(), 'cost should be known, missing: '.json_encode($cost->missingData));
        $this->assertEqualsWithDelta($expectedAmount, $cost->amount()->amount, self::ISK_TOLERANCE);
    }
}
