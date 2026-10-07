<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Service\ESI\EsiClient;
use App\Service\ESI\MarketService;
use App\Service\ESI\TokenManager;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Issue #26: the on-demand Jita price fetch must read ESI through EsiClient::getBatch()
 * (ESI_BASE_URL, error-limit tracking, 420/429 retry) instead of a private HttpClientInterface
 * and a hard-coded ESI URL.
 *
 * ESI is simulated at the HTTP boundary (MockHttpClient), the market cache is a real ArrayAdapter.
 * createService() wires the service on whatever constructor it currently has, so the behavior
 * guards stay green before and after the switch.
 */
#[CoversClass(MarketService::class)]
#[AllowMockObjectsWithoutExpectations]
final class MarketServiceTest extends TestCase
{
    private const PRODUCTION_ESI_BASE_URL = 'https://esi.evetech.net/latest';
    private const CONFIGURED_ESI_BASE_URL = 'https://esi.test/latest';

    private const JITA_STATION_ID = 60003760;
    private const PERIMETER_STATION_ID = 60004588;

    private const TRITANIUM = 34;
    private const PYERITE = 35;
    private const MEXALLON = 36;
    private const ISOGEN = 37;

    private const JITA_PRICE_CACHE_SECONDS = 300;

    /** @var list<string> */
    private array $requestedUrls = [];

    protected function setUp(): void
    {
        $this->requestedUrls = [];
    }

    // ===========================================
    // getJitaPrices() — GREEN guards (current behavior to keep)
    // ===========================================

    public function testJitaPriceIsLowestSellOrderPriceAtJitaStation(): void
    {
        $service = $this->createService([
            $this->sellOrdersPath(self::TRITANIUM) => [$this->jsonResponse([
                $this->order(self::JITA_STATION_ID, 5.40, isBuyOrder: false),
                $this->order(self::JITA_STATION_ID, 5.12, isBuyOrder: false),
                // Cheaper, but outside the Jita station: ignored while a Jita sell order exists.
                $this->order(self::PERIMETER_STATION_ID, 4.90, isBuyOrder: false),
                // Buy order: never a sell price.
                $this->order(self::JITA_STATION_ID, 3.00, isBuyOrder: true),
            ])],
        ]);

        $prices = $service->getJitaPrices([self::TRITANIUM]);

        $this->assertSame([self::TRITANIUM => 5.12], $prices);
        $this->assertSame([
            self::PRODUCTION_ESI_BASE_URL . '/markets/10000002/orders/?order_type=sell&type_id=34',
        ], $this->requestedUrls);
    }

    public function testJitaPriceFallsBackToLowestRegionSellOrderWhenNoneAtJitaStation(): void
    {
        $service = $this->createService([
            $this->sellOrdersPath(self::PYERITE) => [$this->jsonResponse([
                $this->order(self::PERIMETER_STATION_ID, 11.75, isBuyOrder: false),
                $this->order(self::PERIMETER_STATION_ID, 11.20, isBuyOrder: false),
                $this->order(self::JITA_STATION_ID, 9.00, isBuyOrder: true),
            ])],
        ]);

        $this->assertSame([self::PYERITE => 11.2], $service->getJitaPrices([self::PYERITE]));
    }

    public function testJitaPricesOfSeveralTypesAreReturnedUnderTheirTypeId(): void
    {
        $service = $this->createService([
            $this->sellOrdersPath(self::TRITANIUM) => [$this->jsonResponse([$this->order(self::JITA_STATION_ID, 5.12, isBuyOrder: false)])],
            $this->sellOrdersPath(self::PYERITE) => [$this->jsonResponse([$this->order(self::JITA_STATION_ID, 11.75, isBuyOrder: false)])],
            $this->sellOrdersPath(self::MEXALLON) => [$this->jsonResponse([$this->order(self::JITA_STATION_ID, 60.4, isBuyOrder: false)])],
        ]);

        $prices = $service->getJitaPrices([self::MEXALLON, self::TRITANIUM, self::PYERITE]);

        $this->assertSame([self::MEXALLON => 60.4, self::TRITANIUM => 5.12, self::PYERITE => 11.75], $prices);
    }

    public function testMoreThanTenUncachedTypesAreAllPriced(): void
    {
        $typeIds = range(1001, 1012);
        $esiResponsesByPath = [];
        $expectedPrices = [];
        foreach ($typeIds as $typeId) {
            $esiResponsesByPath[$this->sellOrdersPath($typeId)] = [$this->jsonResponse([
                $this->order(self::JITA_STATION_ID, $typeId / 10.0, isBuyOrder: false),
            ])];
            $expectedPrices[$typeId] = $typeId / 10.0;
        }
        $service = $this->createService($esiResponsesByPath);

        $this->assertSame($expectedPrices, $service->getJitaPrices($typeIds));
        $this->assertCount(12, $this->requestedUrls);
    }

