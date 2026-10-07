<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ESI\EsiClient;
use App\Service\JitaMarketService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Issue #26 (slice 4): JitaMarketService reads market orders and market history through
 * EsiClient::getBatch() (ESI_BASE_URL, error-limit tracking, 420/429 replayed once) instead
 * of its own HttpClientInterface and a hard-coded https://esi.evetech.net/latest.
 *
 * Three ESI call sites, all one-page and concurrent per chunk today:
 * - refreshPricesForTypes() / syncJitaMarket(): orders, chunks of 20 types;
 * - *WithFallback() on-demand fetch: orders, chunks of 10 types;
 * - getAverageDailyVolumes*(): market history, chunks of 10 types.
 *
 * ESI is simulated at the HTTP boundary (MockHttpClient). createJitaMarketService() wires the
 * service on whatever constructor it currently has, so the behavior guards are green before
 * and after the switch.
 */
#[CoversClass(JitaMarketService::class)]
final class JitaMarketServiceEsiClientTest extends TestCase
{
    use CreatesJitaMarketService;

    private const PRODUCTION_ESI_BASE_URL = 'https://esi.evetech.net/latest';
    private const CONFIGURED_ESI_BASE_URL = 'https://esi.test/latest';

    private const THE_FORGE = 10000002;
    private const DOMAIN = 10000043;
    private const JITA_STATION = 60003760;
    private const PERIMETER_STATION = 60011866;

    private const TRITANIUM = 34;
    private const PYERITE = 35;
    private const MEXALLON = 36;

    private const SELL_ORDER_BOOK_KEY = 'jita_market_prices';
    private const BUY_ORDER_BOOK_KEY = 'jita_market_buy_prices';

    /** @var list<string> */
    private array $requestedUrls = [];

    /** @var list<string> "request <route>" when a request is launched, "read <route>" when its body is consumed */
    private array $esiEvents = [];

    private ArrayAdapter $marketCache;

    protected function setUp(): void
    {
        $this->requestedUrls = [];
        $this->esiEvents = [];
        $this->marketCache = new ArrayAdapter();
    }

    // ===========================================
    // GREEN guards — refreshPricesForTypes() (background order books)
    // ===========================================

    public function testRefreshPricesForTypesStoresJitaOrderBooksAndFallsBackToTheRegion(): void
    {
        $service = $this->createService([
            $this->ordersRoute(self::TRITANIUM) => [$this->tritaniumOrders()],
            $this->ordersRoute(self::PYERITE) => [[
                // No order in Jita station: the region orders are kept.
                $this->sellOrder(10.0, 300, self::PERIMETER_STATION),
                $this->sellOrder(9.5, 700, self::PERIMETER_STATION),
            ]],
        ], self::PRODUCTION_ESI_BASE_URL);

        $result = $service->refreshPricesForTypes([self::TRITANIUM, self::PYERITE]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['typeCount']);
        $this->assertSame([
            self::TRITANIUM => [['price' => 4.95, 'volume' => 500], ['price' => 5.1, 'volume' => 1000]],
            self::PYERITE => [['price' => 9.5, 'volume' => 700], ['price' => 10.0, 'volume' => 300]],
        ], $this->marketCache->getItem(self::SELL_ORDER_BOOK_KEY)->get());
        $this->assertSame([
            self::TRITANIUM => [['price' => 4.7, 'volume' => 100], ['price' => 4.5, 'volume' => 2000]],
        ], $this->marketCache->getItem(self::BUY_ORDER_BOOK_KEY)->get());
        $this->assertSame([self::TRITANIUM => 4.95, self::PYERITE => 9.5], $service->getPrices([self::TRITANIUM, self::PYERITE]));
    }

    public function testRefreshPricesForTypesMergesFreshOrderBooksIntoTheCachedOnes(): void
    {
        $this->storeInMarketCache(self::SELL_ORDER_BOOK_KEY, [
            self::TRITANIUM => [['price' => 6.0, 'volume' => 10]],
            self::MEXALLON => [['price' => 55.0, 'volume' => 42]],
        ]);
        $service = $this->createService([
            $this->ordersRoute(self::TRITANIUM) => [$this->tritaniumOrders()],
        ], self::PRODUCTION_ESI_BASE_URL);

        $service->refreshPricesForTypes([self::TRITANIUM]);

        $this->assertSame([
            self::TRITANIUM => [['price' => 4.95, 'volume' => 500], ['price' => 5.1, 'volume' => 1000]],
            self::MEXALLON => [['price' => 55.0, 'volume' => 42]],
        ], $this->marketCache->getItem(self::SELL_ORDER_BOOK_KEY)->get());
    }

