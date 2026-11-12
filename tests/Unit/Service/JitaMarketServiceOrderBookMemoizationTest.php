<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\JitaMarketService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(JitaMarketService::class)]
#[Group('issue-762')]
final class JitaMarketServiceOrderBookMemoizationTest extends TestCase
{
    use CreatesJitaMarketService;

    private const SELL_ORDER_BOOK_KEY = 'jita_market_prices';
    private const BUY_ORDER_BOOK_KEY = 'jita_market_buy_prices';
    private const TRITANIUM = 34;
    private const PYERITE = 35;
    private const MEXALLON = 36;

    private ReadCountingCachePool $marketCache;
    private JitaMarketService $service;

    protected function setUp(): void
    {
        $this->marketCache = new ReadCountingCachePool();
        $this->seed(self::SELL_ORDER_BOOK_KEY, [
            self::TRITANIUM => [['price' => 4.5, 'volume' => 1000], ['price' => 4.7, 'volume' => 500]],
            self::PYERITE => [['price' => 10.25, 'volume' => 300]],
        ]);
        $this->seed(self::BUY_ORDER_BOOK_KEY, [
            self::TRITANIUM => [['price' => 4.0, 'volume' => 800]],
        ]);
        $this->service = $this->createJitaMarketService(
            new MockHttpClient(static fn (): MockResponse => new MockResponse('[]')),
            'https://esi.evetech.net/latest',
            $this->marketCache,
        );
    }

    public function testReadsTheSellOrderBookOnceWhateverTheNumberOfPriceReads(): void
    {
        $this->service->getPrice(self::TRITANIUM);
        $this->service->getPrice(self::PYERITE);
        $this->service->getPrices([self::TRITANIUM, self::MEXALLON]);
        $this->service->getSellOrders(self::TRITANIUM);
        $this->service->getCachedWeightedSellQuotes([['typeId' => self::TRITANIUM, 'quantity' => 10]]);

        $this->assertSame(1, $this->marketCache->readsOf(self::SELL_ORDER_BOOK_KEY));
    }

    public function testReadsTheBuyOrderBookOnceWhateverTheNumberOfPriceReads(): void
    {
        $this->service->getBuyOrders(self::TRITANIUM);
        $this->service->getBuyOrders(self::PYERITE);
        $this->service->getBuyPrices([self::TRITANIUM, self::PYERITE]);

        $this->assertSame(1, $this->marketCache->readsOf(self::BUY_ORDER_BOOK_KEY));
    }

    public function testResetForcesTheNextReadToLoadTheOrderBookAgain(): void
    {
        $this->service->getPrice(self::TRITANIUM);
        $this->service->reset();
        $this->service->getPrice(self::TRITANIUM);
        $this->service->getPrice(self::PYERITE);

        $this->assertSame(2, $this->marketCache->readsOf(self::SELL_ORDER_BOOK_KEY));
    }

    public function testReturnsTheSamePricesAndOrdersAsTheCachedOrderBook(): void
    {
        $this->assertSame(4.5, $this->service->getPrice(self::TRITANIUM));
        $this->assertNull($this->service->getPrice(self::MEXALLON));
        $this->assertSame(
            [self::TRITANIUM => 4.5, self::PYERITE => 10.25, self::MEXALLON => null],
            $this->service->getPrices([self::TRITANIUM, self::PYERITE, self::MEXALLON]),
        );
        $this->assertSame([['price' => 4.0, 'volume' => 800]], $this->service->getBuyOrders(self::TRITANIUM));
        $this->assertSame([], $this->service->getBuyOrders(self::PYERITE));
    }

    public function testSeesAnOrderBookStoredAfterAnEmptyRead(): void
    {
        $emptyCache = new ReadCountingCachePool();
        $service = $this->createJitaMarketService(
            new MockHttpClient(static fn (): MockResponse => new MockResponse('[]')),
            'https://esi.evetech.net/latest',
            $emptyCache,
        );
        $this->assertNull($service->getPrice(self::TRITANIUM));

        $item = $emptyCache->getItem(self::SELL_ORDER_BOOK_KEY);
        $item->set([self::TRITANIUM => [['price' => 5.5, 'volume' => 10]]]);
        $emptyCache->save($item);

        $this->assertSame(5.5, $service->getPrice(self::TRITANIUM));
    }

    public function testServesTheFreshOrderBookRightAfterARefreshOfTypes(): void
    {
        $this->assertSame(4.5, $this->service->getPrice(self::TRITANIUM));
        $refreshingService = $this->createJitaMarketService(
            new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode([
                ['order_id' => 1, 'type_id' => self::TRITANIUM, 'price' => 3.9, 'volume_remain' => 70, 'is_buy_order' => false, 'location_id' => 60003760],
            ]), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']])),
            'https://esi.evetech.net/latest',
            $this->marketCache,
        );

        $refreshingService->getPrice(self::TRITANIUM);
        $refreshingService->refreshPricesForTypes([self::TRITANIUM]);

        $this->assertSame(3.9, $refreshingService->getPrice(self::TRITANIUM));
    }

    /**
     * @param array<int, list<array{price: float, volume: int}>> $orderBook
     */
    private function seed(string $key, array $orderBook): void
    {
        $item = $this->marketCache->getItem($key);
        $item->set($orderBook);
        $this->marketCache->save($item);
        $this->marketCache->resetReadCounts();
    }
}