    public function testJitaPriceIsCachedForFiveMinutesUnderItsTypeId(): void
    {
        // CacheItem::expiresAfter() stamps the expiry with the real microtime(): the clock starts at the real now.
        $clock = new MockClock();
        $marketCache = new ArrayAdapter(clock: $clock);
        $service = $this->createService([
            $this->sellOrdersPath(self::TRITANIUM) => [
                $this->jsonResponse([$this->order(self::JITA_STATION_ID, 5.12, isBuyOrder: false)]),
                $this->jsonResponse([$this->order(self::JITA_STATION_ID, 5.30, isBuyOrder: false)]),
            ],
        ], marketCache: $marketCache);

        $service->getJitaPrices([self::TRITANIUM]);
        $this->assertSame(5.12, $marketCache->getItem('market_jita_34')->get());

        $clock->sleep(self::JITA_PRICE_CACHE_SECONDS - 1);
        $this->assertSame([self::TRITANIUM => 5.12], $service->getJitaPrices([self::TRITANIUM]));
        $this->assertCount(1, $this->requestedUrls);

        $clock->sleep(2);
        $this->assertSame([self::TRITANIUM => 5.3], $service->getJitaPrices([self::TRITANIUM]));
        $this->assertCount(2, $this->requestedUrls);
    }

    public function testCachedJitaPriceIsServedWithoutEsiRequestAndOnlyUncachedTypesAreFetched(): void
    {
        $marketCache = new ArrayAdapter();
        $cachedTritanium = $marketCache->getItem('market_jita_34');
        $cachedTritanium->set(5.12);
        $marketCache->save($cachedTritanium);
        $service = $this->createService([
            $this->sellOrdersPath(self::PYERITE) => [$this->jsonResponse([$this->order(self::JITA_STATION_ID, 11.75, isBuyOrder: false)])],
        ], marketCache: $marketCache);

        $prices = $service->getJitaPrices([self::TRITANIUM, self::PYERITE]);

        $this->assertSame([self::TRITANIUM => 5.12, self::PYERITE => 11.75], $prices);
        $this->assertSame([
            self::PRODUCTION_ESI_BASE_URL . '/markets/10000002/orders/?order_type=sell&type_id=35',
        ], $this->requestedUrls);
    }

    public function testFailedTypesStayNullAndUncachedWithoutFailingTheOthers(): void
    {
        $marketCache = new ArrayAdapter();
        $service = $this->createService([
            $this->sellOrdersPath(self::TRITANIUM) => [new MockResponse('{"error":"Type not found"}', ['http_code' => 404])],
            $this->sellOrdersPath(self::PYERITE) => [$this->jsonResponse([$this->order(self::JITA_STATION_ID, 11.75, isBuyOrder: false)])],
            $this->sellOrdersPath(self::MEXALLON) => [new MockResponse('{"error":"Internal server error"}', ['http_code' => 500])],
            $this->sellOrdersPath(self::ISOGEN) => [new MockResponse('', ['error' => 'Connection reset by peer'])],
        ], marketCache: $marketCache);

        $prices = $service->getJitaPrices([self::TRITANIUM, self::PYERITE, self::MEXALLON, self::ISOGEN]);

        $this->assertSame([
            self::TRITANIUM => null,
            self::PYERITE => 11.75,
            self::MEXALLON => null,
            self::ISOGEN => null,
        ], $prices);
        $this->assertFalse($marketCache->getItem('market_jita_34')->isHit());
        $this->assertTrue($marketCache->getItem('market_jita_35')->isHit());
        $this->assertFalse($marketCache->getItem('market_jita_36')->isHit());
        $this->assertFalse($marketCache->getItem('market_jita_37')->isHit());
        // 404, 5xx and network errors are not retried.
        $this->assertCount(4, $this->requestedUrls);
    }

    public function testTypeWithoutSellOrderGetsNullPriceWhichIsCached(): void
    {
        $marketCache = new ArrayAdapter();
        $service = $this->createService([
            $this->sellOrdersPath(self::TRITANIUM) => [$this->jsonResponse([])],
        ], marketCache: $marketCache);

        $this->assertSame([self::TRITANIUM => null], $service->getJitaPrices([self::TRITANIUM]));

        $cachedTritanium = $marketCache->getItem('market_jita_34');
        $this->assertTrue($cachedTritanium->isHit());
        $this->assertNull($cachedTritanium->get());
    }