    public function testRefreshPricesForTypesSkipsATypeWhoseOrdersFailWithoutFailingTheRefresh(): void
    {
        $service = $this->createService([
            $this->ordersRoute(self::TRITANIUM) => [$this->tritaniumOrders()],
            $this->ordersRoute(self::PYERITE) => [new MockResponse('{"error":"Internal server error"}', ['http_code' => 500])],
        ], self::PRODUCTION_ESI_BASE_URL);

        $result = $service->refreshPricesForTypes([self::TRITANIUM, self::PYERITE]);

        $this->assertTrue($result['success']);
        $this->assertSame([self::TRITANIUM => 4.95, self::PYERITE => null], $service->getPrices([self::TRITANIUM, self::PYERITE]));
    }

    public function testOrdersOfARefreshAreAllRequestedBeforeTheFirstOrdersResponseIsRead(): void
    {
        $service = $this->createService([
            $this->ordersRoute(self::TRITANIUM) => [[$this->sellOrder(5.0, 100, self::JITA_STATION)]],
            $this->ordersRoute(self::PYERITE) => [[$this->sellOrder(10.0, 100, self::JITA_STATION)]],
            $this->ordersRoute(self::MEXALLON) => [[$this->sellOrder(55.0, 100, self::JITA_STATION)]],
        ], self::PRODUCTION_ESI_BASE_URL);

        $service->refreshPricesForTypes([self::TRITANIUM, self::PYERITE, self::MEXALLON]);

        $this->assertSame([
            'request ' . $this->ordersRoute(self::TRITANIUM),
            'request ' . $this->ordersRoute(self::PYERITE),
            'request ' . $this->ordersRoute(self::MEXALLON),
        ], array_slice($this->esiEvents, 0, 3));
        $this->assertCount(6, $this->esiEvents);
        $this->assertSame(
            [self::TRITANIUM => 5.0, self::PYERITE => 10.0, self::MEXALLON => 55.0],
            $service->getPrices([self::TRITANIUM, self::PYERITE, self::MEXALLON]),
        );
    }

    public function testCharacterizationOnlyTheFirstPageOfOrdersIsReadEvenWhenEsiAnnouncesMorePages(): void
    {
        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #26.
        // /markets/{region}/orders/ is paginated (X-Pages) but only page 1 is read; orders of
        // later pages are ignored. Switching to getBatch() keeps this; getPaginated() would change it.
        $service = $this->createService([
            $this->ordersRoute(self::TRITANIUM) => [
                $this->jsonResponse([$this->sellOrder(5.0, 100, self::JITA_STATION)], ['X-Pages' => '2']),
            ],
        ], self::PRODUCTION_ESI_BASE_URL);

        $service->refreshPricesForTypes([self::TRITANIUM]);

        $this->assertCount(1, $this->requestedUrls);
        $this->assertStringNotContainsString('page=', $this->requestedUrls[0]);
        $this->assertSame([self::TRITANIUM => [['price' => 5.0, 'volume' => 100]]], $this->marketCache->getItem(self::SELL_ORDER_BOOK_KEY)->get());
    }

    // ===========================================
    // GREEN guards — on-demand order books (*WithFallback)
    // ===========================================

    public function testGetPricesWithFallbackFetchesOnlyTheUncachedTypeAndCachesItsOrderBook(): void
    {
        $this->storeInMarketCache(self::SELL_ORDER_BOOK_KEY, [self::TRITANIUM => [['price' => 4.95, 'volume' => 500]]]);
        $service = $this->createService([
            $this->ordersRoute(self::PYERITE) => [[
                $this->sellOrder(10.0, 300, self::JITA_STATION),
                $this->buyOrder(9.0, 800, self::JITA_STATION),
            ]],
        ], self::PRODUCTION_ESI_BASE_URL);

        $prices = $service->getPricesWithFallback([self::TRITANIUM, self::PYERITE]);
        // Second call: served from the on-demand cache, no new ESI request.
        $pricesAgain = $service->getPricesWithFallback([self::TRITANIUM, self::PYERITE]);

        $this->assertSame([self::TRITANIUM => 4.95, self::PYERITE => 10.0], $prices);
        $this->assertSame([self::TRITANIUM => 4.95, self::PYERITE => 10.0], $pricesAgain);
        $this->assertSame([
            'sell' => [['price' => 10.0, 'volume' => 300]],
            'buy' => [['price' => 9.0, 'volume' => 800]],
        ], $this->marketCache->getItem('jita_ondemand_' . self::PYERITE)->get());
        $this->assertSame([self::PRODUCTION_ESI_BASE_URL . '/markets/10000002/orders/?order_type=all&type_id=35'], $this->requestedUrls);
    }

