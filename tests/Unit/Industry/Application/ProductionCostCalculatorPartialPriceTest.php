<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Constant\EveConstants;
use App\Industry\Application\CostMode;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\InventionRecipe;
use App\Industry\Application\InventionSettings;
use App\Industry\Application\MarketPrices;
use App\Industry\Application\ProductionCost;
use App\Industry\Application\ProductionCostCalculator;
use App\Industry\Application\ProductionCostRequest;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Decryptor;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\OrderBookCoverage;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\PartialPrice;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Industry\Infrastructure\Market\JitaMarketPrices;
use App\Service\ESI\EsiClient;
use App\Service\JitaMarketService;
use App\Tests\Unit\Industry\Application\Double\InMemoryAdjustedPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryInventionCatalog;
use App\Tests\Unit\Industry\Application\Double\InMemoryMarketPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(ProductionCostCalculator::class)]
final class ProductionCostCalculatorPartialPriceTest extends TestCase
{
    private const int AMMUNITION = 5001;
    private const int COMPOUND = 5002;
    private const int ADVANCED_AMMUNITION = 5003;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int ISOGEN = 37;
    private const int MORPHITE = 11399;

    private const int HOBGOBLIN_I = 2454;
    private const int HOBGOBLIN_I_BLUEPRINT = 2455;
    private const int HOBGOBLIN_II = 2456;
    private const int FIRST_DATACORE = 20424;
    private const int SECOND_DATACORE = 20418;
    private const int PROCESS_DECRYPTOR = 34205;
    private const float HOBGOBLIN_II_BASE_PROBABILITY = 0.3;

    private const int MANUFACTURING_SYSTEM_ID = 30000142;
    private const int REACTION_SYSTEM_ID = 30002510;

    private const int OUTPUT_PER_RUN_OF_A_HUNDRED = 100;
    private const int BPO_MAX_PRODUCTION_LIMIT = 300;
    private const int T2_BPC_MAX_PRODUCTION_LIMIT = 10;

    private const float TRITANIUM_COVERAGE = 0.3;
    private const float TRITANIUM_COVERAGE_OF_THE_INVENTED_PRODUCT = 0.35;
    private const float FIRST_DATACORE_COVERAGE = 0.45;
    private const float PROCESS_DECRYPTOR_COVERAGE = 0.25;

    private const float FLOAT_TOLERANCE = 1e-6;

    private const array AMMUNITION_MATERIALS = [self::TRITANIUM => 1000, self::PYERITE => 150, self::MEXALLON => 500];
    private const int ISOGEN_PER_AMMUNITION_RUN = 20;

    private const array AMMUNITION_MARKET_PRICES = [
        self::TRITANIUM => 6.0,
        self::PYERITE => 800.0 / 150.0,
        self::MEXALLON => 10.0,
    ];

    private const array AMMUNITION_ADJUSTED_PRICES = [
        self::TRITANIUM => 5.5,
        self::PYERITE => 4.8,
        self::MEXALLON => 9.2,
        self::ISOGEN => 30.0,
    ];

    private const array HOBGOBLIN_MARKET_PRICES = [
        self::TRITANIUM => 4.2,
        self::MORPHITE => 8700.0,
        self::FIRST_DATACORE => 72000.0,
        self::SECOND_DATACORE => 55000.0,
        self::PROCESS_DECRYPTOR => 61000.0,
    ];

    private array $theForgeOrdersByTypeId = [];

    public function testLeafBoughtAtAPartialPriceIsKnownAtItsQuantityTimesTheWeightedPriceAndCarriesItsCoverage(): void
    {
        $productionCost = self::ammunitionCostOf([self::TRITANIUM => self::TRITANIUM_COVERAGE]);

        $tritanium = $productionCost->leaves[self::TRITANIUM];
        $this->assertSame(1000.0, $tritanium->quantity);
        $this->assertNotNull($tritanium->unitPrice);
        $this->assertSame(6.0, $tritanium->unitPrice->amount);
        $this->assertKnownCost(6000.0, $tritanium->cost);
        $this->assertSame([], $tritanium->cost->missingData);
        $this->assertEquals([self::partialPrice(self::TRITANIUM, self::TRITANIUM_COVERAGE)], $tritanium->cost->partialPrices);
    }