    public function testJitaPriceReadsOnlyTheFirstPageOfRegionSellOrders(): void
    {
        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #26.
        // X-Pages is ignored: a cheaper sell order on page 2 is never seen. getBatch() keeps this limit.
        $service = $this->createService([
            $this->sellOrdersPath(self::TRITANIUM) => [$this->jsonResponse(
                [$this->order(self::JITA_STATION_ID, 5.12, isBuyOrder: false)],
                ['X-Pages' => '2'],
            )],
        ]);

        $this->assertSame([self::TRITANIUM => 5.12], $service->getJitaPrices([self::TRITANIUM]));
        $this->assertSame([
            self::PRODUCTION_ESI_BASE_URL . '/markets/10000002/orders/?order_type=sell&type_id=34',
        ], $this->requestedUrls);
    }

    public function testJitaMarketServiceCacheIsUsedWithoutEsiRequest(): void
    {
        $jitaMarketService = $this->createMock(JitaMarketService::class);
        $jitaMarketService->method('hasCachedData')->willReturn(true);
        $jitaMarketService->expects($this->once())
            ->method('getPricesWithFallback')
            ->with([self::TRITANIUM, self::PYERITE])
            ->willReturn([self::TRITANIUM => 5.12, self::PYERITE => null]);
        $service = $this->createService([], jitaMarketService: $jitaMarketService);

        $prices = $service->getJitaPrices([self::TRITANIUM, self::PYERITE]);

        $this->assertSame([self::TRITANIUM => 5.12, self::PYERITE => null], $prices);
        $this->assertSame([], $this->requestedUrls);
    }

    public function testNoTypeIdsReturnsNoPriceWithoutEsiRequest(): void
    {
        $service = $this->createService([]);

        $this->assertSame([], $service->getJitaPrices([]));
        $this->assertSame([], $this->requestedUrls);
    }

    // ===========================================
    // getJitaPrices() — lowest sell price selection, whatever the order of the ESI orders
    // ===========================================

    public function testCheapestJitaSellOrderListedBeforeAPricierOneIsTheJitaPrice(): void
    {
        $service = $this->createService([
            $this->sellOrdersPath(self::TRITANIUM) => [$this->jsonResponse([
                $this->order(self::JITA_STATION_ID, 5.12, isBuyOrder: false),
                $this->order(self::JITA_STATION_ID, 5.40, isBuyOrder: false),
                $this->order(self::JITA_STATION_ID, 5.25, isBuyOrder: false),
            ])],
        ]);

        $this->assertSame([self::TRITANIUM => 5.12], $service->getJitaPrices([self::TRITANIUM]));
    }

    public function testCheapestRegionSellOrderListedBeforeAPricierOneIsTheFallbackPrice(): void
    {
        $service = $this->createService([
            $this->sellOrdersPath(self::PYERITE) => [$this->jsonResponse([
                $this->order(self::PERIMETER_STATION_ID, 11.20, isBuyOrder: false),
                $this->order(self::PERIMETER_STATION_ID, 11.75, isBuyOrder: false),
                $this->order(self::PERIMETER_STATION_ID, 11.50, isBuyOrder: false),
            ])],
        ]);

        $this->assertSame([self::PYERITE => 11.2], $service->getJitaPrices([self::PYERITE]));
    }

    public function testJitaPricesAllServedFromCacheAreAllReturned(): void
    {
        $marketCache = new ArrayAdapter();
        foreach ([self::TRITANIUM => 5.12, self::PYERITE => 11.75, self::MEXALLON => 60.4] as $typeId => $price) {
            $cachedPrice = $marketCache->getItem("market_jita_{$typeId}");
            $cachedPrice->set($price);
            $marketCache->save($cachedPrice);
        }
        $service = $this->createService([], marketCache: $marketCache);

        $prices = $service->getJitaPrices([self::TRITANIUM, self::PYERITE, self::MEXALLON]);

        $this->assertSame([self::TRITANIUM => 5.12, self::PYERITE => 11.75, self::MEXALLON => 60.4], $prices);
        $this->assertSame([], $this->requestedUrls);
    }

