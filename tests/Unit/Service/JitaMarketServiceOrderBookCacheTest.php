<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\JitaMarketService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Order book selection (Jita station vs region, top 20 per side), cache lifetimes
 * (order books 2 h, on-demand order books 5 min, daily volumes 24 h) and multi-type results.
 *
 * ESI is simulated at the HTTP boundary (MockHttpClient), the market cache is a real ArrayAdapter.
 * CacheItem::expiresAfter() stamps the expiry with the real microtime(): a MockClock created
 * before the call starts at the real now, so sleeping the TTL ± 1 s crosses the expiry.
 */
#[CoversClass(JitaMarketService::class)]
final class JitaMarketServiceOrderBookCacheTest extends TestCase
{
    use CreatesJitaMarketService;

    private const ESI_BASE_URL = 'https://esi.evetech.net/latest';

    private const THE_FORGE = 10000002;
    private const JITA_STATION = 60003760;
    private const PERIMETER_STATION = 60011866;

    private const TRITANIUM = 34;
    private const PYERITE = 35;
    private const MEXALLON = 36;

    private const SELL_ORDER_BOOK_KEY = 'jita_market_prices';
    private const BUY_ORDER_BOOK_KEY = 'jita_market_buy_prices';

    private const ORDER_BOOK_CACHE_SECONDS = 7200;
    private const ON_DEMAND_ORDER_BOOK_CACHE_SECONDS = 300;
    private const DAILY_VOLUME_CACHE_SECONDS = 86400;

    /** @var list<string> */
    private array $requestedUrls = [];

    private MockClock $clock;
    private ArrayAdapter $marketCache;

    protected function setUp(): void
    {
        $this->requestedUrls = [];
        $this->clock = new MockClock();
        $this->marketCache = new ArrayAdapter(clock: $this->clock);
    }

    // ===========================================
    // Order book selection
    // ===========================================

    public function testBuyOrderBookKeepsOnlyJitaStationBuyOrdersWhenJitaHasSome(): void
    {
        $service = $this->createService([
            $this->ordersRoute(self::TRITANIUM) => [[
                $this->buyOrder(4.50, 2000, self::JITA_STATION),
                // Higher bid, but outside Jita station: ignored while Jita has buy orders.
                $this->buyOrder(4.90, 9999, self::PERIMETER_STATION),
                $this->buyOrder(4.70, 100, self::JITA_STATION),
            ]],
        ]);

        $service->refreshPricesForTypes([self::TRITANIUM]);

        $this->assertSame(
            [self::TRITANIUM => [['price' => 4.7, 'volume' => 100], ['price' => 4.5, 'volume' => 2000]]],
            $this->marketCache->getItem(self::BUY_ORDER_BOOK_KEY)->get(),
        );
    }

    public function testBuyOrderBookFallsBackToRegionBuyOrdersWhenJitaHasNone(): void
    {
        $service = $this->createService([
            $this->ordersRoute(self::PYERITE) => [[
                $this->buyOrder(9.00, 800, self::PERIMETER_STATION),
                $this->buyOrder(9.40, 300, self::PERIMETER_STATION),
                $this->sellOrder(10.0, 300, self::JITA_STATION),
            ]],
        ]);

        $service->refreshPricesForTypes([self::PYERITE]);

        $this->assertSame(
            [self::PYERITE => [['price' => 9.4, 'volume' => 300], ['price' => 9.0, 'volume' => 800]]],
            $this->marketCache->getItem(self::BUY_ORDER_BOOK_KEY)->get(),
        );
    }

    public function testOrderBookKeepsTheTwentyBestOrdersOfEachSide(): void
    {
        $orders = [];
        for ($rank = 1; $rank <= 25; ++$rank) {
            $orders[] = $this->sellOrder(5.0 + $rank, 10 * $rank, self::JITA_STATION);
            $orders[] = $this->buyOrder(5.0 - $rank / 10, 10 * $rank, self::JITA_STATION);
        }
        $service = $this->createService([$this->ordersRoute(self::TRITANIUM) => [$orders]]);

        $service->refreshPricesForTypes([self::TRITANIUM]);

        $expectedSellOrderBook = [];
        $expectedBuyOrderBook = [];
        for ($rank = 1; $rank <= 20; ++$rank) {
            $expectedSellOrderBook[] = ['price' => 5.0 + $rank, 'volume' => 10 * $rank];
            $expectedBuyOrderBook[] = ['price' => 5.0 - $rank / 10, 'volume' => 10 * $rank];
        }
        $this->assertSame($expectedSellOrderBook, $service->getSellOrders(self::TRITANIUM));
        $this->assertSame($expectedBuyOrderBook, $service->getBuyOrders(self::TRITANIUM));
    }