    public function testLeafBoughtAtAFullCoverageHasNoPartialPrice(): void
    {
        $productionCost = self::ammunitionCostOf([self::TRITANIUM => self::TRITANIUM_COVERAGE]);

        $pyerite = $productionCost->leaves[self::PYERITE];
        $this->assertKnownCost(800.0, $pyerite->cost);
        $this->assertSame([], $pyerite->cost->partialPrices);
        $this->assertSame([], $productionCost->leaves[self::MEXALLON]->cost->partialPrices);
    }

    public function testMaterialCostWithOnePartialMaterialIsKnownAndListsOnlyThatMaterial(): void
    {
        $productionCost = self::ammunitionCostOf([self::TRITANIUM => self::TRITANIUM_COVERAGE]);

        $this->assertKnownCost(11800.0, $productionCost->materialCost);
        $this->assertSame([], $productionCost->materialCost->missingData);
        $this->assertEquals([self::partialPrice(self::TRITANIUM, self::TRITANIUM_COVERAGE)], $productionCost->materialCost->partialPrices);
    }

    public function testTotalCostAndCostPerUnitKeepThePartialPriceOfTheMaterial(): void
    {
        $productionCost = self::ammunitionCostOf([self::TRITANIUM => self::TRITANIUM_COVERAGE]);

        $this->assertKnownCost(1000.85, $productionCost->installCost);
        $this->assertSame([], $productionCost->installCost->partialPrices);
        $this->assertKnownCost(12800.85, $productionCost->totalCost);
        $this->assertEquals([self::partialPrice(self::TRITANIUM, self::TRITANIUM_COVERAGE)], $productionCost->totalCost->partialPrices);
        $this->assertSame(100, $productionCost->producedQuantity->value);
        $this->assertKnownCost(128.0085, $productionCost->costPerUnit);
        $this->assertEquals([self::partialPrice(self::TRITANIUM, self::TRITANIUM_COVERAGE)], $productionCost->costPerUnit->partialPrices);
    }

    public function testPartialPriceAndMissingMarketPriceAreBothListedOnTheUnknownCosts(): void
    {
        $productionCost = self::ammunitionCostOf(
            [self::TRITANIUM => self::TRITANIUM_COVERAGE],
            [self::ISOGEN => self::ISOGEN_PER_AMMUNITION_RUN],
        );

        $this->assertFalse($productionCost->leaves[self::ISOGEN]->cost->isKnown());
        $this->assertKnownCost(1056.35, $productionCost->installCost);
        foreach ([$productionCost->materialCost, $productionCost->totalCost, $productionCost->costPerUnit] as $unknownCost) {
            $this->assertFalse($unknownCost->isKnown());
            $this->assertEquals([MissingData::marketPrice(self::ISOGEN)], $unknownCost->missingData);
            $this->assertEquals([self::partialPrice(self::TRITANIUM, self::TRITANIUM_COVERAGE)], $unknownCost->partialPrices);
        }
    }

    public function testFullCoverageOfEveryPriceLeavesEveryCostWithoutPartialPriceAndAtTheSameAmounts(): void
    {
        $productionCost = self::ammunitionCostOf([]);

        $this->assertKnownCost(6000.0, $productionCost->leaves[self::TRITANIUM]->cost);
        $this->assertKnownCost(11800.0, $productionCost->materialCost);
        $this->assertKnownCost(12800.85, $productionCost->totalCost);
        $this->assertKnownCost(128.0085, $productionCost->costPerUnit);
        foreach ($productionCost->leaves as $leaf) {
            $this->assertSame([], $leaf->cost->partialPrices);
        }
        foreach ([$productionCost->materialCost, $productionCost->installCost, $productionCost->totalCost, $productionCost->costPerUnit] as $cost) {
            $this->assertSame([], $cost->partialPrices);
        }
    }

