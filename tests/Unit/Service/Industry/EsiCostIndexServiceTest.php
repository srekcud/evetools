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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
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
    private const SYSTEM_WITHOUT_COST_INDEX = 30002510;

    private const ROBOTICS = 9848;
    private const COOLANT = 3683;
    private const NITROGEN_FUEL_BLOCK_MATERIALS = [
        44 => 4,
        3683 => 22,
        3689 => 4,
        9832 => 9,
        9848 => 1,
        16272 => 170,
        16273 => 350,
        16275 => 20,
        17888 => 450,
    ];
    private const ADJUSTED_PRICE_DIVISOR = 1_000.0;
    private const MORPHITE = 11399;
    private const MORPHITE_ADJUSTED_PRICE = 10_000_000.0;
    private const JITA_INVENTION_COST_INDEX = 0.08;
    private const JITA_MANUFACTURING_COST_INDEX = 0.05;
    private const JITA_REACTION_COST_INDEX = 0.04;
    private const PERIMETER_MANUFACTURING_COST_INDEX = 0.0734;
    private const FACILITY_TAX_PERCENT = 10.0;

    private const SECONDS_PER_MINUTE = 60;
    private const COST_INDEX_SYNC_INTERVAL_SECONDS = 2 * 3600;
    private const ADJUSTED_PRICE_SYNC_INTERVAL_SECONDS = 24 * 3600;
    private const SYNC_INTERVALS_KEPT_IN_CACHE = 3;

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

    public function testSyncAdjustedPricesStoresEachAdjustedPriceByTypeIdAndSkipsEntriesWithoutAdjustedPrice(): void
    {
        $esiCostIndexCache = new ArrayAdapter();
        $service = $this->createService([
            self::ADJUSTED_PRICES_PATH => [$this->jsonResponse($this->threeAdjustedPrices())],
        ], self::PRODUCTION_ESI_BASE_URL, $esiCostIndexCache);

        $count = $service->syncAdjustedPrices();

        $this->assertSame(2, $count);
        $this->assertSame(5.12, $esiCostIndexCache->getItem('esi_adjusted_price_' . self::TRITANIUM)->get());
        $this->assertSame(11.75, $esiCostIndexCache->getItem('esi_adjusted_price_' . self::PYERITE)->get());
        $this->assertFalse($esiCostIndexCache->getItem('esi_adjusted_price_' . self::MEXALLON)->isHit());
        $this->assertNull($service->getAdjustedPrice(self::MEXALLON));
        $this->assertSame([self::TRITANIUM => 5.12, self::PYERITE => 11.75], $service->getAdjustedPrices([self::TRITANIUM, self::PYERITE, self::MEXALLON]));
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
    }

    public function testSyncAdjustedPricesLogsTheFetchThenTheNumberOfCachedAdjustedPrices(): void
    {
        $logger = new BufferingLogger();
        $service = $this->createService([
            self::ADJUSTED_PRICES_PATH => [$this->jsonResponse($this->threeAdjustedPrices())],
        ], self::PRODUCTION_ESI_BASE_URL, new ArrayAdapter(), $logger);

        $service->syncAdjustedPrices();

        $this->assertSame(
            [
                ['info', 'Fetching ESI adjusted prices', []],
                ['info', 'ESI adjusted prices cached', ['count' => 2]],
            ],
            $logger->cleanLogs(),
        );
    }

    public function testSyncCostIndicesLogsTheFetchThenTheNumberOfCachedSystems(): void
    {
        $logger = new BufferingLogger();
        $service = $this->createService([
            self::COST_INDICES_PATH => [$this->jsonResponse($this->twoSystemsCostIndices())],
        ], self::PRODUCTION_ESI_BASE_URL, new ArrayAdapter(), $logger);

        $service->syncCostIndices();

        $this->assertSame(
            [
                ['info', 'Fetching ESI system cost indices', []],
                ['info', 'ESI system cost indices cached', ['count' => 2]],
            ],
            $logger->cleanLogs(),
        );
    }

    public function testWholeIskAdjustedPriceSentAsAnIntegerByEsiIsReadBackAsAFloat(): void
    {
        $service = $this->createService([
            self::ADJUSTED_PRICES_PATH => [$this->jsonResponse([
                ['type_id' => self::MORPHITE, 'adjusted_price' => 9512],
                ['type_id' => self::PYERITE, 'adjusted_price' => 11.75],
            ])],
        ], self::PRODUCTION_ESI_BASE_URL, new ArrayAdapter());
        $service->syncAdjustedPrices();

        $this->assertSame([self::MORPHITE => 9512.0, self::PYERITE => 11.75], $service->getAdjustedPrices([self::MORPHITE, self::PYERITE]));
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

        $this->assertSame(2, $service->syncAdjustedPrices());
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

        $this->assertSame(2, $count);
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

        $this->assertSame(2, $service->syncAdjustedPrices());
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

    public function testCostIndexIsStillServedAfterAFailedSyncUpToThreeSyncIntervals(): void
    {
        $clock = new MockClock();
        $service = $this->createService([
            self::COST_INDICES_PATH => [$this->jsonResponse($this->twoSystemsCostIndices())],
        ], self::PRODUCTION_ESI_BASE_URL, new ArrayAdapter(clock: $clock));
        $service->syncCostIndices();

        $clock->sleep(self::COST_INDEX_SYNC_INTERVAL_SECONDS + self::SECONDS_PER_MINUTE);

        $this->assertSame(0.0512, $service->getCostIndex(self::JITA, 'manufacturing'));

        $clock->sleep(self::SYNC_INTERVALS_KEPT_IN_CACHE * self::COST_INDEX_SYNC_INTERVAL_SECONDS - self::COST_INDEX_SYNC_INTERVAL_SECONDS - self::SECONDS_PER_MINUTE);

        $this->assertSame(0.0512, $service->getCostIndex(self::JITA, 'manufacturing'));
        $this->assertSame(0.0734, $service->getCostIndex(self::PERIMETER, 'manufacturing'));
    }

    public function testCostIndexExpiresOneMinutePastThreeSyncIntervals(): void
    {
        $clock = new MockClock();
        $service = $this->createService([
            self::COST_INDICES_PATH => [$this->jsonResponse($this->twoSystemsCostIndices())],
        ], self::PRODUCTION_ESI_BASE_URL, new ArrayAdapter(clock: $clock));
        $service->syncCostIndices();

        $clock->sleep(self::SYNC_INTERVALS_KEPT_IN_CACHE * self::COST_INDEX_SYNC_INTERVAL_SECONDS + self::SECONDS_PER_MINUTE);

        $this->assertNull($service->getCostIndex(self::JITA, 'manufacturing'));
    }

    public function testAdjustedPriceIsStillServedAfterAFailedSyncUpToThreeSyncIntervals(): void
    {
        $clock = new MockClock();
        $service = $this->createService([
            self::ADJUSTED_PRICES_PATH => [$this->jsonResponse($this->threeAdjustedPrices())],
        ], self::PRODUCTION_ESI_BASE_URL, new ArrayAdapter(clock: $clock));
        $service->syncAdjustedPrices();

        $clock->sleep(self::ADJUSTED_PRICE_SYNC_INTERVAL_SECONDS + self::SECONDS_PER_MINUTE);

        $this->assertSame(5.12, $service->getAdjustedPrice(self::TRITANIUM));

        $clock->sleep(self::SYNC_INTERVALS_KEPT_IN_CACHE * self::ADJUSTED_PRICE_SYNC_INTERVAL_SECONDS - self::ADJUSTED_PRICE_SYNC_INTERVAL_SECONDS - self::SECONDS_PER_MINUTE);

        $this->assertSame([self::TRITANIUM => 5.12, self::PYERITE => 11.75], $service->getAdjustedPrices([self::TRITANIUM, self::PYERITE]));
    }

    public function testAdjustedPriceExpiresOneMinutePastThreeSyncIntervals(): void
    {
        $clock = new MockClock();
        $service = $this->createService([
            self::ADJUSTED_PRICES_PATH => [$this->jsonResponse($this->threeAdjustedPrices())],
        ], self::PRODUCTION_ESI_BASE_URL, new ArrayAdapter(clock: $clock));
        $service->syncAdjustedPrices();

        $clock->sleep(self::SYNC_INTERVALS_KEPT_IN_CACHE * self::ADJUSTED_PRICE_SYNC_INTERVAL_SECONDS + self::SECONDS_PER_MINUTE);

        $this->assertNull($service->getAdjustedPrice(self::TRITANIUM));
        $this->assertSame([], $service->getAdjustedPrices([self::TRITANIUM, self::PYERITE]));
    }

    public function testEstimateJobInstallCostOfNitrogenFuelBlockWithEveryInputKnownAddsSystemFacilityTaxAndSccSurchargeOnTheBase(): void
    {
        $service = $this->createServiceWithSyncedNitrogenFuelBlockInputs();

        $estimate = $service->estimateJobInstallCost($this->nitrogenFuelBlockMaterials(), 1, self::JITA, 'manufacturing', self::FACILITY_TAX_PERCENT);

        $this->assertEqualsWithDelta(3235.92496, $estimate['cost'], 1e-6);
        $this->assertNull($estimate['unknownReason']);
        $this->assertSame([], $estimate['missingAdjustedPriceTypeIds']);
    }

    public function testEstimateJobInstallCostMultipliesTheBaseByRunsAndReadsNullFacilityTaxAsZeroWithSccSurcharge(): void
    {
        $service = $this->createServiceWithSyncedNitrogenFuelBlockInputs();

        $estimate = $service->estimateJobInstallCost($this->nitrogenFuelBlockMaterials(), 3, self::JITA, 'manufacturing', null);

        $this->assertEqualsWithDelta(4598.41968, $estimate['cost'], 1e-6);
        $this->assertNull($estimate['unknownReason']);
        $this->assertSame([], $estimate['missingAdjustedPriceTypeIds']);
    }

    public function testEstimateJobInstallCostOfNitrogenFuelBlockWithNullFacilityTaxStillAddsSccSurcharge(): void
    {
        $service = $this->createServiceWithSyncedNitrogenFuelBlockInputs();

        $estimate = $service->estimateJobInstallCost($this->nitrogenFuelBlockMaterials(), 1, self::JITA, 'manufacturing', null);

        $this->assertEqualsWithDelta(1532.80656, $estimate['cost'], 1e-6);
        $this->assertNull($estimate['unknownReason']);
        $this->assertSame([], $estimate['missingAdjustedPriceTypeIds']);
    }

    public function testEstimateJobInstallCostOfNitrogenFuelBlockReactionUsesTheReactionCostIndex(): void
    {
        $service = $this->createServiceWithSyncedNitrogenFuelBlockInputs();

        $estimate = $service->estimateJobInstallCost($this->nitrogenFuelBlockMaterials(), 1, self::JITA, 'reaction', self::FACILITY_TAX_PERCENT);

        $this->assertEqualsWithDelta(3065.61312, $estimate['cost'], 1e-6);
        $this->assertNull($estimate['unknownReason']);
        $this->assertSame([], $estimate['missingAdjustedPriceTypeIds']);
    }

    public function testEstimateJobInstallCostTreatsAnExplicitZeroFacilityTaxAsNull(): void
    {
        $service = $this->createServiceWithSyncedNitrogenFuelBlockInputs();

        $estimate = $service->estimateJobInstallCost($this->nitrogenFuelBlockMaterials(), 1, self::JITA, 'manufacturing', 0.0);

        $this->assertEqualsWithDelta(1532.80656, $estimate['cost'], 1e-6);
        $this->assertNull($estimate['unknownReason']);
    }

    public function testEstimateJobInstallCostIsUnknownWhenAMaterialHasNoAdjustedPrice(): void
    {
        $service = $this->createServiceWithSyncedNitrogenFuelBlockInputs(typeIdsWithoutAdjustedPrice: [self::ROBOTICS]);

        $estimate = $service->estimateJobInstallCost($this->nitrogenFuelBlockMaterials(), 1, self::JITA, 'manufacturing', self::FACILITY_TAX_PERCENT);

        $this->assertNull($estimate['cost']);
        $this->assertSame('missing_adjusted_price', $estimate['unknownReason']);
        $this->assertSame([self::ROBOTICS], $estimate['missingAdjustedPriceTypeIds']);
    }

    public function testEstimateJobInstallCostListsEachMissingAdjustedPriceTypeIdOnceInAscendingOrder(): void
    {
        $service = $this->createServiceWithSyncedNitrogenFuelBlockInputs(typeIdsWithoutAdjustedPrice: [self::ROBOTICS, self::COOLANT]);
        $materialsWithRoboticsTwice = [
            ...array_reverse($this->nitrogenFuelBlockMaterials()),
            ['materialTypeId' => self::ROBOTICS, 'quantity' => 2],
        ];

        $estimate = $service->estimateJobInstallCost($materialsWithRoboticsTwice, 1, self::JITA, 'manufacturing', self::FACILITY_TAX_PERCENT);

        $this->assertNull($estimate['cost']);
        $this->assertSame('missing_adjusted_price', $estimate['unknownReason']);
        $this->assertSame([self::COOLANT, self::ROBOTICS], $estimate['missingAdjustedPriceTypeIds']);
    }

    public function testEstimateJobInstallCostIsUnknownWhenTheSystemHasNoCostIndex(): void
    {
        $service = $this->createServiceWithSyncedNitrogenFuelBlockInputs();

        $estimate = $service->estimateJobInstallCost($this->nitrogenFuelBlockMaterials(), 1, self::SYSTEM_WITHOUT_COST_INDEX, 'manufacturing', self::FACILITY_TAX_PERCENT);

        $this->assertNull($estimate['cost']);
        $this->assertSame('missing_cost_index', $estimate['unknownReason']);
        $this->assertSame([], $estimate['missingAdjustedPriceTypeIds']);
    }

    public function testEstimateJobInstallCostIsUnknownWhenTheSystemHasNoCostIndexForTheActivity(): void
    {
        $service = $this->createServiceWithSyncedNitrogenFuelBlockInputs();

        $estimate = $service->estimateJobInstallCost($this->nitrogenFuelBlockMaterials(), 1, self::PERIMETER, 'reaction', self::FACILITY_TAX_PERCENT);

        $this->assertNull($estimate['cost']);
        $this->assertSame('missing_cost_index', $estimate['unknownReason']);
        $this->assertSame([], $estimate['missingAdjustedPriceTypeIds']);
    }

    public function testEstimateJobInstallCostReportsMissingCostIndexBeforeMissingAdjustedPriceAndStillListsTheTypeIds(): void
    {
        $service = $this->createServiceWithSyncedNitrogenFuelBlockInputs(typeIdsWithoutAdjustedPrice: [self::ROBOTICS]);

        $estimate = $service->estimateJobInstallCost($this->nitrogenFuelBlockMaterials(), 1, self::SYSTEM_WITHOUT_COST_INDEX, 'manufacturing', self::FACILITY_TAX_PERCENT);

        $this->assertNull($estimate['cost']);
        $this->assertSame('missing_cost_index', $estimate['unknownReason']);
        $this->assertSame([self::ROBOTICS], $estimate['missingAdjustedPriceTypeIds']);
    }

    public function testEstimateJobInstallCostOfABlueprintWithoutMaterialIsZero(): void
    {
        $service = $this->createServiceWithSyncedNitrogenFuelBlockInputs();

        $estimate = $service->estimateJobInstallCost([], 1, self::JITA, 'manufacturing', self::FACILITY_TAX_PERCENT);

        $this->assertSame(0.0, $estimate['cost']);
        $this->assertNull($estimate['unknownReason']);
        $this->assertSame([], $estimate['missingAdjustedPriceTypeIds']);
    }

    public function testEstimateJobInstallCostOfNitrogenFuelBlockInventionAddsSystemFacilityTaxAndSccSurchargeOnTheBase(): void
    {
        $service = $this->createServiceWithSyncedNitrogenFuelBlockInputs();

        $estimate = $service->estimateJobInstallCost($this->nitrogenFuelBlockMaterials(), 1, self::JITA, 'invention', self::FACILITY_TAX_PERCENT);

        $this->assertEqualsWithDelta(74.9372096, $estimate['cost'], 1e-6);
        $this->assertNull($estimate['unknownReason']);
        $this->assertSame([], $estimate['missingAdjustedPriceTypeIds']);
    }

    public function testEstimateJobInstallCostOfNitrogenFuelBlockInventionReadsNullFacilityTaxAsZeroWithSccSurcharge(): void
    {
        $service = $this->createServiceWithSyncedNitrogenFuelBlockInputs();

        $estimate = $service->estimateJobInstallCost($this->nitrogenFuelBlockMaterials(), 1, self::JITA, 'invention', null);

        $this->assertEqualsWithDelta(40.8748416, $estimate['cost'], 1e-6);
        $this->assertNull($estimate['unknownReason']);
        $this->assertSame([], $estimate['missingAdjustedPriceTypeIds']);
    }

    public function testEstimateJobInstallCostOfInventionWithOneMaterialOfTenMillionAdjustedPriceMatchesTheReferenceVector(): void
    {
        $service = $this->createServiceWithSyncedNitrogenFuelBlockInputs();

        $estimate = $service->estimateJobInstallCost([['materialTypeId' => self::MORPHITE, 'quantity' => 1]], 1, self::JITA, 'invention', 5.0);

        $this->assertEqualsWithDelta(34_000.0, $estimate['cost'], 1e-6);
        $this->assertNull($estimate['unknownReason']);
        $this->assertSame([], $estimate['missingAdjustedPriceTypeIds']);
    }

    public function testEstimateJobInstallCostOfInventionIsUnknownWhenTheSystemHasNoInventionCostIndex(): void
    {
        $service = $this->createServiceWithSyncedNitrogenFuelBlockInputs();

        $estimate = $service->estimateJobInstallCost($this->nitrogenFuelBlockMaterials(), 1, self::PERIMETER, 'invention', self::FACILITY_TAX_PERCENT);

        $this->assertNull($estimate['cost']);
        $this->assertSame('missing_cost_index', $estimate['unknownReason']);
        $this->assertSame([], $estimate['missingAdjustedPriceTypeIds']);
    }

    // ===========================================
    // Helpers
    // ===========================================

    /**
     * @param array<string, list<MockResponse>> $esiResponsesByPath responses consumed in order, per path
     */
    private function createService(array $esiResponsesByPath, string $esiBaseUrl, CacheItemPoolInterface $esiCostIndexCache, LoggerInterface $logger = new NullLogger()): EsiCostIndexService
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
            logger: $logger,
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

    private function createServiceWithSyncedNitrogenFuelBlockInputs(array $typeIdsWithoutAdjustedPrice = []): EsiCostIndexService
    {
        $adjustedPrices = [];
        foreach (array_keys(self::NITROGEN_FUEL_BLOCK_MATERIALS) as $typeId) {
            if (!in_array($typeId, $typeIdsWithoutAdjustedPrice, true)) {
                $adjustedPrices[] = ['type_id' => $typeId, 'adjusted_price' => $typeId / self::ADJUSTED_PRICE_DIVISOR];
            }
        }
        $adjustedPrices[] = ['type_id' => self::MORPHITE, 'adjusted_price' => self::MORPHITE_ADJUSTED_PRICE];

        $service = $this->createService([
            self::ADJUSTED_PRICES_PATH => [$this->jsonResponse($adjustedPrices)],
            self::COST_INDICES_PATH => [$this->jsonResponse([
                ['solar_system_id' => self::JITA, 'cost_indices' => [
                    ['activity' => 'manufacturing', 'cost_index' => self::JITA_MANUFACTURING_COST_INDEX],
                    ['activity' => 'reaction', 'cost_index' => self::JITA_REACTION_COST_INDEX],
                    ['activity' => 'invention', 'cost_index' => self::JITA_INVENTION_COST_INDEX],
                ]],
                ['solar_system_id' => self::PERIMETER, 'cost_indices' => [
                    ['activity' => 'manufacturing', 'cost_index' => self::PERIMETER_MANUFACTURING_COST_INDEX],
                ]],
            ])],
        ], self::PRODUCTION_ESI_BASE_URL, new ArrayAdapter());

        $service->syncAdjustedPrices();
        $service->syncCostIndices();

        return $service;
    }

    private function nitrogenFuelBlockMaterials(): array
    {
        $materials = [];
        foreach (self::NITROGEN_FUEL_BLOCK_MATERIALS as $typeId => $quantity) {
            $materials[] = ['materialTypeId' => $typeId, 'quantity' => $quantity];
        }

        return $materials;
    }

    /**
     * @return list<array{type_id: int, adjusted_price?: float, average_price?: float}>
     */
    private function threeAdjustedPrices(): array
    {
        return [
            ['type_id' => self::TRITANIUM, 'adjusted_price' => 5.12, 'average_price' => 4.98],
            ['type_id' => self::MEXALLON, 'average_price' => 60.4],
            ['type_id' => self::PYERITE, 'adjusted_price' => 11.75],
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