    public function testGetWeightedSellPricesWithFallbackComputesTheWeightedPriceFromOnDemandOrders(): void
    {
        $service = $this->createService([
            $this->ordersRoute(self::TRITANIUM) => [$this->tritaniumOrders()],
        ], self::PRODUCTION_ESI_BASE_URL);

        $weightedPrices = $service->getWeightedSellPricesWithFallback([self::TRITANIUM => 1000]);

        // 500 units at 4.95 + 500 units at 5.10 = 5025.0 for 1000 units.
        $this->assertSame([self::TRITANIUM => ['weightedPrice' => 5.025, 'coverage' => 1.0, 'ordersUsed' => 2]], $weightedPrices);
    }

    public function testOnDemandTypeWhoseOrdersFailHasNoPriceAndIsNotCached(): void
    {
        $service = $this->createService([
            $this->ordersRoute(self::PYERITE) => [new MockResponse('{"error":"Internal server error"}', ['http_code' => 500])],
            $this->ordersRoute(self::MEXALLON) => [[$this->sellOrder(55.0, 42, self::JITA_STATION)]],
        ], self::PRODUCTION_ESI_BASE_URL);

        $prices = $service->getPricesWithFallback([self::PYERITE, self::MEXALLON]);

        $this->assertSame([self::PYERITE => null, self::MEXALLON => 55.0], $prices);
        $this->assertFalse($this->marketCache->getItem('jita_ondemand_' . self::PYERITE)->isHit());
    }

    // ===========================================
    // GREEN guards — average daily volumes (market history)
    // ===========================================

    public function testGetAverageDailyVolumesAveragesTheLast30DaysAndCachesThem(): void
    {
        $service = $this->createService([
            $this->historyRoute(self::THE_FORGE, self::TRITANIUM) => [$this->thirtyTwoDaysOfTritaniumHistory()],
            $this->historyRoute(self::THE_FORGE, self::PYERITE) => [[]],
        ], self::PRODUCTION_ESI_BASE_URL);

        $dailyVolumes = $service->getAverageDailyVolumes([self::TRITANIUM, self::PYERITE]);

        // Last 30 days: 10 + 20 + ... + 300 + 1 = 4651, / 30 = 155.03.
        $this->assertSame([self::TRITANIUM => 155.03, self::PYERITE => 0.0], $dailyVolumes);
        $this->assertSame(155.03, $this->marketCache->getItem('jita_volume_' . self::TRITANIUM)->get());
        $this->assertSame([self::TRITANIUM => 155.03, self::PYERITE => 0.0], $service->getCachedDailyVolumes([self::TRITANIUM, self::PYERITE]));
    }

    public function testGetAverageDailyVolumesForRegionReadsThatRegionAndCachesPerRegion(): void
    {
        $service = $this->createService([
            $this->historyRoute(self::DOMAIN, self::TRITANIUM) => [$this->thirtyTwoDaysOfTritaniumHistory()],
        ], self::PRODUCTION_ESI_BASE_URL);

        $dailyVolumes = $service->getAverageDailyVolumesForRegion(self::DOMAIN, [self::TRITANIUM]);

        $this->assertSame([self::TRITANIUM => 155.03], $dailyVolumes);
        $this->assertSame(155.03, $this->marketCache->getItem('volume_10000043_34')->get());
        $this->assertFalse($this->marketCache->getItem('jita_volume_' . self::TRITANIUM)->isHit());
        $this->assertSame([self::PRODUCTION_ESI_BASE_URL . '/markets/10000043/history/?type_id=34'], $this->requestedUrls);
    }

    public function testTypeWhoseHistoryFailsIsLeftOutOfTheDailyVolumesAndNotCached(): void
    {
        $service = $this->createService([
            $this->historyRoute(self::THE_FORGE, self::TRITANIUM) => [$this->thirtyTwoDaysOfTritaniumHistory()],
            $this->historyRoute(self::THE_FORGE, self::PYERITE) => [new MockResponse('{"error":"Internal server error"}', ['http_code' => 500])],
        ], self::PRODUCTION_ESI_BASE_URL);

        $dailyVolumes = $service->getAverageDailyVolumes([self::TRITANIUM, self::PYERITE]);

        $this->assertSame([self::TRITANIUM => 155.03], $dailyVolumes);
        $this->assertFalse($this->marketCache->getItem('jita_volume_' . self::PYERITE)->isHit());
    }