    public function testPartialDatacorePriceIsListedOnTheInventionAndTheTotalCost(): void
    {
        $productionCost = self::hobgoblinIICostOf([self::FIRST_DATACORE => self::FIRST_DATACORE_COVERAGE], null);

        $invention = $productionCost->invention;
        $this->assertNotNull($invention);
        $firstDatacorePartialPrice = [self::partialPrice(self::FIRST_DATACORE, self::FIRST_DATACORE_COVERAGE)];
        $this->assertKnownCost(309000.0, $invention->datacoresCost);
        $this->assertEquals($firstDatacorePartialPrice, $invention->datacoresCost->partialPrices);
        $this->assertKnownCost(0.0, $invention->decryptorCost);
        $this->assertSame([], $invention->decryptorCost->partialPrices);
        $this->assertKnownCost(309228.822, $invention->attemptCost);
        $this->assertEquals($firstDatacorePartialPrice, $invention->attemptCost->partialPrices);
        $this->assertKnownCost(754216.6390243904, $invention->total);
        $this->assertEquals($firstDatacorePartialPrice, $invention->total->partialPrices);
        $this->assertSame([], $productionCost->materialCost->partialPrices);
        $this->assertKnownCost(1926751.6390243904, $productionCost->totalCost);
        $this->assertEquals($firstDatacorePartialPrice, $productionCost->totalCost->partialPrices);
        $this->assertKnownCost(192675.16390243903, $productionCost->costPerUnit);
        $this->assertEquals($firstDatacorePartialPrice, $productionCost->costPerUnit->partialPrices);
    }

    public function testPartialDecryptorPriceIsListedOnTheInventionAndTheTotalCost(): void
    {
        $productionCost = self::hobgoblinIICostOf([self::PROCESS_DECRYPTOR => self::PROCESS_DECRYPTOR_COVERAGE], self::processDecryptor());

        $invention = $productionCost->invention;
        $this->assertNotNull($invention);
        $decryptorPartialPrice = [self::partialPrice(self::PROCESS_DECRYPTOR, self::PROCESS_DECRYPTOR_COVERAGE)];
        $this->assertKnownCost(309000.0, $invention->datacoresCost);
        $this->assertSame([], $invention->datacoresCost->partialPrices);
        $this->assertKnownCost(61000.0, $invention->decryptorCost);
        $this->assertEquals($decryptorPartialPrice, $invention->decryptorCost->partialPrices);
        $this->assertKnownCost(370228.822, $invention->attemptCost);
        $this->assertKnownCost(820906.4789356984, $invention->total);
        $this->assertEquals($decryptorPartialPrice, $invention->total->partialPrices);
        $this->assertKnownCost(1960231.4789356985, $productionCost->totalCost);
        $this->assertEquals($decryptorPartialPrice, $productionCost->totalCost->partialPrices);
    }

    public function testPartialLeafAndPartialDatacoreAreBothListedOnTheTotalCostInOrderOfAppearance(): void
    {
        $productionCost = self::hobgoblinIICostOf(
            [self::TRITANIUM => self::TRITANIUM_COVERAGE_OF_THE_INVENTED_PRODUCT, self::FIRST_DATACORE => self::FIRST_DATACORE_COVERAGE],
            null,
        );

        $this->assertKnownCost(1926751.6390243904, $productionCost->totalCost);
        $this->assertEquals(
            [
                self::partialPrice(self::TRITANIUM, self::TRITANIUM_COVERAGE_OF_THE_INVENTED_PRODUCT),
                self::partialPrice(self::FIRST_DATACORE, self::FIRST_DATACORE_COVERAGE),
            ],
            $productionCost->totalCost->partialPrices,
        );
    }