    // ===========================================
    // refreshPricesForTypes() — buy merge, lifetime, duration
    // ===========================================

    public function testRefreshMergesFreshBuyOrderBooksIntoTheCachedOnes(): void
    {
        $this->storeInMarketCache(self::BUY_ORDER_BOOK_KEY, [
            self::TRITANIUM => [['price' => 3.0, 'volume' => 10]],
            self::MEXALLON => [['price' => 50.0, 'volume' => 42]],
        ]);
        $service = $this->createService([
            $this->ordersRoute(self::TRITANIUM) => [[$this->buyOrder(4.70, 100, self::JITA_STATION)]],
        ]);

        $service->refreshPricesForTypes([self::TRITANIUM]);

        $this->assertSame([
            self::TRITANIUM => [['price' => 4.7, 'volume' => 100]],
            self::MEXALLON => [['price' => 50.0, 'volume' => 42]],
        ], $this->marketCache->getItem(self::BUY_ORDER_BOOK_KEY)->get());
    }

    public function testRefreshedSellAndBuyOrderBooksExpireAfterTwoHours(): void
    {
        $service = $this->createService([
            $this->ordersRoute(self::TRITANIUM) => [[
                $this->sellOrder(4.95, 500, self::JITA_STATION),
                $this->buyOrder(4.70, 100, self::JITA_STATION),
            ]],
        ]);

        $service->refreshPricesForTypes([self::TRITANIUM]);

        $this->clock->sleep(self::ORDER_BOOK_CACHE_SECONDS - 1);
        $this->assertSame([self::TRITANIUM => 4.95], $service->getPrices([self::TRITANIUM]));
        $this->assertSame([self::TRITANIUM => 4.7], $service->getBuyPrices([self::TRITANIUM]));

        $this->clock->sleep(2);
        $this->assertFalse($service->hasCachedData());
        $this->assertSame([self::TRITANIUM => null], $service->getPrices([self::TRITANIUM]));
        $this->assertSame([self::TRITANIUM => null], $service->getBuyPrices([self::TRITANIUM]));
    }

    public function testRefreshReportsADurationInSecondsBelowOneSecondForAQuickRefresh(): void
    {
        $service = $this->createService([
            $this->ordersRoute(self::TRITANIUM) => [[$this->sellOrder(4.95, 500, self::JITA_STATION)]],
        ]);

        $result = $service->refreshPricesForTypes([self::TRITANIUM]);

        $this->assertTrue($result['success']);
        $this->assertGreaterThanOrEqual(0.0, $result['duration']);
        $this->assertLessThan(1.0, $result['duration']);
    }

    // ===========================================
    // Multi-type reads from the background order books
    // ===========================================

    public function testBuyPricesAreNullForEveryTypeWhenNoBuyOrderBookIsCached(): void
    {
        $service = $this->createService([]);

        $this->assertSame([self::TRITANIUM => null, self::PYERITE => null], $service->getBuyPrices([self::TRITANIUM, self::PYERITE]));
    }

    public function testBuyPricesOfSeveralTypesAreTheHighestBidOfEach(): void
    {
        $this->storeInMarketCache(self::BUY_ORDER_BOOK_KEY, [
            self::TRITANIUM => [['price' => 4.7, 'volume' => 100], ['price' => 4.5, 'volume' => 2000]],
            self::PYERITE => [['price' => 9.4, 'volume' => 300]],
        ]);
        $service = $this->createService([]);

        $this->assertSame(
            [self::TRITANIUM => 4.7, self::PYERITE => 9.4, self::MEXALLON => null],
            $service->getBuyPrices([self::TRITANIUM, self::PYERITE, self::MEXALLON]),
        );
    }

    public function testWeightedSellPricesAreNullForEveryTypeWhenNoSellOrderBookIsCached(): void
    {
        $service = $this->createService([]);

        $this->assertSame(
            [self::TRITANIUM => null, self::PYERITE => null],
            $service->getWeightedSellPrices([self::TRITANIUM => 100, self::PYERITE => 200]),
        );
    }