    // ===========================================
    // RED — issue #26: ESI must be read through EsiClient
    // ===========================================

    public function testConstructorTakesAnEsiClientNamedEsiClientInsteadOfHttpClient(): void
    {
        $constructor = (new \ReflectionClass(JitaMarketService::class))->getConstructor();
        $this->assertNotNull($constructor);
        $parameterTypesByName = [];
        foreach ($constructor->getParameters() as $parameter) {
            $parameterTypesByName[$parameter->getName()] = (string) $parameter->getType();
        }

        $this->assertNotContains(HttpClientInterface::class, $parameterTypesByName);
        $this->assertSame(EsiClient::class, $parameterTypesByName['esiClient'] ?? null);
    }

    public function testOrdersAndHistoryAreReadFromTheConfiguredEsiBaseUrl(): void
    {
        $service = $this->createService([
            $this->ordersRoute(self::TRITANIUM) => [$this->tritaniumOrders()],
            $this->ordersRoute(self::PYERITE) => [[$this->sellOrder(10.0, 300, self::JITA_STATION)]],
            $this->historyRoute(self::THE_FORGE, self::MEXALLON) => [$this->thirtyTwoDaysOfTritaniumHistory()],
        ], self::CONFIGURED_ESI_BASE_URL);

        $service->refreshPricesForTypes([self::TRITANIUM]);
        $onDemandPrices = $service->getPricesWithFallback([self::PYERITE]);
        $dailyVolumes = $service->getAverageDailyVolumes([self::MEXALLON]);

        $this->assertSame(4.95, $service->getPrice(self::TRITANIUM));
        $this->assertSame([self::PYERITE => 10.0], $onDemandPrices);
        $this->assertSame([self::MEXALLON => 155.03], $dailyVolumes);
        $this->assertSame([
            self::CONFIGURED_ESI_BASE_URL . '/markets/10000002/orders/?order_type=all&type_id=34',
            self::CONFIGURED_ESI_BASE_URL . '/markets/10000002/orders/?order_type=all&type_id=35',
            self::CONFIGURED_ESI_BASE_URL . '/markets/10000002/history/?type_id=36',
        ], $this->requestedUrls);
    }

    public function testRateLimitedOrdersOfARefreshAreRetriedOnceAndStored(): void
    {
        $service = $this->createService([
            $this->ordersRoute(self::TRITANIUM) => [$this->rateLimitedResponse(), $this->tritaniumOrders()],
            $this->ordersRoute(self::PYERITE) => [[$this->sellOrder(10.0, 300, self::JITA_STATION)]],
        ], self::PRODUCTION_ESI_BASE_URL);

        $service->refreshPricesForTypes([self::TRITANIUM, self::PYERITE]);

        $this->assertSame([self::TRITANIUM => 4.95, self::PYERITE => 10.0], $service->getPrices([self::TRITANIUM, self::PYERITE]));
        $this->assertSame([self::TRITANIUM => 4.7], $service->getBuyPrices([self::TRITANIUM]));
        $this->assertCount(3, $this->requestedUrls);
    }

    public function testRateLimitedOnDemandOrdersAreRetriedOnceAndCached(): void
    {
        $service = $this->createService([
            $this->ordersRoute(self::PYERITE) => [$this->rateLimitedResponse(), [$this->sellOrder(10.0, 300, self::JITA_STATION)]],
        ], self::PRODUCTION_ESI_BASE_URL);

        $prices = $service->getPricesWithFallback([self::PYERITE]);

        $this->assertSame([self::PYERITE => 10.0], $prices);
        $this->assertSame(
            ['sell' => [['price' => 10.0, 'volume' => 300]], 'buy' => []],
            $this->marketCache->getItem('jita_ondemand_' . self::PYERITE)->get(),
        );
        $this->assertCount(2, $this->requestedUrls);
    }

    public function testRateLimitedHistoryIsRetriedOnceAndCached(): void
    {
        $service = $this->createService([
            $this->historyRoute(self::THE_FORGE, self::TRITANIUM) => [$this->rateLimitedResponse(), $this->thirtyTwoDaysOfTritaniumHistory()],
        ], self::PRODUCTION_ESI_BASE_URL);

        $dailyVolumes = $service->getAverageDailyVolumes([self::TRITANIUM]);

        $this->assertSame([self::TRITANIUM => 155.03], $dailyVolumes);
        $this->assertSame(155.03, $this->marketCache->getItem('jita_volume_' . self::TRITANIUM)->get());
        $this->assertCount(2, $this->requestedUrls);
    }

