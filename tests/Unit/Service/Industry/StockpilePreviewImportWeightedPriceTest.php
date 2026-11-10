<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryStockpileImportRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\StockpileService;
use App\Service\JitaMarketService;
use App\Tests\Unit\Service\Industry\Double\SellQuoteCallbacks;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StockpileService::class)]
#[Group('issue-538')]
final class StockpilePreviewImportWeightedPriceTest extends TestCase
{
    use BuildsStockpileOnWidgetSde;

    private const int ORE_PER_WIDGET_RUN = 10;
    private const int ME_ZERO = 0;

    private const array SELL_BOOKS = [
        self::ORE => [[5.0, 100], [6.0, 100], [7.0, 100]],
        self::WIDGET => [[1000.0, 5], [1600.0, 10]],
    ];

    private const array FULL_SOURCE = ['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => null];

    private array $quoteReads = [];
    private array $bestOrderReads = [];
    private \Closure $sellQuotes;

    private StockpileService $service;
    private User $pilot;

    protected function setUp(): void
    {
        $this->recipes = [
            self::WIDGET => ['activity' => IndustryActivityType::Manufacturing, 'outputPerRun' => 1, 'materials' => [self::ORE => self::ORE_PER_WIDGET_RUN]],
        ];
        $this->pilot = $this->pilotWithId();
        $this->sellQuotes = SellQuoteCallbacks::weighedFrom(self::SELL_BOOKS);

        $typeRepository = $this->typeRepositoryOnWidgetSde();
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $className): ?InvTypeRepository => $className === InvType::class ? $typeRepository : null,
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
            $this->createStub(IndustryStockpileTargetRepository::class),
            $this->createStub(CachedAssetRepository::class),
            $jitaMarketService,
            $this->productionPlanningOnWidgetSde(),
            $this->userStructuresOfThePilot(),
            $this->productRepositoryOnWidgetSde(),
            $entityManager,
            $this->createStub(IndustryStockpileImportRepository::class),
            $this->startingStockReducerOnWidgetSde(),
            $this->blacklistOfThePilot(),
        );
    }

    public function testEachPreviewItemIsPricedAtTheWeightedPriceOfItsQuantityAndLabelledWithItsSource(): void
    {
        $preview = $this->service->previewImport($this->pilot, self::WIDGET, 15, self::ME_ZERO);

        $ore = $this->itemOf($preview, self::ORE);
        self::assertSame(150, $ore['quantity']);
        self::assertSame(5.3333, round($ore['unitPrice'], 4));
        self::assertSame(self::FULL_SOURCE, $ore['priceSource']);
        $widget = $this->itemOf($preview, self::WIDGET);
        self::assertSame(15, $widget['quantity']);
        self::assertSame(1400.0, $widget['unitPrice']);
        self::assertSame(self::FULL_SOURCE, $widget['priceSource']);
        self::assertSame(21800.0, $preview['estimatedCost']);
        self::assertSame([], $preview['missingPriceTypeIds']);
        self::assertSame(['estimatedCost' => self::FULL_SOURCE], $this->rootPriceSourcesOf($preview));
    }

    public function testPreviewReadsTheSellBookOnceForTheWholeListOfQuantities(): void
    {
        $this->service->previewImport($this->pilot, self::WIDGET, 15, self::ME_ZERO);

        self::assertSame(['3001:15', '3003:150'], $this->requestedCouples());
        self::assertCount(1, $this->quoteReads);
    }

    public function testPartialCoverageKeepsTheWeightedAmountAndTheRootTakesTheLowestCoverage(): void
    {
        $this->sellQuotes = SellQuoteCallbacks::weighedFrom([
            self::ORE => self::SELL_BOOKS[self::ORE],
            self::WIDGET => [[1000.0, 45]],
        ]);

        $preview = $this->service->previewImport($this->pilot, self::WIDGET, 60, self::ME_ZERO);

        $ore = $this->itemOf($preview, self::ORE);
        self::assertSame(600, $ore['quantity']);
        self::assertSame(6.0, $ore['unitPrice']);
        self::assertSame(['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => 50.0], $ore['priceSource']);
        $widget = $this->itemOf($preview, self::WIDGET);
        self::assertSame(1000.0, $widget['unitPrice']);
        self::assertSame(['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => 75.0], $widget['priceSource']);
        self::assertSame(63600.0, $preview['estimatedCost']);
        self::assertSame([], $preview['missingPriceTypeIds']);
        self::assertSame(
            ['estimatedCost' => ['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => 50.0]],
            $this->rootPriceSourcesOf($preview),
        );
    }

    public function testItemWithoutSellBookHasNoPriceNoSourceAndMakesTheEstimatedCostUnknown(): void
    {
        $this->sellQuotes = SellQuoteCallbacks::weighedFrom([self::WIDGET => self::SELL_BOOKS[self::WIDGET]]);

        $preview = $this->service->previewImport($this->pilot, self::WIDGET, 15, self::ME_ZERO);

        $ore = $this->itemOf($preview, self::ORE);
        self::assertArrayHasKey('unitPrice', $ore);
        self::assertNull($ore['unitPrice']);
        self::assertArrayHasKey('priceSource', $ore);
        self::assertNull($ore['priceSource']);
        self::assertSame(self::FULL_SOURCE, $this->itemOf($preview, self::WIDGET)['priceSource']);
        self::assertNull($preview['estimatedCost']);
        self::assertSame([self::ORE], $preview['missingPriceTypeIds']);
        $rootPriceSources = $this->rootPriceSourcesOf($preview);
        self::assertArrayHasKey('estimatedCost', $rootPriceSources);
        self::assertNull($rootPriceSources['estimatedCost']);
    }

    public function testQuoteWithZeroCoverageIsAnUnknownPriceNeverADefaultOne(): void
    {
        $this->sellQuotes = SellQuoteCallbacks::answering(['weightedPrice' => 5.0, 'coverage' => 0.0, 'ordersUsed' => 1]);

        $preview = $this->service->previewImport($this->pilot, self::WIDGET, 15, self::ME_ZERO);

        self::assertNull($this->itemOf($preview, self::ORE)['unitPrice']);
        self::assertNull($this->itemOf($preview, self::WIDGET)['unitPrice']);
        self::assertNull($preview['estimatedCost']);
        self::assertSame([self::WIDGET, self::ORE], $preview['missingPriceTypeIds']);
    }

    public function testPreviewNeverFallsBackOnTheBestOrderPrice(): void
    {
        $this->sellQuotes = SellQuoteCallbacks::weighedFrom([self::WIDGET => self::SELL_BOOKS[self::WIDGET]]);

        $preview = $this->service->previewImport($this->pilot, self::WIDGET, 15, self::ME_ZERO);

        self::assertSame([], $this->bestOrderReads);
        self::assertNull($this->itemOf($preview, self::ORE)['unitPrice']);
        self::assertSame(1400.0, $this->itemOf($preview, self::WIDGET)['unitPrice']);
    }

    private function rootPriceSourcesOf(array $preview): array
    {
        self::assertArrayHasKey('priceSources', $preview);

        return $preview['priceSources'];
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

    private function itemOf(array $preview, int $typeId): array
    {
        foreach ($preview['stages'] as $items) {
            foreach ($items as $item) {
                if ($item['typeId'] === $typeId) {
                    return $item;
                }
            }
        }
        self::fail("No preview item for type {$typeId}");
    }
}