    public function testPlanModeBuysTheMaterialsOfAWholeReactionRunAndGetsAPartialPrice(): void
    {
        $this->theForgeOrdersByTypeId = [self::TRITANIUM => $this->threeHundredUnitsOrderBook()];

        $productionCost = $this->advancedAmmunitionCostOnJitaOf(CostMode::Plan);

        $tritanium = $productionCost->leaves[self::TRITANIUM];
        $this->assertSame(320.0, $tritanium->quantity);
        $this->assertNotNull($tritanium->unitPrice);
        $this->assertSame(6.0, $tritanium->unitPrice->amount);
        $this->assertKnownCost(1920.0, $tritanium->cost);
        $this->assertEquals([self::partialPrice(self::TRITANIUM, 0.9375)], $tritanium->cost->partialPrices);
        $this->assertTrue($productionCost->totalCost->isKnown());
        $this->assertEquals([self::partialPrice(self::TRITANIUM, 0.9375)], $productionCost->totalCost->partialPrices);
    }

    public function testMarginalCostModeBuysTheMaterialsOfTheFractionalReactionRunsAtAFullCoverage(): void
    {
        $this->theForgeOrdersByTypeId = [self::TRITANIUM => $this->threeHundredUnitsOrderBook()];

        $productionCost = $this->advancedAmmunitionCostOnJitaOf(CostMode::MarginalCost);

        $tritanium = $productionCost->leaves[self::TRITANIUM];
        $this->assertEqualsWithDelta(32.0, $tritanium->quantity, self::FLOAT_TOLERANCE);
        $this->assertNotNull($tritanium->unitPrice);
        $this->assertSame(5.0, $tritanium->unitPrice->amount);
        $this->assertKnownCost(160.0, $tritanium->cost);
        $this->assertSame([], $tritanium->cost->partialPrices);
        $this->assertTrue($productionCost->totalCost->isKnown());
        $this->assertSame([], $productionCost->totalCost->partialPrices);
    }

    public function testSameTypeBoughtAsLeafAndAsDatacoreIsListedOnceAtTheLowestCoverage(): void
    {
        $this->theForgeOrdersByTypeId = [self::TRITANIUM => $this->threeHundredUnitsOrderBook()];

        $productionCost = $this->hobgoblinIIWithTritaniumDatacoreCostOnJita();

        $tritanium = $productionCost->leaves[self::TRITANIUM];
        $this->assertSame(1000.0, $tritanium->quantity);
        $this->assertKnownCost(6000.0, $tritanium->cost);
        $this->assertEquals([self::partialPrice(self::TRITANIUM, 0.3)], $tritanium->cost->partialPrices);
        $invention = $productionCost->invention;
        $this->assertNotNull($invention);
        $this->assertKnownCost(3000.0, $invention->datacoresCost);
        $this->assertEquals([self::partialPrice(self::TRITANIUM, 0.6)], $invention->datacoresCost->partialPrices);
        $this->assertTrue($productionCost->totalCost->isKnown());
        $this->assertEquals([self::partialPrice(self::TRITANIUM, 0.3)], $productionCost->totalCost->partialPrices);
    }

    private static function ammunitionCostOf(array $coverages, array $additionalMaterials = []): ProductionCost
    {
        $calculator = new ProductionCostCalculator(
            new InMemoryBlueprintCatalog(
                self::recipe(self::AMMUNITION, ActivityKind::Manufacturing, self::OUTPUT_PER_RUN_OF_A_HUNDRED, self::AMMUNITION_MATERIALS + $additionalMaterials),
            ),
            new InMemoryInventionCatalog(),
            new InMemoryMarketPrices(self::AMMUNITION_MARKET_PRICES, $coverages),
            new InMemoryAdjustedPrices(self::AMMUNITION_ADJUSTED_PRICES),
            new InMemorySystemCostIndices([self::MANUFACTURING_SYSTEM_ID => [ActivityKind::Manufacturing->name => 0.05]]),
            new InMemoryRigCategories(),
        );

        return $calculator->costOf(new ProductionCostRequest(
            productTypeId: self::AMMUNITION,
            runs: new Runs(1),
            efficiency: new BlueprintEfficiency(new MaterialEfficiency(0), new TimeEfficiency(0)),
            mode: CostMode::Plan,
            structures: [],
            favoriteSystems: new FavoriteSystems(self::MANUFACTURING_SYSTEM_ID, null),
        ));
    }