    public function testJitaPriceIsStillCachedHalfASecondBeforeFiveMinutes(): void
    {
        // CacheItem::expiresAfter() stamps the expiry with the real microtime(): the clock starts at the real now.
        $clock = new MockClock();
        $marketCache = new ArrayAdapter(clock: $clock);
        $service = $this->createService([
            $this->sellOrdersPath(self::TRITANIUM) => [$this->jsonResponse([$this->order(self::JITA_STATION_ID, 5.12, isBuyOrder: false)])],
        ], marketCache: $marketCache);

        $service->getJitaPrices([self::TRITANIUM]);
        $clock->sleep(self::JITA_PRICE_CACHE_SECONDS - 0.5);

        $this->assertTrue($marketCache->getItem('market_jita_34')->isHit());
        $this->assertSame([self::TRITANIUM => 5.12], $service->getJitaPrices([self::TRITANIUM]));
        $this->assertCount(1, $this->requestedUrls);
    }

    public function testFailedJitaPriceFetchIsReportedWithItsTypeId(): void
    {
        // A failed fetch leaves a null price, like a type without sell order: the warning is the only trace of the ESI failure.
        $logger = new BufferingLogger();
        $service = $this->createService([
            $this->sellOrdersPath(self::TRITANIUM) => [new MockResponse('{"error":"Internal server error"}', ['http_code' => 500])],
            $this->sellOrdersPath(self::PYERITE) => [$this->jsonResponse([$this->order(self::JITA_STATION_ID, 11.75, isBuyOrder: false)])],
        ], logger: $logger);

        $service->getJitaPrices([self::TRITANIUM, self::PYERITE]);

        $warnings = array_values(array_filter(
            $logger->cleanLogs(),
            static fn (array $log): bool => $log[0] === LogLevel::WARNING,
        ));
        $this->assertSame([[LogLevel::WARNING, 'Failed to fetch Jita price', ['typeId' => self::TRITANIUM]]], $warnings);
    }

    // ===========================================
    // RED — issue #26: ESI must be read through EsiClient::getBatch()
    // ===========================================

    public function testConstructorTakesEsiClientWithoutHttpClientNorEsiBaseUrl(): void
    {
        $parameterTypesByName = $this->constructorParameterTypesByName();

        $this->assertSame(EsiClient::class, $parameterTypesByName['esiClient'] ?? null);
        $this->assertNotContains(HttpClientInterface::class, $parameterTypesByName, 'ESI is read through EsiClient, not a private HTTP client.');
        $this->assertArrayNotHasKey('esiBaseUrl', $parameterTypesByName, 'The ESI base URL comes from EsiClient (ESI_BASE_URL).');
    }

    public function testJitaPricesAreRequestedFromTheConfiguredEsiBaseUrl(): void
    {
        $service = $this->createService([
            $this->sellOrdersPath(self::TRITANIUM) => [$this->jsonResponse([$this->order(self::JITA_STATION_ID, 5.12, isBuyOrder: false)])],
        ], esiBaseUrl: self::CONFIGURED_ESI_BASE_URL);

        $prices = $service->getJitaPrices([self::TRITANIUM]);

        $this->assertSame([self::TRITANIUM => 5.12], $prices);
        $this->assertSame([
            self::CONFIGURED_ESI_BASE_URL . '/markets/10000002/orders/?order_type=sell&type_id=34',
        ], $this->requestedUrls);
    }

    public function testRateLimitedTypeIsRetriedOnceAndPriced(): void
    {
        $marketCache = new ArrayAdapter();
        $service = $this->createService([
            $this->sellOrdersPath(self::TRITANIUM) => [
                $this->rateLimitedResponse(),
                $this->jsonResponse([$this->order(self::JITA_STATION_ID, 5.12, isBuyOrder: false)]),
            ],
            $this->sellOrdersPath(self::PYERITE) => [$this->jsonResponse([$this->order(self::JITA_STATION_ID, 11.75, isBuyOrder: false)])],
        ], marketCache: $marketCache);

        $prices = $service->getJitaPrices([self::TRITANIUM, self::PYERITE]);

        $this->assertSame([self::TRITANIUM => 5.12, self::PYERITE => 11.75], $prices);
        $this->assertSame(5.12, $marketCache->getItem('market_jita_34')->get());
        // Only the rate-limited type is replayed.
        $this->assertSame([
            self::PRODUCTION_ESI_BASE_URL . '/markets/10000002/orders/?order_type=sell&type_id=34',
            self::PRODUCTION_ESI_BASE_URL . '/markets/10000002/orders/?order_type=sell&type_id=35',
            self::PRODUCTION_ESI_BASE_URL . '/markets/10000002/orders/?order_type=sell&type_id=34',
        ], $this->requestedUrls);
    }

    // ===========================================
    // Helpers
    // ===========================================

