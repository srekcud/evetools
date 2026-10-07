<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\Industry\EsiCostIndexService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Issue #25: adjusted prices and cost indices must be read through EsiClient
 * (ESI_BASE_URL, error-limit tracking, 420/429 retry) instead of a private HttpClientInterface.
 *
 * ESI is simulated at the HTTP boundary (MockHttpClient). createService() wires the service on
 * whatever constructor it currently has, so the behavior guards stay green before and after the switch.
 */
#[CoversClass(EsiCostIndexService::class)]
#[AllowMockObjectsWithoutExpectations]
class EsiCostIndexServiceTest extends TestCase
{
    private const PRODUCTION_ESI_BASE_URL = 'https://esi.evetech.net/latest';
    private const CONFIGURED_ESI_BASE_URL = 'https://esi.test/latest';
    private const ADJUSTED_PRICES_PATH = '/markets/prices/';
    private const COST_INDICES_PATH = '/industry/systems/';

    private const TRITANIUM = 34;
    private const PYERITE = 35;
    private const MEXALLON = 36;
    private const JITA = 30000142;
    private const PERIMETER = 30000144;

    private CacheItemPoolInterface&MockObject $cache;
    private EsiCostIndexService $service;

    /** @var list<string> */
    private array $requestedUrls = [];

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->requestedUrls = [];

