<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryStockpileTarget;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryStockpileImportRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\Service\Industry\StockpileService;
use App\Service\JitaMarketService;
use App\Tests\Unit\Service\Industry\Double\SellQuoteCallbacks;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StockpileService::class)]
#[Group('issue-538')]
final class StockpileStatusWeightedPriceTest extends TestCase
{
    use BuildsStockpileOnWidgetSde;

    private const array SELL_BOOKS = [
        self::ORE => [[5.0, 100], [6.0, 100], [7.0, 100]],
        self::FRAME => [[40.0, 150], [44.0, 150]],
        self::GADGET => [[300.0, 100]],
    ];

    private const array FULL_SOURCE = ['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => null];

    private array $targets = [];
    private array $stockByTypeId = [];
    private array $quoteReads = [];
    private array $bestOrderReads = [];
    private \Closure $sellQuotes;

    private StockpileService $service;
    private User $pilot;

    protected function setUp(): void
    {
        $this->useWidgetRecipes();
        $this->pilot = $this->pilotWithId();
        $this->sellQuotes = SellQuoteCallbacks::weighedFrom(self::SELL_BOOKS);

        $targetRepository = $this->createStub(IndustryStockpileTargetRepository::class);
        $targetRepository->method('findByUser')->willReturnCallback(fn (): array => $this->targets);
        $importRepository = $this->createStub(IndustryStockpileImportRepository::class);
        $importRepository->method('findByUserInCreationOrder')->willReturn([]);
        $assetRepository = $this->createStub(CachedAssetRepository::class);
        $assetRepository->method('getQuantitiesByUserWithLocations')->willReturnCallback(
            fn (): array => array_map(static fn (int $stock): array => ['total' => $stock, 'locations' => []], $this->stockByTypeId),
        );
        $jitaMarketService = $this->createMock(JitaMarketService::class);
        $jitaMarketService->method('getPricesWithFallback')->willReturnCallback(
            function (array $typeIds): array {
                $this->bestOrderReads[] = $typeIds;

                return array_map(static fn (int $typeId): ?float => self::SELL_BOOKS[$typeId][0][0] ?? null, array_combine($typeIds, $typeIds));
            },
        );
        $jitaMarketService->method('getWeightedSellQuotesWithFallback')->willReturnCallback(
            function (array $requests): array {
                $this->quoteReads[] = $requests;

                return ($this->sellQuotes)($requests);
            },
        );
        $this->service = new StockpileService(
            $targetRepository,
            $assetRepository,
            $jitaMarketService,
            $this->productionPlanningOnWidgetSde(),
            $this->userStructuresOfThePilot(),
            $this->productRepositoryOnWidgetSde(),
            $this->createStub(EntityManagerInterface::class),
            $importRepository,
            $this->startingStockReducerOnWidgetSde(),
            $this->blacklistOfThePilot(),
        );
    }

    public function testStockValueIsTheStockTimesTheWeightedPriceOfTheStock(): void
    {
        $this->addManualTarget(self::ORE, 100, 'raw_material');
        $this->stockByTypeId = [self::ORE => 100];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            [
                'unitPrice' => 5.0,
                'stockValue' => 500.0,
                'deficit' => 0,
                'deficitCost' => 0.0,
                'priceSources' => ['stock' => self::FULL_SOURCE, 'deficit' => null],
            ],
            $this->fieldsOf($status, self::ORE, ['unitPrice', 'stockValue', 'deficit', 'deficitCost', 'priceSources']),
        );
        self::assertSame(500.0, $status['kpis']['totalInvested']);
        self::assertSame(['stockValue' => self::FULL_SOURCE, 'deficitCost' => null], $this->rootPriceSourcesOf($status));
        self::assertSame(['3003:100'], $this->requestedCouples());
        self::assertSame([], $status['missingPriceTypeIds']);
    }