    private static function hobgoblinIICostOf(array $coverages, ?Decryptor $decryptor): ProductionCost
    {
        $calculator = self::hobgoblinIICalculator(
            [self::TRITANIUM => 1500, self::MORPHITE => 12],
            [new RecipeMaterial(self::FIRST_DATACORE, new Quantity(2)), new RecipeMaterial(self::SECOND_DATACORE, new Quantity(3))],
            new InMemoryMarketPrices(self::HOBGOBLIN_MARKET_PRICES, $coverages),
        );

        return $calculator->costOf(self::hobgoblinIIRequest(new Runs(10), CostMode::MarginalCost, $decryptor));
    }

    private function hobgoblinIIWithTritaniumDatacoreCostOnJita(): ProductionCost
    {
        $calculator = self::hobgoblinIICalculator(
            [self::TRITANIUM => 1020],
            [new RecipeMaterial(self::TRITANIUM, new Quantity(500))],
            $this->jitaMarketPrices(),
        );

        return $calculator->costOf(self::hobgoblinIIRequest(new Runs(1), CostMode::Plan, null));
    }

    private static function hobgoblinIICalculator(array $hobgoblinIIMaterials, array $datacores, MarketPrices $marketPrices): ProductionCostCalculator
    {
        return new ProductionCostCalculator(
            new InMemoryBlueprintCatalog(
                self::recipe(self::HOBGOBLIN_II, ActivityKind::Manufacturing, 1, $hobgoblinIIMaterials, self::T2_BPC_MAX_PRODUCTION_LIMIT),
                self::recipe(self::HOBGOBLIN_I, ActivityKind::Manufacturing, 1, [self::TRITANIUM => 2400]),
            ),
            new InMemoryInventionCatalog([self::HOBGOBLIN_II => new InventionRecipe(
                t1BlueprintTypeId: self::HOBGOBLIN_I_BLUEPRINT,
                t1ProductTypeId: self::HOBGOBLIN_I,
                baseProbability: self::HOBGOBLIN_II_BASE_PROBABILITY,
                baseRuns: new Runs(10),
                datacores: $datacores,
            )]),
            $marketPrices,
            new InMemoryAdjustedPrices([self::TRITANIUM => 3.6, self::MORPHITE => 7900.0]),
            new InMemorySystemCostIndices([
                self::MANUFACTURING_SYSTEM_ID => [
                    ActivityKind::Manufacturing->name => 0.045,
                    ActivityKind::Invention->name => 0.065,
                    ActivityKind::Copying->name => 0.035,
                ],
            ]),
            new InMemoryRigCategories(),
        );
    }

    private static function hobgoblinIIRequest(Runs $runs, CostMode $mode, ?Decryptor $decryptor): ProductionCostRequest
    {
        return new ProductionCostRequest(
            productTypeId: self::HOBGOBLIN_II,
            runs: $runs,
            efficiency: new BlueprintEfficiency(new MaterialEfficiency(2), new TimeEfficiency(4)),
            mode: $mode,
            structures: [],
            favoriteSystems: new FavoriteSystems(self::MANUFACTURING_SYSTEM_ID, null),
            invention: new InventionSettings(
                firstScienceSkillLevel: 4,
                secondScienceSkillLevel: 4,
                encryptionSkillLevel: 4,
                decryptor: $decryptor,
            ),
        );
    }

    private static function processDecryptor(): Decryptor
    {
        return new Decryptor(self::PROCESS_DECRYPTOR, new Multiplier(1.1), 0, 3, 6);
    }