        // Formula tests never reach ESI.
        $this->service = $this->createService([], self::PRODUCTION_ESI_BASE_URL, $this->cache);
    }

    // ===========================================
    // syncAdjustedPrices() / syncCostIndices() — GREEN guards (stored cache content)
    // ===========================================

    public function testSyncAdjustedPricesStoresEachAdjustedPriceByTypeId(): void
    {
        $esiCostIndexCache = new ArrayAdapter();
        $service = $this->createService([
            self::ADJUSTED_PRICES_PATH => [$this->jsonResponse($this->threeAdjustedPrices())],
        ], self::PRODUCTION_ESI_BASE_URL, $esiCostIndexCache);

        $count = $service->syncAdjustedPrices();

        $this->assertSame(3, $count);
        $this->assertSame(5.12, $esiCostIndexCache->getItem('esi_adjusted_price_' . self::TRITANIUM)->get());
        $this->assertSame(11.75, $esiCostIndexCache->getItem('esi_adjusted_price_' . self::PYERITE)->get());
        // No adjusted_price in the ESI entry: stored as 0.0.
        $this->assertSame(0.0, $esiCostIndexCache->getItem('esi_adjusted_price_' . self::MEXALLON)->get());
        $this->assertSame([self::TRITANIUM => 5.12, self::PYERITE => 11.75, self::MEXALLON => 0.0], $service->getAdjustedPrices([self::TRITANIUM, self::PYERITE, self::MEXALLON]));

        $meta = $esiCostIndexCache->getItem('esi_adjusted_prices_meta')->get();
        $this->assertSame(3, $meta['count']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $service->getAdjustedPricesLastSync());
    }

    public function testSyncCostIndicesStoresOneCostIndexPerSystemAndActivity(): void
    {
        $esiCostIndexCache = new ArrayAdapter();
        $service = $this->createService([
            self::COST_INDICES_PATH => [$this->jsonResponse($this->twoSystemsCostIndices())],
        ], self::PRODUCTION_ESI_BASE_URL, $esiCostIndexCache);

        $count = $service->syncCostIndices();

        $this->assertSame(2, $count);
        $this->assertSame(0.0512, $service->getCostIndex(self::JITA, 'manufacturing'));
        $this->assertSame(0.0213, $service->getCostIndex(self::JITA, 'reaction'));
        $this->assertSame(0.0734, $service->getCostIndex(self::PERIMETER, 'manufacturing'));
        $this->assertSame(0.0021, $service->getCostIndex(self::PERIMETER, 'invention'));
        $this->assertNull($service->getCostIndex(self::PERIMETER, 'reaction'));

        $meta = $esiCostIndexCache->getItem('esi_cost_indices_meta')->get();
        $this->assertSame(2, $meta['count']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $service->getCostIndicesLastSync());
    }

    public function testSyncAdjustedPricesServerErrorThrowsAndStoresNothing(): void
    {
        $esiCostIndexCache = new ArrayAdapter();
        $service = $this->createService([
            self::ADJUSTED_PRICES_PATH => [new MockResponse('{"error":"Internal server error"}', ['http_code' => 500])],
        ], self::PRODUCTION_ESI_BASE_URL, $esiCostIndexCache);

        try {
            $service->syncAdjustedPrices();
            $this->fail('A 500 on /markets/prices/ must abort the sync.');
        } catch (\RuntimeException) {
            // RuntimeException today, EsiApiException (a RuntimeException) through EsiClient.
        }

        $this->assertFalse($esiCostIndexCache->getItem('esi_adjusted_price_' . self::TRITANIUM)->isHit());
        $this->assertNull($service->getAdjustedPricesLastSync());
    }

    // ===========================================
    // RED — issue #25: ESI must be read through EsiClient
    // ===========================================

    public function testConstructorTakesEsiClientInsteadOfHttpClient(): void
    {
        $parameterTypes = $this->constructorParameterTypes();

        $this->assertNotContains(HttpClientInterface::class, $parameterTypes);
        $this->assertContains(EsiClient::class, $parameterTypes);
    }

    public function testAdjustedPricesAndCostIndicesAreReadFromTheConfiguredEsiBaseUrl(): void
    {
        $esiCostIndexCache = new ArrayAdapter();
        $service = $this->createService([
            self::ADJUSTED_PRICES_PATH => [$this->jsonResponse($this->threeAdjustedPrices())],
            self::COST_INDICES_PATH => [$this->jsonResponse($this->twoSystemsCostIndices())],
        ], self::CONFIGURED_ESI_BASE_URL, $esiCostIndexCache);

        $this->assertSame(3, $service->syncAdjustedPrices());
        $this->assertSame(2, $service->syncCostIndices());

        $this->assertSame(5.12, $service->getAdjustedPrice(self::TRITANIUM));
        $this->assertSame(0.0512, $service->getCostIndex(self::JITA, 'manufacturing'));
        $this->assertSame([
            self::CONFIGURED_ESI_BASE_URL . self::ADJUSTED_PRICES_PATH,
            self::CONFIGURED_ESI_BASE_URL . self::COST_INDICES_PATH,
        ], $this->requestedUrls);
    }

    public function testRateLimitedAdjustedPricesRequestIsRetriedOnceAndStored(): void
    {
        $esiCostIndexCache = new ArrayAdapter();
        $service = $this->createService([
            self::ADJUSTED_PRICES_PATH => [
                new MockResponse('{"error":"Too many requests"}', ['http_code' => 429, 'response_headers' => ['Retry-After' => '0']]),
                $this->jsonResponse($this->threeAdjustedPrices()),
            ],
        ], self::PRODUCTION_ESI_BASE_URL, $esiCostIndexCache);

        $count = $service->syncAdjustedPrices();

        $this->assertSame(3, $count);
        $this->assertSame(5.12, $service->getAdjustedPrice(self::TRITANIUM));
        $this->assertSame(11.75, $service->getAdjustedPrice(self::PYERITE));
        $this->assertCount(2, $this->requestedUrls);
    }

    public function testAdjustedPricesAndCostIndicesAreReadWithEsiClientGetWithoutToken(): void
    {
        /** @var list<array{string, mixed}> $esiGetCalls */
        $esiGetCalls = [];
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('get')
            ->willReturnCallback(function (string $endpoint, mixed $token = null) use (&$esiGetCalls): array {
                $esiGetCalls[] = [$endpoint, $token];

                return match ($endpoint) {
                    self::ADJUSTED_PRICES_PATH => $this->threeAdjustedPrices(),
                    self::COST_INDICES_PATH => $this->twoSystemsCostIndices(),
                    default => throw new \LogicException('Unexpected ESI endpoint: ' . $endpoint),
                };
            });

        $service = new EsiCostIndexService(
            esiClient: $esiClient,
            cache: new ArrayAdapter(),
            logger: new NullLogger(),
        );

        $this->assertSame(3, $service->syncAdjustedPrices());
        $this->assertSame(2, $service->syncCostIndices());
        $this->assertSame([
            [self::ADJUSTED_PRICES_PATH, null],
            [self::COST_INDICES_PATH, null],
        ], $esiGetCalls);
        $this->assertSame(0.0734, $service->getCostIndex(self::PERIMETER, 'manufacturing'));
    }

    // ===========================================
    // getAdjustedPrices() batch tests
    // ===========================================

    public function testGetAdjustedPricesReturnsCachedPrices(): void
    {
        $this->cache->method('getItem')
            ->willReturnCallback(function (string $key): CacheItemInterface {
                $item = $this->createMock(CacheItemInterface::class);
                $item->method('isHit')->willReturn(true);
                $item->method('get')->willReturn(match ($key) {
                    'esi_adjusted_price_34' => 5.0,
                    'esi_adjusted_price_35' => 12.0,
                    default => 0.0,
                });

                return $item;
            });

        $result = $this->service->getAdjustedPrices([34, 35]);

        $this->assertSame([34 => 5.0, 35 => 12.0], $result);
    }

    public function testGetAdjustedPricesSkipsMissingEntries(): void
    {
        $this->cache->method('getItem')
            ->willReturnCallback(function (string $key): CacheItemInterface {
                $item = $this->createMock(CacheItemInterface::class);
                if ($key === 'esi_adjusted_price_34') {
                    $item->method('isHit')->willReturn(true);
                    $item->method('get')->willReturn(5.0);
                } else {
                    $item->method('isHit')->willReturn(false);
                }

                return $item;
            });

        $result = $this->service->getAdjustedPrices([34, 99999]);

        $this->assertSame([34 => 5.0], $result);
    }

    public function testGetAdjustedPricesReturnsEmptyForEmptyInput(): void
    {
        $result = $this->service->getAdjustedPrices([]);

        $this->assertSame([], $result);
    }

    // ===========================================
    // calculateEivFromPrices() tests
    // ===========================================

    public function testCalculateEivFromPricesWithKnownPrices(): void
    {
        $materials = [
            ['materialTypeId' => 34, 'quantity' => 100],
            ['materialTypeId' => 35, 'quantity' => 50],
        ];
        $prices = [34 => 5.0, 35 => 12.0];

        $eiv = $this->service->calculateEivFromPrices($materials, $prices);

        // 100*5.0 + 50*12.0 = 500 + 600 = 1100
        $this->assertSame(1100.0, $eiv);
    }

    public function testCalculateEivFromPricesDefaultsToZeroForMissingPrice(): void
    {
        $materials = [
            ['materialTypeId' => 34, 'quantity' => 200],
            ['materialTypeId' => 99999, 'quantity' => 50],
        ];
        $prices = [34 => 10.0]; // 99999 not in map, defaults to 0.0

        $eiv = $this->service->calculateEivFromPrices($materials, $prices);

        // 200*10.0 + 50*0.0 = 2000
        $this->assertSame(2000.0, $eiv);
    }

    public function testCalculateEivFromPricesWithEmptyMaterials(): void
    {
        $eiv = $this->service->calculateEivFromPrices([], [34 => 5.0]);

        $this->assertSame(0.0, $eiv);
    }

    // ===========================================
    // calculateEiv() tests
    // ===========================================

    public function testCalculateEivWithKnownAdjustedPrices(): void
    {
        // Material: 100 Tritanium (adjusted=5.0) + 50 Pyerite (adjusted=12.0)
        // EIV = 100*5.0 + 50*12.0 = 500 + 600 = 1100
        $materials = [
            ['materialTypeId' => 34, 'quantity' => 100],
            ['materialTypeId' => 35, 'quantity' => 50],
        ];

        $this->cache->method('getItem')
            ->willReturnCallback(function (string $key): CacheItemInterface {
                $item = $this->createMock(CacheItemInterface::class);
                $item->method('isHit')->willReturn(true);
                $item->method('get')->willReturn(match ($key) {
                    'esi_adjusted_price_34' => 5.0,
                    'esi_adjusted_price_35' => 12.0,
                    default => 0.0,
                });

                return $item;
            });

        $eiv = $this->service->calculateEiv($materials);

        $this->assertSame(1100.0, $eiv);
    }

    public function testCalculateEivSkipsMaterialsWithMissingPrice(): void
    {
        // One material has adjusted price, the other is not in cache
        $materials = [
            ['materialTypeId' => 34, 'quantity' => 200],
            ['materialTypeId' => 99999, 'quantity' => 50],
        ];

        $this->cache->method('getItem')
            ->willReturnCallback(function (string $key): CacheItemInterface {
                $item = $this->createMock(CacheItemInterface::class);
                if ($key === 'esi_adjusted_price_34') {
                    $item->method('isHit')->willReturn(true);
                    $item->method('get')->willReturn(10.0);
                } else {
                    $item->method('isHit')->willReturn(false);
                }

                return $item;
            });

        $eiv = $this->service->calculateEiv($materials);

        // Only Tritanium counted: 200 * 10.0 = 2000
        $this->assertSame(2000.0, $eiv);
    }

    public function testCalculateEivWithZeroPriceIncludesIt(): void
    {
        // A material with adjusted_price = 0.0 should contribute 0 to EIV
        $materials = [
            ['materialTypeId' => 34, 'quantity' => 100],
        ];

        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn(0.0);
        $this->cache->method('getItem')->willReturn($item);

        $eiv = $this->service->calculateEiv($materials);

        $this->assertSame(0.0, $eiv);
    }

    public function testCalculateEivWithEmptyMaterials(): void
    {
        $eiv = $this->service->calculateEiv([]);

        $this->assertSame(0.0, $eiv);
    }

    // ===========================================
    // calculateJobInstallCost() tests
    // ===========================================

    public function testCalculateJobInstallCostBasicFormula(): void
    {
        // Formula: eiv * runs * costIndex * (1 + facilityTaxRate/100)
        // 1000000 * 5 * 0.05 * (1 + 10/100) = 1000000 * 5 * 0.05 * 1.1 = 275000
        $costIndexItem = $this->createMock(CacheItemInterface::class);
        $costIndexItem->method('isHit')->willReturn(true);
        $costIndexItem->method('get')->willReturn(0.05);

        $this->cache->method('getItem')
            ->willReturn($costIndexItem);

        $result = $this->service->calculateJobInstallCost(
            1000000.0,
            5,
            30002510,
            'manufacturing',
            10.0,
        );

        $this->assertSame(275000.0, $result);
    }

    public function testCalculateJobInstallCostWithNullFacilityTax(): void
    {
        // null facility tax = 0 tax: eiv * runs * costIndex * 1.0
        // 500000 * 1 * 0.10 * 1.0 = 50000
        $costIndexItem = $this->createMock(CacheItemInterface::class);
        $costIndexItem->method('isHit')->willReturn(true);
        $costIndexItem->method('get')->willReturn(0.10);

        $this->cache->method('getItem')->willReturn($costIndexItem);

        $result = $this->service->calculateJobInstallCost(
            500000.0,
            1,
            30002510,
            'manufacturing',
            null,
        );

        $this->assertSame(50000.0, $result);
    }

    public function testCalculateJobInstallCostWithZeroEivReturnsZero(): void
    {
        $result = $this->service->calculateJobInstallCost(
            0.0,
            10,
            30002510,
            'manufacturing',
            10.0,
        );

        $this->assertSame(0.0, $result);
    }

    public function testCalculateJobInstallCostWithMissingCostIndexReturnsZero(): void
    {
        // Cost index not in cache -> returns 0.0
        $costIndexItem = $this->createMock(CacheItemInterface::class);
        $costIndexItem->method('isHit')->willReturn(false);

        $this->cache->method('getItem')->willReturn($costIndexItem);

        $result = $this->service->calculateJobInstallCost(
            1000000.0,
            5,
            30002510,
            'manufacturing',
            10.0,
        );

        $this->assertSame(0.0, $result);
    }

    public function testCalculateJobInstallCostWithZeroCostIndex(): void
    {
        // Cost index = 0 in cache -> result = 0
        $costIndexItem = $this->createMock(CacheItemInterface::class);
        $costIndexItem->method('isHit')->willReturn(true);
        $costIndexItem->method('get')->willReturn(0.0);

        $this->cache->method('getItem')->willReturn($costIndexItem);

        $result = $this->service->calculateJobInstallCost(
            1000000.0,
            5,
            30002510,
            'manufacturing',
            10.0,
        );

        $this->assertSame(0.0, $result);
    }

    public function testCalculateJobInstallCostForReactionActivity(): void
    {
        // Reactions use 'reaction' as activity key
        // 200000 * 10 * 0.02 * 1.0 = 40000
        $costIndexItem = $this->createMock(CacheItemInterface::class);
        $costIndexItem->method('isHit')->willReturn(true);
        $costIndexItem->method('get')->willReturn(0.02);

        $this->cache->method('getItem')
            ->with('esi_cost_index_30002510_reaction')
            ->willReturn($costIndexItem);

        $result = $this->service->calculateJobInstallCost(
            200000.0,
            10,
            30002510,
            'reaction',
            null,
        );

        $this->assertSame(40000.0, $result);
    }

    // ===========================================
    // Helpers
    // ===========================================

    /**
     * @param array<string, list<MockResponse>> $esiResponsesByPath responses consumed in order, per path
     */
    private function createService(array $esiResponsesByPath, string $esiBaseUrl, CacheItemPoolInterface $esiCostIndexCache): EsiCostIndexService
    {
        $esiHttpClient = $this->createSimulatedEsi($esiResponsesByPath, $esiBaseUrl);

        if (in_array(HttpClientInterface::class, $this->constructorParameterTypes(), true)) {
            return new EsiCostIndexService($esiHttpClient, $esiCostIndexCache, new NullLogger());
        }

        $esiClient = new EsiClient(
            $esiHttpClient,
            $this->createStub(CacheItemPoolInterface::class),
            $this->createStub(TokenManager::class),
            $esiBaseUrl,
            new NullLogger(),
        );

        return new EsiCostIndexService(
            esiClient: $esiClient,
            cache: $esiCostIndexCache,
            logger: new NullLogger(),
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

            $urlWithoutQuery = (string) strtok($url, '?');
            if (!str_starts_with($urlWithoutQuery, $esiBaseUrl . '/')) {
                return new MockResponse('{"error":"Unknown host"}', ['http_code' => 404]);
            }
            $path = substr($urlWithoutQuery, strlen($esiBaseUrl));

            $callIndex = $callsByPath[$path] ?? 0;
            $callsByPath[$path] = $callIndex + 1;
            $response = $esiResponsesByPath[$path][$callIndex] ?? null;
            if ($response === null) {
                $this->fail(sprintf('Unexpected ESI request: %s %s', $method, $url));
            }

            return $response;
        });
    }

    /**
     * @param array<int|string, mixed> $body
     */
    private function jsonResponse(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    /**
     * @return list<array{type_id: int, adjusted_price?: float, average_price?: float}>
     */
    private function threeAdjustedPrices(): array
    {
        return [
            ['type_id' => self::TRITANIUM, 'adjusted_price' => 5.12, 'average_price' => 4.98],
            ['type_id' => self::PYERITE, 'adjusted_price' => 11.75],
            ['type_id' => self::MEXALLON, 'average_price' => 60.4],
        ];
    }

    /**
     * @return list<array{solar_system_id: int, cost_indices: list<array{activity: string, cost_index: float}>}>
     */
    private function twoSystemsCostIndices(): array
    {
        return [
            ['solar_system_id' => self::JITA, 'cost_indices' => [
                ['activity' => 'manufacturing', 'cost_index' => 0.0512],
                ['activity' => 'reaction', 'cost_index' => 0.0213],
            ]],
            ['solar_system_id' => self::PERIMETER, 'cost_indices' => [
                ['activity' => 'manufacturing', 'cost_index' => 0.0734],
                ['activity' => 'invention', 'cost_index' => 0.0021],
            ]],
        ];
    }

    /**
     * @return list<string>
     */
    private function constructorParameterTypes(): array
    {
        $constructor = (new \ReflectionClass(EsiCostIndexService::class))->getConstructor();
        $this->assertNotNull($constructor);

        return array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $constructor->getParameters(),
        );
    }
}