    public function testWeightedBuyPricesOfSeveralTypesAreAllReturned(): void
    {
        $this->storeInMarketCache(self::BUY_ORDER_BOOK_KEY, [
            self::TRITANIUM => [['price' => 4.7, 'volume' => 100], ['price' => 4.5, 'volume' => 2000]],
            self::PYERITE => [['price' => 9.4, 'volume' => 300]],
        ]);
        $service = $this->createService([]);

        $weightedPrices = $service->getWeightedBuyPrices([self::TRITANIUM => 200, self::PYERITE => 600]);

        // Tritanium: 100 at 4.70 + 100 at 4.50 = 920.0 for 200 units. Pyerite: only 300 of 600 units.
        $this->assertSame([
            self::TRITANIUM => ['weightedPrice' => 4.6, 'coverage' => 1.0, 'ordersUsed' => 2],
            self::PYERITE => ['weightedPrice' => 9.4, 'coverage' => 0.5, 'ordersUsed' => 1],
        ], $weightedPrices);
    }

    public function testWeightedSellPriceOfAnOrderBookWithoutRemainingVolumeIsNull(): void
    {
        $this->storeInMarketCache(self::SELL_ORDER_BOOK_KEY, [
            self::TRITANIUM => [['price' => 4.95, 'volume' => 0], ['price' => 5.1, 'volume' => 0]],
        ]);
        $service = $this->createService([]);

        $this->assertNull($service->getWeightedSellPrice(self::TRITANIUM, 100));
    }

    public function testCheapestPercentilePriceBelowOneUnitIsExactlyTheCheapestSellPrice(): void
    {
        // 10 % of 3 units = 0.3 unit: the cheapest price is returned as is, not recomputed as 0.3 × 60.4 / 0.3.
        $this->storeInMarketCache(self::SELL_ORDER_BOOK_KEY, [
            self::TRITANIUM => [['price' => 60.4, 'volume' => 3]],
        ]);
        $service = $this->createService([]);

        $this->assertSame([self::TRITANIUM => 60.4], $service->getCheapestPercentilePrices([self::TRITANIUM], 0.1));
    }

    // ===========================================
    // On-demand order books (*WithFallback)
    // ===========================================

    public function testOnDemandBuyPricesAreFetchedThenServedFromTheOnDemandCache(): void
    {
        $service = $this->createService([
            $this->ordersRoute(self::TRITANIUM) => [[$this->buyOrder(4.70, 100, self::JITA_STATION)]],
            $this->ordersRoute(self::PYERITE) => [[
                $this->sellOrder(10.0, 300, self::JITA_STATION),
                $this->buyOrder(9.0, 800, self::JITA_STATION),
            ]],
        ]);

        $buyPrices = $service->getBuyPricesWithFallback([self::TRITANIUM, self::PYERITE]);
        $buyPricesAgain = $service->getBuyPricesWithFallback([self::TRITANIUM, self::PYERITE]);

        $this->assertSame([self::TRITANIUM => 4.7, self::PYERITE => 9.0], $buyPrices);
        $this->assertSame([self::TRITANIUM => 4.7, self::PYERITE => 9.0], $buyPricesAgain);
        $this->assertCount(2, $this->requestedUrls);
    }

    public function testOnDemandWeightedSellPricesAreComputedForEveryMissingType(): void
    {
        $service = $this->createService([
            $this->ordersRoute(self::TRITANIUM) => [[$this->sellOrder(4.95, 500, self::JITA_STATION)]],
            $this->ordersRoute(self::PYERITE) => [[$this->sellOrder(10.0, 300, self::JITA_STATION)]],
        ]);

        $weightedPrices = $service->getWeightedSellPricesWithFallback([self::TRITANIUM => 100, self::PYERITE => 600]);

        $this->assertSame([
            self::TRITANIUM => ['weightedPrice' => 4.95, 'coverage' => 1.0, 'ordersUsed' => 1],
            self::PYERITE => ['weightedPrice' => 10.0, 'coverage' => 0.5, 'ordersUsed' => 1],
        ], $weightedPrices);
    }

    public function testOnDemandOrderBookExpiresAfterFiveMinutes(): void
    {
        $service = $this->createService([
            $this->ordersRoute(self::PYERITE) => [
                [$this->sellOrder(10.0, 300, self::JITA_STATION)],
                [$this->sellOrder(10.5, 300, self::JITA_STATION)],
            ],
        ]);

        $service->getPricesWithFallback([self::PYERITE]);

        $this->clock->sleep(self::ON_DEMAND_ORDER_BOOK_CACHE_SECONDS - 1);
        $this->assertSame([self::PYERITE => 10.0], $service->getPricesWithFallback([self::PYERITE]));
        $this->assertCount(1, $this->requestedUrls);

        $this->clock->sleep(2);
        $this->assertSame([self::PYERITE => 10.5], $service->getPricesWithFallback([self::PYERITE]));
        $this->assertCount(2, $this->requestedUrls);
    }

