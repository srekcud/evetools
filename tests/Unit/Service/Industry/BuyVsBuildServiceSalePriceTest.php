<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Service\Industry\BuyVsBuildService;
use App\Service\Industry\SalesFees\SalesFees;
use App\Tests\Unit\Service\Industry\Double\SellQuoteCallbacks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuyVsBuildService::class)]
#[Group('issue-538')]
final class BuyVsBuildServiceSalePriceTest extends TestCase
{
    use BuildsBuyVsBuildOnGoldenSde;

    private const int FACTORY_SYSTEM_ID = 30_002_187;
    private const int SELL_STRUCTURE_ID = 1_035_466_617_946;
    private const float BROKER_FEE_RATE = 0.03;
    private const float SALES_TAX_RATE = 0.045;

    private const int OXY_ORGANIC_SOLVENTS = 57454;
    private const int RAM_AMMUNITION_TECH = 11476;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int ISOGEN = 37;
    private const int NOCXIUM = 38;
    private const int OXYGEN_FUEL_BLOCK = 4312;

    private const int RAM_RUNS_SELLING_THREE_HUNDRED_UNITS = 3;
    private const int RAM_ME_LEVEL = 0;

    private const array PRODUCT_SELL_BOOK = [[10.0, 100], [12.0, 100]];
    private const array RAM_SELL_BOOK = [[1000.0, 100], [1200.0, 100]];
    private const float RAM_THREE_RUNS_TOTAL_PRODUCTION_COST = 179067.99;

    private const array COST_INDICES = [
        self::FACTORY_SYSTEM_ID => ['manufacturing' => 0.05, 'reaction' => 0.1],
    ];

    private const array RAM_JITA_PRICES = [
        self::TRITANIUM => 5.0,
        self::PYERITE => 10.0,
        self::MEXALLON => 50.0,
        self::ISOGEN => 100.0,
        self::NOCXIUM => 800.0,
    ];

    private const array RAM_ADJUSTED_PRICES = [
        self::TRITANIUM => 4.0,
        self::PYERITE => 8.0,
        self::MEXALLON => 40.0,
        self::ISOGEN => 90.0,
        self::NOCXIUM => 700.0,
    ];