    private function advancedAmmunitionCostOnJitaOf(CostMode $mode): ProductionCost
    {
        $calculator = new ProductionCostCalculator(
            new InMemoryBlueprintCatalog(
                self::recipe(self::ADVANCED_AMMUNITION, ActivityKind::Manufacturing, self::OUTPUT_PER_RUN_OF_A_HUNDRED, [self::COMPOUND => 10]),
                self::recipe(self::COMPOUND, ActivityKind::Reaction, self::OUTPUT_PER_RUN_OF_A_HUNDRED, [self::TRITANIUM => 320]),
            ),
            new InMemoryInventionCatalog(),
            $this->jitaMarketPrices(),
            new InMemoryAdjustedPrices([self::COMPOUND => 25.0, self::TRITANIUM => 5.5]),
            new InMemorySystemCostIndices([
                self::MANUFACTURING_SYSTEM_ID => [ActivityKind::Manufacturing->name => 0.05],
                self::REACTION_SYSTEM_ID => [ActivityKind::Reaction->name => 0.02],
            ]),
            new InMemoryRigCategories(),
        );

        return $calculator->costOf(new ProductionCostRequest(
            productTypeId: self::ADVANCED_AMMUNITION,
            runs: new Runs(1),
            efficiency: new BlueprintEfficiency(new MaterialEfficiency(0), new TimeEfficiency(0)),
            mode: $mode,
            structures: [],
            favoriteSystems: new FavoriteSystems(self::MANUFACTURING_SYSTEM_ID, self::REACTION_SYSTEM_ID),
        ));
    }

    private function jitaMarketPrices(): JitaMarketPrices
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getBatch')->willReturnCallback(
            fn (array $endpointsByTypeId): array => array_map(
                fn (int $typeId): ?array => $this->theForgeOrdersByTypeId[$typeId] ?? null,
                array_combine(array_keys($endpointsByTypeId), array_keys($endpointsByTypeId)),
            ),
        );

        return new JitaMarketPrices(new JitaMarketService(
            esiClient: $esiClient,
            cache: new ArrayAdapter(),
            connection: $this->createStub(Connection::class),
            logger: new NullLogger(),
        ));
    }

    private function threeHundredUnitsOrderBook(): array
    {
        return [$this->sellOrder(7.0, 100), $this->sellOrder(5.0, 100), $this->sellOrder(6.0, 100)];
    }

    private function sellOrder(float $price, int $volumeRemain): array
    {
        return ['price' => $price, 'volume_remain' => $volumeRemain, 'location_id' => EveConstants::JITA_STATION_ID, 'is_buy_order' => false];
    }

    private static function recipe(
        int $productTypeId,
        ActivityKind $activity,
        int $outputPerRun,
        array $baseQuantitiesPerRun,
        int $maxProductionLimit = self::BPO_MAX_PRODUCTION_LIMIT,
    ): Recipe {
        $materials = [];
        foreach ($baseQuantitiesPerRun as $materialTypeId => $baseQuantityPerRun) {
            $materials[] = new RecipeMaterial($materialTypeId, new Quantity($baseQuantityPerRun));
        }

        return new Recipe(
            productTypeId: $productTypeId,
            activity: $activity,
            outputPerRun: new OutputPerRun($outputPerRun),
            materials: $materials,
            maxProductionLimit: new Runs($maxProductionLimit),
            baseTimeSeconds: 600,
        );
    }

    private static function partialPrice(int $typeId, float $coverage): PartialPrice
    {
        return new PartialPrice($typeId, new OrderBookCoverage($coverage));
    }

    private function assertKnownCost(float $expectedAmount, Cost $cost): void
    {
        $this->assertTrue($cost->isKnown(), 'cost should be known, missing: '.json_encode($cost->missingData));
        $this->assertEqualsWithDelta($expectedAmount, $cost->amount()->amount, self::FLOAT_TOLERANCE);
    }
}