    public function testDeficitCostIsTheDeficitTimesTheWeightedPriceOfTheDeficit(): void
    {
        $this->addManualTarget(self::ORE, 300, 'raw_material');
        $this->stockByTypeId = [self::ORE => 100];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            [
                'unitPrice' => 5.5,
                'stockValue' => 500.0,
                'deficit' => 200,
                'deficitCost' => 1100.0,
                'priceSources' => ['stock' => self::FULL_SOURCE, 'deficit' => self::FULL_SOURCE],
            ],
            $this->fieldsOf($status, self::ORE, ['unitPrice', 'stockValue', 'deficit', 'deficitCost', 'priceSources']),
        );
        self::assertSame(500.0, $status['kpis']['totalInvested']);
        self::assertSame(['stockValue' => self::FULL_SOURCE, 'deficitCost' => self::FULL_SOURCE], $this->rootPriceSourcesOf($status));
        self::assertSame(['3003:100', '3003:200'], $this->requestedCouples());
        self::assertCount(1, $this->quoteReads);
    }

    public function testDeficitBeyondTheSellBookKeepsItsWeightedCostAndMarksThePartialCoverage(): void
    {
        $this->addManualTarget(self::ORE, 500, 'raw_material');
        $this->stockByTypeId = [self::ORE => 100];

        $status = $this->service->getStockpileStatus($this->pilot);

        $partialSource = ['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => 75.0];
        self::assertSame(
            [
                'unitPrice' => 6.0,
                'stockValue' => 500.0,
                'deficit' => 400,
                'deficitCost' => 2400.0,
                'priceSources' => ['stock' => self::FULL_SOURCE, 'deficit' => $partialSource],
            ],
            $this->fieldsOf($status, self::ORE, ['unitPrice', 'stockValue', 'deficit', 'deficitCost', 'priceSources']),
        );
        self::assertSame(['stockValue' => self::FULL_SOURCE, 'deficitCost' => $partialSource], $this->rootPriceSourcesOf($status));
        self::assertSame($partialSource, $status['shoppingList'][0]['priceSources']['deficit']);
        self::assertSame([], $status['missingPriceTypeIds']);
    }

    public function testStockAndDeficitOfTheSameQuantityKeepTheirOwnAmountFromOneBookQuote(): void
    {
        $this->addManualTarget(self::ORE, 300, 'raw_material');
        $this->stockByTypeId = [self::ORE => 150];

        $status = $this->service->getStockpileStatus($this->pilot);

        $item = $this->itemOf($status, self::ORE);
        self::assertSame(800.0, $item['stockValue']);
        self::assertSame(800.0, $item['deficitCost']);
        self::assertSame(5.3333, round($item['unitPrice'], 4));
        self::assertSame(['3003:150'], $this->requestedCouples());
        self::assertCount(1, $this->quoteReads);
        self::assertCount(1, $this->quoteReads[0]);
    }

    public function testStockWithoutSellBookIsAnUnknownValueAndTheTypeIsMissing(): void
    {
        $this->addManualTarget(self::GADGET, 10, 'final_product');
        $this->sellQuotes = SellQuoteCallbacks::weighedFrom([]);
        $this->stockByTypeId = [self::GADGET => 10];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            [
                'unitPrice' => null,
                'stockValue' => null,
                'deficit' => 0,
                'deficitCost' => 0.0,
                'priceSources' => ['stock' => null, 'deficit' => null],
            ],
            $this->fieldsOf($status, self::GADGET, ['unitPrice', 'stockValue', 'deficit', 'deficitCost', 'priceSources']),
        );
        self::assertNull($status['stages']['final_product']['totalValue']);
        self::assertNull($status['kpis']['totalInvested']);
        self::assertSame([self::GADGET], $status['missingPriceTypeIds']);
        self::assertSame(['stockValue' => null, 'deficitCost' => null], $this->rootPriceSourcesOf($status));
    }

    public function testDeficitWithoutSellBookIsAnUnknownCostAndTheTypeIsMissing(): void
    {
        $this->addManualTarget(self::GADGET, 10, 'final_product');
        $this->sellQuotes = SellQuoteCallbacks::weighedFrom([]);

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            [
                'unitPrice' => null,
                'stockValue' => 0.0,
                'deficit' => 10,
                'deficitCost' => null,
                'priceSources' => ['stock' => null, 'deficit' => null],
            ],
            $this->fieldsOf($status, self::GADGET, ['unitPrice', 'stockValue', 'deficit', 'deficitCost', 'priceSources']),
        );
        self::assertSame([self::GADGET], $status['missingPriceTypeIds']);
        self::assertSame(['stockValue' => null, 'deficitCost' => null], $this->rootPriceSourcesOf($status));
    }

    public function testItemWithoutStockIsPricedOnItsDeficit(): void
    {
        $this->addManualTarget(self::ORE, 100, 'raw_material');

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            [
                'unitPrice' => 5.0,
                'stockValue' => 0.0,
                'deficit' => 100,
                'deficitCost' => 500.0,
                'priceSources' => ['stock' => null, 'deficit' => self::FULL_SOURCE],
            ],
            $this->fieldsOf($status, self::ORE, ['unitPrice', 'stockValue', 'deficit', 'deficitCost', 'priceSources']),
        );
        self::assertSame(['stockValue' => null, 'deficitCost' => self::FULL_SOURCE], $this->rootPriceSourcesOf($status));
        self::assertSame(['3003:100'], $this->requestedCouples());
    }

    public function testTargetAndStockOfQuantityZeroAreWorthZeroWithoutAnyBookRequestAndAreNotMissing(): void
    {
        $this->addManualTarget(self::ORE, 0, 'raw_material');

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            [
                'unitPrice' => null,
                'stockValue' => 0.0,
                'deficit' => 0,
                'deficitCost' => 0.0,
                'priceSources' => ['stock' => null, 'deficit' => null],
            ],
            $this->fieldsOf($status, self::ORE, ['unitPrice', 'stockValue', 'deficit', 'deficitCost', 'priceSources']),
        );
        self::assertSame([], $status['missingPriceTypeIds']);
        self::assertSame([], $this->requestedCouples());
        self::assertSame(['stockValue' => null, 'deficitCost' => null], $this->rootPriceSourcesOf($status));
    }

    #[DataProvider('quotesWithoutUsableCoverage')]
    public function testQuoteWithoutUsableCoverageIsAnUnknownPriceNeverADefaultOne(array $quote): void
    {
        $this->addManualTarget(self::ORE, 10, 'raw_material');
        $this->stockByTypeId = [self::ORE => 10];
        $this->sellQuotes = SellQuoteCallbacks::answering($quote);

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            ['unitPrice' => null, 'stockValue' => null, 'priceSources' => ['stock' => null, 'deficit' => null]],
            $this->fieldsOf($status, self::ORE, ['unitPrice', 'stockValue', 'priceSources']),
        );
        self::assertSame([self::ORE], $status['missingPriceTypeIds']);
    }

    public static function quotesWithoutUsableCoverage(): iterable
    {
        yield 'coverage of zero' => [['weightedPrice' => 5.0, 'coverage' => 0.0, 'ordersUsed' => 1]];
        yield 'coverage absent' => [['weightedPrice' => 5.0, 'ordersUsed' => 1]];
    }

    public function testRootPriceSourceIsTheLowestPartialCoverageAmongTheValuedItems(): void
    {
        $this->addManualTarget(self::FRAME, 500, 'component');
        $this->addManualTarget(self::GADGET, 200, 'final_product');
        $this->addManualTarget(self::ORE, 300, 'raw_material');
        $this->stockByTypeId = [self::FRAME => 400, self::GADGET => 200, self::ORE => 100];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            ['stockValue' => 16800.0, 'deficitCost' => 4000.0, 'priceSources' => [
                'stock' => ['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => 75.0],
                'deficit' => self::FULL_SOURCE,
            ]],
            $this->fieldsOf($status, self::FRAME, ['stockValue', 'deficitCost', 'priceSources']),
        );
        self::assertSame(60000.0, $this->itemOf($status, self::GADGET)['stockValue']);
        self::assertSame(77300.0, $status['kpis']['totalInvested']);
        self::assertSame(
            [
                'stockValue' => ['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => 50.0],
                'deficitCost' => self::FULL_SOURCE,
            ],
            $this->rootPriceSourcesOf($status),
        );
    }

    public function testStatusNeverFallsBackOnTheBestOrderPrice(): void
    {
        $this->addManualTarget(self::ORE, 300, 'raw_material');
        $this->addManualTarget(self::GADGET, 10, 'final_product');
        $this->sellQuotes = SellQuoteCallbacks::weighedFrom([self::ORE => self::SELL_BOOKS[self::ORE]]);
        $this->stockByTypeId = [self::ORE => 100, self::GADGET => 10];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame([], $this->bestOrderReads);
        self::assertSame([self::GADGET], $status['missingPriceTypeIds']);
        self::assertSame(1100.0, $this->itemOf($status, self::ORE)['deficitCost']);
        self::assertNull($this->itemOf($status, self::GADGET)['unitPrice']);
    }

    public function testStatusWithoutTargetHasNoPriceSourceAndReadsNoBook(): void
    {
        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(['stockValue' => null, 'deficitCost' => null], $this->rootPriceSourcesOf($status));
        self::assertSame([], $this->quoteReads);
    }

    private function addManualTarget(int $typeId, int $targetQuantity, string $stage): void
    {
        $this->targets[] = (new IndustryStockpileTarget())
            ->setUser($this->pilot)
            ->setTypeId($typeId)
            ->setTypeName(self::TYPE_NAMES[$typeId])
            ->setTargetQuantity($targetQuantity)
            ->setStage($stage)
            ->setSourceProductTypeId(null);
    }

    private function rootPriceSourcesOf(array $status): array
    {
        self::assertArrayHasKey('priceSources', $status);

        return $status['priceSources'];
    }

    private function requestedCouples(): array
    {
        $couples = array_map(
            static fn (array $request): string => $request['typeId'] . ':' . $request['quantity'],
            array_merge([], ...$this->quoteReads),
        );
        sort($couples);

        return $couples;
    }

    private function itemOf(array $status, int $typeId): array
    {
        foreach ($status['stages'] as $stage) {
            foreach ($stage['items'] as $item) {
                if ($item['typeId'] === $typeId) {
                    return $item;
                }
            }
        }
        self::fail("No stockpile item for type {$typeId}");
    }

    private function fieldsOf(array $status, int $typeId, array $keys): array
    {
        $item = $this->itemOf($status, $typeId);

        return array_intersect_key(array_merge(array_flip($keys), $item), $item, array_flip($keys));
    }
}