    public function testOrdersAndHistoryAreReadWithEsiClientGetBatchKeyedByTypeIdInChunks(): void
    {
        /** @var list<array{array<int|string, string>, mixed}> $esiBatches */
        $esiBatches = [];
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getBatch')
            ->willReturnCallback(function (array $endpoints, mixed $token = null) use (&$esiBatches): array {
                $esiBatches[] = [$endpoints, $token];
                $results = [];
                foreach ($endpoints as $typeId => $endpoint) {
                    $results[$typeId] = match (true) {
                        // A failed key comes back null from getBatch(): that type is skipped.
                        $typeId === self::PYERITE => null,
                        str_contains($endpoint, '/history/') => [['volume' => 90], ['volume' => 110]],
                        default => [$this->sellOrder((float) $typeId, 100, self::JITA_STATION)],
                    };
                }

                return $results;
            });
        $esiClient->method('get')->willThrowException(new \LogicException('Market data must be read with getBatch()'));
        $esiClient->method('getPaginated')->willThrowException(new \LogicException('Market data must be read with getBatch()'));

        $service = new JitaMarketService(
            esiClient: $esiClient,
            cache: $this->marketCache,
            connection: $this->createStub(Connection::class),
            logger: new NullLogger(),
        );

        $refreshedTypeIds = range(self::TRITANIUM, self::TRITANIUM + 20); // 21 types: chunks of 20 + 1
        $service->refreshPricesForTypes($refreshedTypeIds);
        $onDemandTypeIds = range(1000, 1010); // 11 types: chunks of 10 + 1
        $onDemandPrices = $service->getPricesWithFallback($onDemandTypeIds);
        $dailyVolumes = $service->getAverageDailyVolumes([self::TRITANIUM, self::PYERITE]);

        $this->assertSame(
            [20, 1, 10, 1, 2],
            array_map(static fn (array $esiBatch): int => count($esiBatch[0]), $esiBatches),
        );
        $this->assertSame([null, null, null, null, null], array_column($esiBatches, 1));
        $this->assertSame(range(self::TRITANIUM, self::TRITANIUM + 19), array_keys($esiBatches[0][0]));
        $this->assertSame('/markets/10000002/orders/?order_type=all&type_id=34', $esiBatches[0][0][self::TRITANIUM]);
        $this->assertSame([1010 => '/markets/10000002/orders/?order_type=all&type_id=1010'], $esiBatches[3][0]);
        $this->assertSame([
            self::TRITANIUM => '/markets/10000002/history/?type_id=34',
            self::PYERITE => '/markets/10000002/history/?type_id=35',
        ], $esiBatches[4][0]);

        $this->assertSame([self::TRITANIUM => 34.0, self::PYERITE => null, 54 => 54.0], $service->getPrices([self::TRITANIUM, self::PYERITE, 54]));
        $this->assertSame(1010.0, $onDemandPrices[1010]);
        $this->assertSame([self::TRITANIUM => 100.0], $dailyVolumes);
    }

    // ===========================================
    // Helpers
    // ===========================================

    /**
     * @param array<string, list<list<array<string, mixed>>|MockResponse>> $esiResponsesByRoute
     */
    private function createService(array $esiResponsesByRoute, string $esiBaseUrl): JitaMarketService
    {
        return $this->createJitaMarketService(
            $this->createSimulatedEsi($esiResponsesByRoute, $esiBaseUrl),
            $esiBaseUrl,
            $this->marketCache,
        );
    }

    /**
     * Routes each request on "<path>#<type_id>"; the responses of a route are served in order.
     * Unknown host or route: 404.
     *
     * @param array<string, list<list<array<string, mixed>>|MockResponse>> $esiResponsesByRoute
     */
    private function createSimulatedEsi(array $esiResponsesByRoute, string $esiBaseUrl): MockHttpClient
    {
        $callsByRoute = [];

        return new MockHttpClient(function (string $method, string $url) use ($esiResponsesByRoute, $esiBaseUrl, &$callsByRoute): MockResponse {
            $this->requestedUrls[] = $url;

            if (!str_starts_with($url, $esiBaseUrl . '/')) {
                return new MockResponse('{"error":"Unknown host"}', ['http_code' => 404]);
            }
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $route = substr($path, strlen((string) parse_url($esiBaseUrl, PHP_URL_PATH))) . '#' . ($query['type_id'] ?? '');
            $this->esiEvents[] = 'request ' . $route;

            $callIndex = $callsByRoute[$route] ?? 0;
            $callsByRoute[$route] = $callIndex + 1;
            $response = $esiResponsesByRoute[$route][$callIndex] ?? null;

            if ($response === null) {
                return new MockResponse('{"error":"Not found"}', ['http_code' => 404]);
            }

            return $response instanceof MockResponse ? $response : $this->readTrackedJsonResponse($route, $response);
        });
    }

