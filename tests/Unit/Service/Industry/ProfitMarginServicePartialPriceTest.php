<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Industry\Application\Decryptors;
use App\Industry\Application\ProductionCostCalculator;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Repository\CachedStructureRepository;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\ProfitMarginService;
use App\Service\Industry\PublicContractPriceService;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\Service\TypeNameResolver;
use App\Tests\Unit\Industry\Application\Double\InMemoryAdjustedPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryInventionCatalog;
use App\Tests\Unit\Industry\Application\Double\InMemoryMarketPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProfitMarginService::class)]
final class ProfitMarginServicePartialPriceTest extends TestCase
{
    private const int AMMUNITION = 5001;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int ISOGEN = 37;

    private const int MANUFACTURING_SYSTEM_ID = 30000142;
    private const int SELL_STRUCTURE_ID = 1_035_466_617_946;
    private const int RUNS = 1;
    private const int OUTPUT_PER_RUN = 100;
    private const int BPO_MAX_PRODUCTION_LIMIT = 300;
    private const float BROKER_FEE_RATE = 0.03;
    private const float SALES_TAX_RATE = 0.045;
    private const float AMMUNITION_JITA_SELL_PRICE = 150.0;

    private const float TRITANIUM_COVERAGE = 0.30;
    private const float MEXALLON_COVERAGE = 0.9375;

    private const float FLOAT_TOLERANCE = 1e-6;

    private const array AMMUNITION_MATERIALS = [self::TRITANIUM => 1000, self::PYERITE => 150, self::MEXALLON => 500];
    private const int ISOGEN_PER_RUN = 20;

    private const array MARKET_PRICES = [
        self::TRITANIUM => 6.0,
        self::PYERITE => 800.0 / 150.0,
        self::MEXALLON => 10.0,
    ];

    private const array ADJUSTED_PRICES = [
        self::TRITANIUM => 5.5,
        self::PYERITE => 4.8,
        self::MEXALLON => 9.2,
        self::ISOGEN => 30.0,
    ];

    private const array TYPE_NAMES = [
        self::AMMUNITION => 'Ammunition',
        self::TRITANIUM => 'Tritanium',
        self::PYERITE => 'Pyerite',
        self::MEXALLON => 'Mexallon',
        self::ISOGEN => 'Isogen',
    ];