    private const array FULL_JITA_SELL_SOURCE = ['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => null];
    private const array STRUCTURE_SELL_SOURCE = ['market' => 'structure', 'side' => 'sell', 'method' => 'lowest', 'coveragePercent' => null];

    public function testSellingAsManyUnitsAsTheCheapestOrderHoldsIsPricedAtThatOrder(): void
    {
        $analysis = $this->analyzeOxyOrganicSolvents(runs: 10, jitaSellBooks: [self::OXY_ORGANIC_SOLVENTS => self::PRODUCT_SELL_BOOK]);

        $this->assertSame(10.0, $analysis['sellPrice']);
        $this->assertSame(self::FULL_JITA_SELL_SOURCE, $this->priceSourcesOf($analysis)['sale']);
    }

    public function testSellingHundredFiftyUnitsIsPricedAtTheWeightedPriceOfTheSellBook(): void
    {
        $analysis = $this->analyzeOxyOrganicSolvents(runs: 15, jitaSellBooks: [self::OXY_ORGANIC_SOLVENTS => self::PRODUCT_SELL_BOOK]);

        $this->assertSame(10.67, $analysis['sellPrice']);
        $this->assertSame(self::FULL_JITA_SELL_SOURCE, $this->priceSourcesOf($analysis)['sale']);
    }

    public function testSellingMoreUnitsThanTheSellBookHoldsKeepsTheWeightedPriceAndMarksThePartialCoverage(): void
    {
        $analysis = $this->analyzeOxyOrganicSolvents(runs: 30, jitaSellBooks: [self::OXY_ORGANIC_SOLVENTS => self::PRODUCT_SELL_BOOK]);

        $this->assertSame(11.0, $analysis['sellPrice']);
        $this->assertSame(['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => 66.7], $this->priceSourcesOf($analysis)['sale']);
    }

    public function testPartialSaleKeepsTheMarginPercentComputedOnTheWeightedPrice(): void
    {
        $analysis = $this->analyzeRamAmmunitionTech(jitaSellBooks: [self::RAM_AMMUNITION_TECH => self::RAM_SELL_BOOK]);

        $this->assertSame(1100.0, $analysis['sellPrice']);
        $this->assertSame(70.47, $analysis['marginPercent']);
        $this->assertSame(66.7, $this->priceSourcesOf($analysis)['sale']['coveragePercent']);
    }

    public function testSaleAtTheSellStructureLowestPriceIsLabelledStructureWithoutCoverage(): void
    {
        $analysis = $this->analyzeOxyOrganicSolvents(
            runs: 15,
            jitaSellBooks: [self::OXY_ORGANIC_SOLVENTS => self::PRODUCT_SELL_BOOK],
            structurePrices: [self::OXY_ORGANIC_SOLVENTS => 11.0],
        );

        $this->assertSame(11.0, $analysis['sellPrice']);
        $this->assertSame(self::STRUCTURE_SELL_SOURCE, $this->priceSourcesOf($analysis)['sale']);
    }

    public function testSaleFallsBackOnTheWeightedJitaPriceWhenTheSellStructureHasNoPriceForTheProduct(): void
    {
        $analysis = $this->analyzeOxyOrganicSolvents(
            runs: 15,
            jitaSellBooks: [self::OXY_ORGANIC_SOLVENTS => self::PRODUCT_SELL_BOOK],
            structurePrices: [self::OXYGEN_FUEL_BLOCK => 19000.0],
        );

        $this->assertSame(10.67, $analysis['sellPrice']);
        $this->assertSame(self::FULL_JITA_SELL_SOURCE, $this->priceSourcesOf($analysis)['sale']);
    }

    #[DataProvider('unknownSaleQuotes')]
    public function testSaleWithoutAUsableSellQuoteHasAnUnknownPriceMarginAndSource(?array $saleQuote): void
    {
        $analysis = $this->analyzeRamAmmunitionTech(saleQuotes: SellQuoteCallbacks::answering($saleQuote));

        $this->assertArrayHasKey('sellPrice', $analysis);
        $this->assertNull($analysis['sellPrice']);
        $this->assertArrayHasKey('marginPercent', $analysis);
        $this->assertNull($analysis['marginPercent']);
        $this->assertArrayHasKey('sale', $this->priceSourcesOf($analysis));
        $this->assertNull($this->priceSourcesOf($analysis)['sale']);
    }

    public static function unknownSaleQuotes(): iterable
    {
        yield 'empty sell book' => [null];
        yield 'coverage absent' => [['weightedPrice' => 10.0, 'ordersUsed' => 1]];
        yield 'coverage zero' => [['weightedPrice' => 10.0, 'coverage' => 0.0, 'ordersUsed' => 1]];
    }

    public function testSaleIsQuotedOnTheQuantityProducedByTheRuns(): void
    {
        $requestedQuantities = [];
        $recordingQuotes = static function (array $requests) use (&$requestedQuantities): array {
            foreach ($requests as $request) {
                $requestedQuantities[$request['typeId']][] = $request['quantity'];
            }

            return SellQuoteCallbacks::weighedFrom([self::RAM_AMMUNITION_TECH => self::RAM_SELL_BOOK])($requests);
        };

        $this->analyzeRamAmmunitionTech(saleQuotes: $recordingQuotes);

        $this->assertArrayHasKey(self::RAM_AMMUNITION_TECH, $requestedQuantities);
        $this->assertSame([300], $requestedQuantities[self::RAM_AMMUNITION_TECH]);
    }

    public function testMaterialCostsStayPricedOnTheWeightedPriceWhateverTheSaleBook(): void
    {
        $withDeepSellBook = $this->analyzeRamAmmunitionTech(jitaSellBooks: [self::RAM_AMMUNITION_TECH => [[10.0, 100000]]]);
        $withoutSellBook = $this->analyzeRamAmmunitionTech(saleQuotes: SellQuoteCallbacks::answering(null));

        $this->assertSame(self::RAM_THREE_RUNS_TOTAL_PRODUCTION_COST, $withDeepSellBook['totalProductionCost']);
        $this->assertSame($withDeepSellBook['totalProductionCost'], $withoutSellBook['totalProductionCost']);
        $this->assertSame([], $withDeepSellBook['components']);
        $this->assertSame([], $withDeepSellBook['warnings']);
    }

    public function testMaterialsPriceSourceIsJitaSellWeightedWithoutCoverageWhenEveryMaterialIsCovered(): void
    {
        $analysis = $this->analyzeRamAmmunitionTech(jitaSellBooks: [self::RAM_AMMUNITION_TECH => self::RAM_SELL_BOOK]);

        $this->assertSame(self::FULL_JITA_SELL_SOURCE, $this->priceSourcesOf($analysis)['materials']);
    }

    public function testMaterialsPriceSourceCarriesTheLowestCoverageOfTheAnalysisWarnings(): void
    {
        $analysis = $this->analyzeRamAmmunitionTech(
            jitaSellBooks: [self::RAM_AMMUNITION_TECH => self::RAM_SELL_BOOK],
            jitaCoverages: [self::TRITANIUM => 0.75, self::NOCXIUM => 0.5],
        );

        $this->assertSame(
            [
                ['reason' => 'partial_market_depth', 'typeId' => self::TRITANIUM, 'coveragePercent' => 75.0],
                ['reason' => 'partial_market_depth', 'typeId' => self::NOCXIUM, 'coveragePercent' => 50.0],
            ],
            $analysis['warnings'],
        );
        $this->assertSame(['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => 50.0], $this->priceSourcesOf($analysis)['materials']);
    }

    private function analyzeOxyOrganicSolvents(int $runs, array $jitaSellBooks, array $structurePrices = []): array
    {
        return $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: [],
            structurePrices: [self::SELL_STRUCTURE_ID => $structurePrices],
            adjustedPrices: [],
            costIndices: self::COST_INDICES,
            jitaSellBooks: $jitaSellBooks,
        )->analyze(self::OXY_ORGANIC_SOLVENTS, $runs, 0, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, null, null);
    }

    private function analyzeRamAmmunitionTech(array $jitaSellBooks = [], ?\Closure $saleQuotes = null, array $jitaCoverages = []): array
    {
        return $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: self::RAM_JITA_PRICES,
            structurePrices: [],
            adjustedPrices: self::RAM_ADJUSTED_PRICES,
            costIndices: self::COST_INDICES,
            jitaCoverages: $jitaCoverages,
            jitaSellBooks: $jitaSellBooks,
            saleQuotes: $saleQuotes,
        )->analyze(
            self::RAM_AMMUNITION_TECH,
            self::RAM_RUNS_SELLING_THREE_HUNDRED_UNITS,
            self::RAM_ME_LEVEL,
            self::FACTORY_SYSTEM_ID,
            null,
            new SalesFees(self::BROKER_FEE_RATE, self::SALES_TAX_RATE),
            null,
        );
    }

    private function priceSourcesOf(array $analysis): array
    {
        $this->assertArrayHasKey('priceSources', $analysis);

        return $analysis['priceSources'];
    }
}