    // ===========================================
    // Daily volumes
    // ===========================================

    public function testCachedDailyVolumeOfAnUnknownTypeIsZero(): void
    {
        $service = $this->createService([]);

        $this->assertSame([self::TRITANIUM => 0.0], $service->getCachedDailyVolumes([self::TRITANIUM]));
    }

    public function testCachedAverageDailyVolumesAreServedPerTypeWithoutEsiRequest(): void
    {
        $this->storeInMarketCache('jita_volume_' . self::TRITANIUM, 155.03);
        $this->storeInMarketCache('jita_volume_' . self::PYERITE, 42.5);
        $service = $this->createService([]);

        $this->assertSame(
            [self::TRITANIUM => 155.03, self::PYERITE => 42.5],
            $service->getAverageDailyVolumes([self::TRITANIUM, self::PYERITE]),
        );
        $this->assertSame([], $this->requestedUrls);
    }

    public function testAverageDailyVolumeExpiresAfterOneDay(): void
    {
        $service = $this->createService([
            $this->historyRoute(self::TRITANIUM) => [
                [$this->historyDay('2026-10-01', 150)],
                [$this->historyDay('2026-10-02', 90)],
            ],
        ]);

        $service->getAverageDailyVolumes([self::TRITANIUM]);

        $this->clock->sleep(self::DAILY_VOLUME_CACHE_SECONDS - 1);
        $this->assertSame([self::TRITANIUM => 150.0], $service->getAverageDailyVolumes([self::TRITANIUM]));
        $this->assertCount(1, $this->requestedUrls);

        $this->clock->sleep(2);
        $this->assertSame([self::TRITANIUM => 0.0], $service->getCachedDailyVolumes([self::TRITANIUM]));
        $this->assertSame([self::TRITANIUM => 90.0], $service->getAverageDailyVolumes([self::TRITANIUM]));
        $this->assertCount(2, $this->requestedUrls);
    }

    // ===========================================
    // Helpers
    // ===========================================

    /**
     * @param array<string, list<list<array<string, mixed>>>> $esiResponsesByRoute bodies served in order, per "<path>#<type_id>"
     */
    private function createService(array $esiResponsesByRoute): JitaMarketService
    {
        $callsByRoute = [];
        $esiHttpClient = new MockHttpClient(function (string $method, string $url) use ($esiResponsesByRoute, &$callsByRoute): MockResponse {
            $this->requestedUrls[] = $url;

            $path = substr((string) parse_url($url, PHP_URL_PATH), strlen('/latest'));
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $route = $path . '#' . ($query['type_id'] ?? '');

            $callIndex = $callsByRoute[$route] ?? 0;
            $callsByRoute[$route] = $callIndex + 1;
            $body = $esiResponsesByRoute[$route][$callIndex] ?? null;
            if ($body === null) {
                $this->fail(sprintf('Unexpected ESI request: %s %s', $method, $url));
            }

            return new MockResponse((string) json_encode($body), [
                'http_code' => 200,
                'response_headers' => ['Content-Type' => 'application/json'],
            ]);
        });

        return $this->createJitaMarketService($esiHttpClient, self::ESI_BASE_URL, $this->marketCache);
    }

    private function ordersRoute(int $typeId): string
    {
        return sprintf('/markets/%d/orders/#%d', self::THE_FORGE, $typeId);
    }

    private function historyRoute(int $typeId): string
    {
        return sprintf('/markets/%d/history/#%d', self::THE_FORGE, $typeId);
    }

    /**
     * @return array<string, mixed>
     */
    private function sellOrder(float $price, int $volumeRemain, int $locationId): array
    {
        return ['price' => $price, 'volume_remain' => $volumeRemain, 'is_buy_order' => false, 'location_id' => $locationId];
    }

    /**
     * @return array<string, mixed>
     */
    private function buyOrder(float $price, int $volumeRemain, int $locationId): array
    {
        return ['price' => $price, 'volume_remain' => $volumeRemain, 'is_buy_order' => true, 'location_id' => $locationId];
    }

    /**
     * @return array<string, mixed>
     */
    private function historyDay(string $date, int $volume): array
    {
        return ['date' => $date, 'order_count' => 50, 'volume' => $volume, 'lowest' => 4.0, 'highest' => 6.0, 'average' => 5.0];
    }

    private function storeInMarketCache(string $key, mixed $value): void
    {
        $cacheItem = $this->marketCache->getItem($key);
        $cacheItem->set($value);
        $this->marketCache->save($cacheItem);
    }
}