    public function testPartialMaterialPriceIsListedInTheWarningsWhileItsAmountsAndTheMarginsStayDisplayed(): void
    {
        $analysis = $this->analyze([self::TRITANIUM => self::TRITANIUM_COVERAGE]);

        $this->assertSame(['typeId' => self::TRITANIUM, 'typeName' => 'Tritanium', 'quantity' => 1000.0, 'unitPrice' => 6.0, 'totalPrice' => 6000.0], $analysis['materials'][0]);
        $this->assertEqualsWithDelta(11800.0, $analysis['materialCost'], self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(1000.85, $analysis['jobInstallCost'], self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(12800.85, $analysis['totalCost'], self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(128.0085, $analysis['costPerUnit'], self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(15000.0, $analysis['margins']['jitaSell']['revenue'], self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(1125.0, $analysis['margins']['jitaSell']['fees'], self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(1074.15, $analysis['margins']['jitaSell']['profit'], self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(8.39123964, $analysis['margins']['jitaSell']['margin'], self::FLOAT_TOLERANCE);
        $this->assertSame([], $analysis['missingData']);
        $this->assertSame(
            [['reason' => 'partial_market_depth', 'typeId' => self::TRITANIUM, 'coveragePercent' => 30.0]],
            $analysis['warnings'],
        );
    }

    public function testSeveralPartialMaterialPricesAreListedInTheOrderOfTheMaterials(): void
    {
        $analysis = $this->analyze([self::TRITANIUM => self::TRITANIUM_COVERAGE, self::MEXALLON => self::MEXALLON_COVERAGE]);

        $this->assertSame(
            [
                ['reason' => 'partial_market_depth', 'typeId' => self::TRITANIUM, 'coveragePercent' => 30.0],
                ['reason' => 'partial_market_depth', 'typeId' => self::MEXALLON, 'coveragePercent' => 93.8],
            ],
            $analysis['warnings'],
        );
    }

    public function testPartialMaterialPriceIsListedBesideTheMissingPriceOfAnotherMaterial(): void
    {
        $analysis = $this->analyze([self::TRITANIUM => self::TRITANIUM_COVERAGE], [self::ISOGEN => self::ISOGEN_PER_RUN]);

        $this->assertNull($analysis['materialCost']);
        $this->assertEqualsWithDelta(1056.35, $analysis['jobInstallCost'], self::FLOAT_TOLERANCE);
        $this->assertNull($analysis['totalCost']);
        $this->assertSame([['reason' => 'market_price', 'typeId' => self::ISOGEN]], $analysis['missingData']);
        $this->assertSame(
            [['reason' => 'partial_market_depth', 'typeId' => self::TRITANIUM, 'coveragePercent' => 30.0]],
            $analysis['warnings'],
        );
    }

    public function testFullCoverageOfEveryMaterialPriceLeavesTheWarningsEmpty(): void
    {
        $analysis = $this->analyze([]);

        $this->assertEqualsWithDelta(12800.85, $analysis['totalCost'], self::FLOAT_TOLERANCE);
        $this->assertSame([], $analysis['warnings']);
    }

    private function analyze(array $coverages, array $additionalMaterials = []): array
    {
        return $this->profitMarginService($coverages, $additionalMaterials)->analyze(
            self::AMMUNITION,
            self::RUNS,
            0,
            0,
            self::SELL_STRUCTURE_ID,
            self::MANUFACTURING_SYSTEM_ID,
            null,
            self::BROKER_FEE_RATE,
            self::SALES_TAX_RATE,
            null,
            self::MANUFACTURING_SYSTEM_ID,
        );
    }

    private function profitMarginService(array $coverages, array $additionalMaterials): ProfitMarginService
    {
        $decryptors = $this->createStub(Decryptors::class);
        $decryptors->method('all')->willReturn([]);

        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getWeightedSellPrice')->willReturn(['weightedPrice' => self::AMMUNITION_JITA_SELL_PRICE, 'coverage' => 1.0, 'ordersUsed' => 2]);
        $jitaMarketService->method('getAverageDailyVolumes')->willReturn([self::AMMUNITION => 420.0]);

        $typeNameResolver = $this->createStub(TypeNameResolver::class);
        $typeNameResolver->method('findName')->willReturnCallback(static fn (int $typeId): ?string => self::TYPE_NAMES[$typeId] ?? null);
        $typeNameResolver->method('findNames')->willReturnCallback(
            static fn (array $typeIds): array => array_intersect_key(self::TYPE_NAMES, array_flip($typeIds)),
        );

        return new ProfitMarginService(
            new ProductionCostCalculator(
                new InMemoryBlueprintCatalog(self::ammunitionRecipe(self::AMMUNITION_MATERIALS + $additionalMaterials)),
                new InMemoryInventionCatalog(),
                new InMemoryMarketPrices(self::MARKET_PRICES, $coverages),
                new InMemoryAdjustedPrices(self::ADJUSTED_PRICES),
                new InMemorySystemCostIndices([self::MANUFACTURING_SYSTEM_ID => [ActivityKind::Manufacturing->name => 0.05]]),
                new InMemoryRigCategories(),
            ),
            new InMemoryUserProductionStructures(),
            $decryptors,
            $jitaMarketService,
            $this->createStub(StructureMarketService::class),
            $this->createStub(PublicContractPriceService::class),
            $typeNameResolver,
            $this->createStub(CachedStructureRepository::class),
            $this->createStub(IndustryBlacklistService::class),
        );
    }

    private static function ammunitionRecipe(array $baseQuantitiesPerRun): Recipe
    {
        $materials = [];
        foreach ($baseQuantitiesPerRun as $materialTypeId => $baseQuantityPerRun) {
            $materials[] = new RecipeMaterial($materialTypeId, new Quantity($baseQuantityPerRun));
        }

        return new Recipe(
            productTypeId: self::AMMUNITION,
            activity: ActivityKind::Manufacturing,
            outputPerRun: new OutputPerRun(self::OUTPUT_PER_RUN),
            materials: $materials,
            maxProductionLimit: new Runs(self::BPO_MAX_PRODUCTION_LIMIT),
            baseTimeSeconds: 600,
        );
    }
}