    private function ordersRoute(int $typeId): string
    {
        return sprintf('/markets/%d/orders/#%d', self::THE_FORGE, $typeId);
    }

    private function historyRoute(int $regionId, int $typeId): string
    {
        return sprintf('/markets/%d/history/#%d', $regionId, $typeId);
    }

    /**
     * Jita sells 500 @ 4.95 and 1000 @ 5.10, buys 2000 @ 4.50 and 100 @ 4.70.
     * A cheaper sell order outside Jita station is ignored because Jita has orders.
     *
     * @return list<array<string, mixed>>
     */
    private function tritaniumOrders(): array
    {
        return [
            $this->sellOrder(5.10, 1000, self::JITA_STATION),
            $this->sellOrder(4.00, 9999, self::PERIMETER_STATION),
            $this->buyOrder(4.50, 2000, self::JITA_STATION),
            $this->sellOrder(4.95, 500, self::JITA_STATION),
            $this->buyOrder(4.70, 100, self::JITA_STATION),
        ];
    }

    /**
     * Two old days at 1,000,000 (outside the 30-day window), then 30 days at 10, 20, ... 300,
     * the last one at 301.
     *
     * @return list<array<string, mixed>>
     */
    private function thirtyTwoDaysOfTritaniumHistory(): array
    {
        $history = [];
        $day = new \DateTimeImmutable('2026-09-01');
        foreach ([1_000_000, 1_000_000] as $volume) {
            $history[] = ['date' => $day->format('Y-m-d'), 'order_count' => 50, 'volume' => $volume, 'lowest' => 4.0, 'highest' => 6.0, 'average' => 5.0];
            $day = $day->modify('+1 day');
        }
        for ($i = 1; $i <= 30; ++$i) {
            $volume = $i * 10 + ($i === 30 ? 1 : 0);
            $history[] = ['date' => $day->format('Y-m-d'), 'order_count' => 50, 'volume' => $volume, 'lowest' => 4.0, 'highest' => 6.0, 'average' => 5.0];
            $day = $day->modify('+1 day');
        }

        return $history;
    }

    /**
     * @return array<string, mixed>
     */
    private function sellOrder(float $price, int $volumeRemain, int $locationId): array
    {
        return ['price' => $price, 'volume_remain' => $volumeRemain, 'is_buy_order' => false, 'location_id' => $locationId, 'type_id' => self::TRITANIUM];
    }

    /**
     * @return array<string, mixed>
     */
    private function buyOrder(float $price, int $volumeRemain, int $locationId): array
    {
        return ['price' => $price, 'volume_remain' => $volumeRemain, 'is_buy_order' => true, 'location_id' => $locationId, 'type_id' => self::TRITANIUM];
    }

    private function rateLimitedResponse(): MockResponse
    {
        return new MockResponse('{"error":"Too many requests"}', ['http_code' => 429, 'response_headers' => ['Retry-After' => '0']]);
    }

    private function storeInMarketCache(string $key, mixed $value): void
    {
        $cacheItem = $this->marketCache->getItem($key);
        $cacheItem->set($value);
        $this->marketCache->save($cacheItem);
    }

    /**
     * A 200 JSON response that logs "read <route>" in $esiEvents when its body is consumed.
     *
     * @param array<mixed> $body
     */
    private function readTrackedJsonResponse(string $route, array $body): MockResponse
    {
        $bodyChunks = (function () use ($route, $body): \Generator {
            $this->esiEvents[] = 'read ' . $route;
            yield (string) json_encode($body);
        })();

        return new MockResponse($bodyChunks, [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    /**
     * @param array<mixed> $body
     * @param array<string, string> $headers
     */
    private function jsonResponse(array $body, array $headers = []): MockResponse
    {
        return new MockResponse((string) json_encode($body), [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'application/json', ...$headers],
        ]);
    }
}