    /**
     * Mirrors the production wiring: $esiBaseUrl is not bound in services.yaml, so the current
     * constructor runs on its hard-coded default.
     *
     * @param array<string, list<MockResponse>> $esiResponsesByPath responses consumed in order, per path and query
     */
    private function createService(
        array $esiResponsesByPath,
        string $esiBaseUrl = self::PRODUCTION_ESI_BASE_URL,
        ?CacheItemPoolInterface $marketCache = null,
        ?JitaMarketService $jitaMarketService = null,
        ?LoggerInterface $logger = null,
    ): MarketService {
        $esiHttpClient = $this->createSimulatedEsi($esiResponsesByPath, $esiBaseUrl);
        $esiClient = new EsiClient(
            $esiHttpClient,
            $this->createStub(CacheItemPoolInterface::class),
            $this->createStub(TokenManager::class),
            $esiBaseUrl,
            new NullLogger(),
        );

        if ($jitaMarketService === null) {
            $jitaMarketService = $this->createStub(JitaMarketService::class);
            $jitaMarketService->method('hasCachedData')->willReturn(false);
        }

        if (in_array(HttpClientInterface::class, $this->constructorParameterTypesByName(), true)) {
            return new MarketService(
                esiClient: $esiClient,
                httpClient: $esiHttpClient,
                marketCache: $marketCache ?? new ArrayAdapter(),
                logger: $logger ?? new NullLogger(),
                structureMarketService: $this->createStub(StructureMarketService::class),
                jitaMarketService: $jitaMarketService,
                defaultMarketStructureId: 1035466617946,
                defaultMarketStructureName: 'C-J6MT - 1st Taj Mahgoon (Keepstar)',
            );
        }

        return new MarketService(
            esiClient: $esiClient,
            marketCache: $marketCache ?? new ArrayAdapter(),
            logger: $logger ?? new NullLogger(),
            structureMarketService: $this->createStub(StructureMarketService::class),
            jitaMarketService: $jitaMarketService,
            defaultMarketStructureId: 1035466617946,
            defaultMarketStructureName: 'C-J6MT - 1st Taj Mahgoon (Keepstar)',
        );
    }

    /**
     * @param array<string, list<MockResponse>> $esiResponsesByPath
     */
    private function createSimulatedEsi(array $esiResponsesByPath, string $esiBaseUrl): MockHttpClient
    {
        $callsByPath = [];

        return new MockHttpClient(function (string $method, string $url) use ($esiResponsesByPath, $esiBaseUrl, &$callsByPath): MockResponse {
            $this->requestedUrls[] = $url;

            if (!str_starts_with($url, $esiBaseUrl . '/')) {
                return new MockResponse('{"error":"Unknown host"}', ['http_code' => 404]);
            }
            $path = substr($url, strlen($esiBaseUrl));

            $callIndex = $callsByPath[$path] ?? 0;
            $callsByPath[$path] = $callIndex + 1;
            $response = $esiResponsesByPath[$path][$callIndex] ?? null;
            if ($response === null) {
                $this->fail(sprintf('Unexpected ESI request: %s %s', $method, $url));
            }

            return $response;
        });
    }

    private function sellOrdersPath(int $typeId): string
    {
        return sprintf('/markets/10000002/orders/?order_type=sell&type_id=%d', $typeId);
    }

    /**
     * @return array{location_id: int, price: float, is_buy_order: bool, volume_remain: int}
     */
    private function order(int $locationId, float $price, bool $isBuyOrder): array
    {
        return ['location_id' => $locationId, 'price' => $price, 'is_buy_order' => $isBuyOrder, 'volume_remain' => 1000];
    }

    /**
     * @param array<int|string, mixed> $body
     * @param array<string, string> $headers
     */
    private function jsonResponse(array $body, array $headers = []): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => [
                'Content-Type' => 'application/json',
                'X-Esi-Error-Limit-Remain' => '100',
                'X-Esi-Error-Limit-Reset' => '0',
                ...$headers,
            ],
        ]);
    }

    private function rateLimitedResponse(): MockResponse
    {
        return new MockResponse('{"error":"rate limited"}', [
            'http_code' => 429,
            'response_headers' => [
                'Content-Type' => 'application/json',
                'Retry-After' => '0',
                'X-Esi-Error-Limit-Remain' => '100',
                'X-Esi-Error-Limit-Reset' => '0',
            ],
        ]);
    }

    /**
     * @return array<string, string|null> parameter name => declared type
     */
    private function constructorParameterTypesByName(): array
    {
        $constructor = (new \ReflectionClass(MarketService::class))->getConstructor();
        $this->assertNotNull($constructor);

        $parameterTypesByName = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $parameterTypesByName[$parameter->getName()] = $type instanceof \ReflectionNamedType ? $type->getName() : null;
        }

        return $parameterTypesByName;
    }
}
