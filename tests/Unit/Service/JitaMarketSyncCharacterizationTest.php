<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Constant\EveConstants;
use App\Service\JitaMarketService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

#[CoversClass(JitaMarketService::class)]
final class JitaMarketSyncCharacterizationTest extends TestCase
{
    use SimulatesJitaMarketEsi;

    private const int JITA_STATION = EveConstants::JITA_STATION_ID;

    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int ISOGEN = 37;
    private const int VELDSPAR = 1230;
    private const int BACTERIA = 2393;

    private const string SELL_ORDER_BOOK_KEY = 'jita_market_prices';
    private const string BUY_ORDER_BOOK_KEY = 'jita_market_buy_prices';
    private const string SYNC_META_KEY = 'jita_market_meta';
    private const int ORDER_BOOK_CACHE_SECONDS = 7200;

    private MockClock $clock;
    private ArrayAdapter $marketCache;

    protected function setUp(): void
    {
        $this->requestedEsiUrls = [];
        $this->clock = new MockClock();
        $this->marketCache = new ArrayAdapter(clock: $this->clock);
    }

    public function testSyncStoresTheSellAndBuyOrderBooksOfEveryIndustryMaterial(): void
    {
        $service = $this->createSyncService([self::TRITANIUM, self::PYERITE, self::MEXALLON, self::ISOGEN], [
            $this->theForgeOrdersRoute(self::TRITANIUM) => [$this->tritaniumOrders()],
            $this->theForgeOrdersRoute(self::PYERITE) => [$this->pyeriteOrders()],
            $this->theForgeOrdersRoute(self::MEXALLON) => [$this->mexallonOrders()],
            $this->theForgeOrdersRoute(self::ISOGEN) => [$this->isogenOrders()],
        ]);
        $syncStartedAt = new \DateTimeImmutable();

        $result = $service->syncJitaMarket();

        $this->assertSame(['success', 'typeCount', 'duration'], array_keys($result));
        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['typeCount']);
        $this->assertGreaterThanOrEqual(0.0, $result['duration']);
        $this->assertLessThan(1.0, $result['duration']);
        $this->assertSame([
            self::TRITANIUM => [['price' => 3.875, 'volume' => 1200], ['price' => 4.125, 'volume' => 650]],
            self::PYERITE => [['price' => 9.75, 'volume' => 410], ['price' => 10.0625, 'volume' => 275]],
            self::MEXALLON => [['price' => 58.75, 'volume' => 93], ['price' => 61.5, 'volume' => 37]],
        ], $this->marketCache->getItem(self::SELL_ORDER_BOOK_KEY)->get());
        $this->assertSame([
            self::TRITANIUM => [['price' => 3.375, 'volume' => 5300], ['price' => 3.25, 'volume' => 800]],
            self::PYERITE => [['price' => 8.625, 'volume' => 1900]],
            self::ISOGEN => [['price' => 47.125, 'volume' => 120]],
        ], $this->marketCache->getItem(self::BUY_ORDER_BOOK_KEY)->get());
        $this->assertSame(3, $this->marketCache->getItem(self::SYNC_META_KEY)->get()['typeCount']);
        $syncedAt = $service->getLastSyncTime();
        $this->assertNotNull($syncedAt);
        $this->assertGreaterThanOrEqual($syncStartedAt, $syncedAt);
        $this->assertLessThanOrEqual(new \DateTimeImmutable(), $syncedAt);
        $this->assertSame([
            $this->theForgeOrdersUrl(self::TRITANIUM),
            $this->theForgeOrdersUrl(self::PYERITE),
            $this->theForgeOrdersUrl(self::MEXALLON),
            $this->theForgeOrdersUrl(self::ISOGEN),
        ], $this->esiUrlsWithoutPage($this->requestedEsiUrls));
    }

    public function testSyncReadsTheUnionOfIndustryMaterialsOresReprocessOutputsAndPlanetaryCommoditiesOnce(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturnCallback(static fn (string $sql): array => match (true) {
            str_contains($sql, 'sde_industry_activity_materials') => [(string) self::TRITANIUM, (string) self::PYERITE],
            str_contains($sql, 'c.category_id = 25') => [(string) self::VELDSPAR, (string) self::PYERITE],
            str_contains($sql, 'sde_inv_type_materials') => [(string) self::TRITANIUM, (string) self::MEXALLON],
            str_contains($sql, 'market_group_id IN (1334, 1335, 1336, 1337)') => [(string) self::BACTERIA],
        });
        $service = $this->createJitaMarketServiceOnSimulatedEsi([
            $this->theForgeOrdersRoute(self::TRITANIUM) => [$this->tritaniumOrders()],
            $this->theForgeOrdersRoute(self::PYERITE) => [$this->pyeriteOrders()],
            $this->theForgeOrdersRoute(self::VELDSPAR) => [[$this->esiSellOrder(self::VELDSPAR, 17.375, 2240, self::JITA_STATION)]],
            $this->theForgeOrdersRoute(self::MEXALLON) => [$this->mexallonOrders()],
            $this->theForgeOrdersRoute(self::BACTERIA) => [[$this->esiSellOrder(self::BACTERIA, 431.5, 58, self::JITA_STATION)]],
        ], $this->marketCache, $connection);

        $result = $service->syncJitaMarket();

        $this->assertSame(5, $result['typeCount']);
        $this->assertSame([
            $this->theForgeOrdersUrl(self::TRITANIUM),
            $this->theForgeOrdersUrl(self::PYERITE),
            $this->theForgeOrdersUrl(self::VELDSPAR),
            $this->theForgeOrdersUrl(self::MEXALLON),
            $this->theForgeOrdersUrl(self::BACTERIA),
        ], $this->esiUrlsWithoutPage($this->requestedEsiUrls));
        $this->assertSame(
            [self::TRITANIUM => 3.875, self::PYERITE => 9.75, self::VELDSPAR => 17.375, self::MEXALLON => 58.75, self::BACTERIA => 431.5],
            $service->getPrices([self::TRITANIUM, self::PYERITE, self::VELDSPAR, self::MEXALLON, self::BACTERIA]),
        );
    }

    public function testSyncRequestsATypeListedByTwoSourcesOnceEvenAcrossTwoBatches(): void
    {
        $firstBatchTypeIds = range(self::TRITANIUM, self::TRITANIUM + 19);
        $lastFirstBatchTypeId = self::TRITANIUM + 19;
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturnCallback(static fn (string $sql): array => match (true) {
            str_contains($sql, 'sde_industry_activity_materials') => array_map('strval', $firstBatchTypeIds),
            str_contains($sql, 'c.category_id = 25') => [(string) $lastFirstBatchTypeId, (string) self::VELDSPAR],
            str_contains($sql, 'sde_inv_type_materials') => [(string) self::VELDSPAR],
            str_contains($sql, 'market_group_id IN (1334, 1335, 1336, 1337)') => [(string) self::TRITANIUM],
        });
        $syncedTypeIds = [...$firstBatchTypeIds, self::VELDSPAR];
        $esiResponsesByRoute = [];
        foreach ($syncedTypeIds as $typeId) {
            $esiResponsesByRoute[$this->theForgeOrdersRoute($typeId)] = [[$this->esiSellOrder($typeId, $typeId + 0.375, 3 * $typeId + 11, self::JITA_STATION)]];
        }
        $service = $this->createJitaMarketServiceOnSimulatedEsi($esiResponsesByRoute, $this->marketCache, $connection);

        $result = $service->syncJitaMarket();

        $this->assertSame(21, $result['typeCount']);
        $this->assertSame(
            array_map($this->theForgeOrdersUrl(...), $syncedTypeIds),
            $this->esiUrlsWithoutPage($this->requestedEsiUrls),
        );
        $this->assertSame([$lastFirstBatchTypeId => 53.375, self::VELDSPAR => 1230.375], $service->getPrices([$lastFirstBatchTypeId, self::VELDSPAR]));
    }

    public function testSyncedOrderBooksGiveTheBestPriceAndTheWeightedPriceOfEachType(): void
    {
        $service = $this->createSyncService([self::TRITANIUM, self::PYERITE, self::MEXALLON, self::ISOGEN], [
            $this->theForgeOrdersRoute(self::TRITANIUM) => [$this->tritaniumOrders()],
            $this->theForgeOrdersRoute(self::PYERITE) => [$this->pyeriteOrders()],
            $this->theForgeOrdersRoute(self::MEXALLON) => [$this->mexallonOrders()],
            $this->theForgeOrdersRoute(self::ISOGEN) => [$this->isogenOrders()],
        ]);

        $service->syncJitaMarket();

        $this->assertSame(
            [self::TRITANIUM => 3.875, self::PYERITE => 9.75, self::MEXALLON => 58.75, self::ISOGEN => null],
            $service->getPrices([self::TRITANIUM, self::PYERITE, self::MEXALLON, self::ISOGEN]),
        );
        $this->assertSame(
            [self::TRITANIUM => 3.375, self::PYERITE => 8.625, self::MEXALLON => null, self::ISOGEN => 47.125],
            $service->getBuyPrices([self::TRITANIUM, self::PYERITE, self::MEXALLON, self::ISOGEN]),
        );
        $this->assertSame([
            self::TRITANIUM => ['weightedPrice' => 3.925, 'coverage' => 1.0, 'ordersUsed' => 2],
            self::PYERITE => ['weightedPrice' => 9.80625, 'coverage' => 1.0, 'ordersUsed' => 2],
            self::MEXALLON => ['weightedPrice' => 7739.25 / 130, 'coverage' => 0.65, 'ordersUsed' => 2],
            self::ISOGEN => null,
        ], $service->getWeightedSellPrices([self::TRITANIUM => 1500, self::PYERITE => 500, self::MEXALLON => 200, self::ISOGEN => 60]));
        $this->assertSame([
            self::TRITANIUM => ['weightedPrice' => 20162.5 / 6000, 'coverage' => 1.0, 'ordersUsed' => 2],
            self::ISOGEN => ['weightedPrice' => 47.125, 'coverage' => 1.0, 'ordersUsed' => 1],
            self::MEXALLON => null,
        ], $service->getWeightedBuyPrices([self::TRITANIUM => 6000, self::ISOGEN => 90, self::MEXALLON => 10]));
    }

    public function testSyncedOrderBooksAndSyncTimeExpireAfterTwoHours(): void
    {
        $service = $this->createSyncService([self::TRITANIUM], [
            $this->theForgeOrdersRoute(self::TRITANIUM) => [$this->tritaniumOrders()],
        ]);

        $service->syncJitaMarket();

        $this->clock->sleep(self::ORDER_BOOK_CACHE_SECONDS - 1);
        $this->assertSame([self::TRITANIUM => 3.875], $service->getPrices([self::TRITANIUM]));
        $this->assertSame([self::TRITANIUM => 3.375], $service->getBuyPrices([self::TRITANIUM]));
        $this->assertNotNull($service->getLastSyncTime());

        $this->clock->sleep(2);
        $this->assertFalse($service->hasCachedData());
        $this->assertSame([self::TRITANIUM => null], $service->getBuyPrices([self::TRITANIUM]));
        $this->assertNull($service->getLastSyncTime());
    }

    public function testSyncThatCannotListTheIndustryMaterialsFailsAndKeepsThePreviousOrderBooks(): void
    {
        $this->storeInMarketCache(self::SELL_ORDER_BOOK_KEY, [self::PYERITE => [['price' => 9.5, 'volume' => 640]]]);
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willThrowException(new \RuntimeException('SDE tables missing'));
        $service = $this->createJitaMarketServiceOnSimulatedEsi([], $this->marketCache, $connection);

        $result = $service->syncJitaMarket();

        $this->assertSame(['success', 'typeCount', 'duration', 'error'], array_keys($result));
        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['typeCount']);
        $this->assertSame('SDE tables missing', $result['error']);
        $this->assertGreaterThanOrEqual(0.0, $result['duration']);
        $this->assertLessThan(1.0, $result['duration']);
        $this->assertSame([self::PYERITE => 9.5], $service->getPrices([self::PYERITE]));
        $this->assertFalse($this->marketCache->getItem(self::BUY_ORDER_BOOK_KEY)->isHit());
        $this->assertNull($service->getLastSyncTime());
        $this->assertSame([], $this->requestedEsiUrls);
    }

    #[Group('market-audit-3')]
    public function testSyncCurrentlyDropsThePreviousOrderBooksOfATypeWhoseOrdersFailMarketAudit3(): void
    {
        $this->storeInMarketCache(self::SELL_ORDER_BOOK_KEY, [
            self::TRITANIUM => [['price' => 3.9, 'volume' => 77]],
            self::PYERITE => [['price' => 9.5, 'volume' => 640]],
        ]);
        $this->storeInMarketCache(self::BUY_ORDER_BOOK_KEY, [self::PYERITE => [['price' => 8.5, 'volume' => 1500]]]);
        $service = $this->createSyncService([self::TRITANIUM, self::PYERITE, self::MEXALLON], [
            $this->theForgeOrdersRoute(self::TRITANIUM) => [$this->tritaniumOrders()],
            $this->theForgeOrdersRoute(self::PYERITE) => [$this->esiServiceUnavailable()],
            $this->theForgeOrdersRoute(self::MEXALLON) => [$this->mexallonOrders()],
        ]);

        $result = $service->syncJitaMarket();

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['typeCount']);
        $this->assertSame([self::TRITANIUM, self::MEXALLON], array_keys($this->marketCache->getItem(self::SELL_ORDER_BOOK_KEY)->get()));
        $this->assertSame([self::TRITANIUM], array_keys($this->marketCache->getItem(self::BUY_ORDER_BOOK_KEY)->get()));
        $this->assertSame([self::TRITANIUM => 3.875, self::PYERITE => null], $service->getPrices([self::TRITANIUM, self::PYERITE]));
        $this->assertSame([self::PYERITE => null], $service->getBuyPrices([self::PYERITE]));
    }

    #[Group('market-audit-3')]
    public function testSyncCurrentlyWritesEmptyOrderBooksAndReportsSuccessWhenEveryOrdersRequestFailsMarketAudit3(): void
    {
        $this->storeInMarketCache(self::SELL_ORDER_BOOK_KEY, [
            self::TRITANIUM => [['price' => 3.9, 'volume' => 77]],
            self::PYERITE => [['price' => 9.5, 'volume' => 640]],
        ]);
        $this->storeInMarketCache(self::BUY_ORDER_BOOK_KEY, [self::PYERITE => [['price' => 8.5, 'volume' => 1500]]]);
        $service = $this->createSyncService([self::TRITANIUM, self::PYERITE], [
            $this->theForgeOrdersRoute(self::TRITANIUM) => [$this->esiServiceUnavailable()],
            $this->theForgeOrdersRoute(self::PYERITE) => [$this->esiServiceUnavailable()],
        ]);

        $result = $service->syncJitaMarket();

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['typeCount']);
        $this->assertSame([], $this->marketCache->getItem(self::SELL_ORDER_BOOK_KEY)->get());
        $this->assertSame([], $this->marketCache->getItem(self::BUY_ORDER_BOOK_KEY)->get());
        $this->assertTrue($service->hasCachedData());
        $this->assertSame(0, $this->marketCache->getItem(self::SYNC_META_KEY)->get()['typeCount']);
        $this->assertNotNull($service->getLastSyncTime());
        $this->assertSame([self::TRITANIUM => null, self::PYERITE => null], $service->getPrices([self::TRITANIUM, self::PYERITE]));
    }

    #[Group('market-audit-5')]
    public function testSyncCurrentlyReadsOnlyTheFirstPageOfOrdersMarketAudit5(): void
    {
        $service = $this->createSyncService([self::TRITANIUM], [
            $this->theForgeOrdersRoute(self::TRITANIUM) => [$this->esiJsonResponse($this->tritaniumOrders(), ['X-Pages' => '2'])],
            $this->theForgeOrdersRoute(self::TRITANIUM, 2) => [[$this->esiSellOrder(self::TRITANIUM, 3.5, 2900, self::JITA_STATION)]],
        ]);

        $service->syncJitaMarket();

        $this->assertSame([$this->theForgeOrdersUrl(self::TRITANIUM)], $this->requestedEsiUrls);
        $this->assertSame(
            [['price' => 3.875, 'volume' => 1200], ['price' => 4.125, 'volume' => 650]],
            $service->getSellOrders(self::TRITANIUM),
        );
        $this->assertSame(3.875, $service->getPrice(self::TRITANIUM));
    }

    #[Group('market-audit-5')]
    public function testOnDemandPriceCurrentlyReadsOnlyTheFirstPageOfOrdersMarketAudit5(): void
    {
        $service = $this->createSyncService([], [
            $this->theForgeOrdersRoute(self::PYERITE) => [$this->esiJsonResponse($this->pyeriteOrders(), ['X-Pages' => '3'])],
            $this->theForgeOrdersRoute(self::PYERITE, 2) => [[$this->esiSellOrder(self::PYERITE, 9.25, 3100, self::JITA_STATION)]],
        ]);

        $prices = $service->getPricesWithFallback([self::PYERITE]);

        $this->assertSame([self::PYERITE => 9.75], $prices);
        $this->assertSame([$this->theForgeOrdersUrl(self::PYERITE)], $this->requestedEsiUrls);
        $this->assertSame([
            'sell' => [['price' => 9.75, 'volume' => 410], ['price' => 10.0625, 'volume' => 275]],
            'buy' => [['price' => 8.625, 'volume' => 1900]],
        ], $this->marketCache->getItem('jita_ondemand_' . self::PYERITE)->get());
    }

    public function testRefreshOfNoTypeReturnsAnEmptySuccessWithoutReadingEsi(): void
    {
        $service = $this->createSyncService([], []);

        $result = $service->refreshPricesForTypes([]);

        $this->assertSame(['success' => true, 'typeCount' => 0, 'duration' => 0.0], $result);
        $this->assertSame([], $this->requestedEsiUrls);
        $this->assertFalse($service->hasCachedData());
    }

    public function testRefreshThatFailsReportsTheErrorAndLeavesTheOrderBooksUntouched(): void
    {
        $this->storeInMarketCache(self::SELL_ORDER_BOOK_KEY, [self::PYERITE => [['price' => 9.5, 'volume' => 640]]]);
        $service = $this->createSyncService([], [
            $this->theForgeOrdersRoute(self::TRITANIUM) => [new \RuntimeException('ESI unreachable')],
        ]);

        $result = $service->refreshPricesForTypes([self::TRITANIUM]);

        $this->assertSame(['success', 'typeCount', 'duration', 'error'], array_keys($result));
        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['typeCount']);
        $this->assertSame('ESI unreachable', $result['error']);
        $this->assertGreaterThanOrEqual(0.0, $result['duration']);
        $this->assertLessThan(1.0, $result['duration']);
        $this->assertSame([self::PYERITE => [['price' => 9.5, 'volume' => 640]]], $this->marketCache->getItem(self::SELL_ORDER_BOOK_KEY)->get());
        $this->assertFalse($this->marketCache->getItem(self::BUY_ORDER_BOOK_KEY)->isHit());
    }

    #[Group('market-audit-6')]
    public function testRefreshWithoutMainOrderBooksCurrentlyCreatesThemWithOnlyTheRefreshedTypesMarketAudit6(): void
    {
        $service = $this->createSyncService([], [
            $this->theForgeOrdersRoute(self::TRITANIUM) => [$this->tritaniumOrders()],
            $this->theForgeOrdersRoute(self::PYERITE) => [$this->pyeriteOrders()],
        ]);

        $result = $service->refreshPricesForTypes([self::TRITANIUM, self::PYERITE]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['typeCount']);
        $this->assertTrue($service->hasCachedData());
        $this->assertNull($service->getLastSyncTime());
        $this->assertSame([self::TRITANIUM, self::PYERITE], array_keys($this->marketCache->getItem(self::SELL_ORDER_BOOK_KEY)->get()));
        $this->assertSame([self::TRITANIUM, self::PYERITE], array_keys($this->marketCache->getItem(self::BUY_ORDER_BOOK_KEY)->get()));
        $this->assertSame(
            [self::TRITANIUM => 3.875, self::PYERITE => 9.75, self::MEXALLON => null],
            $service->getPrices([self::TRITANIUM, self::PYERITE, self::MEXALLON]),
        );
    }

    #[Group('market-audit-6')]
    public function testRefreshCurrentlyCountsTypesWhoseOrdersFailedAsRefreshedMarketAudit6(): void
    {
        $service = $this->createSyncService([], [
            $this->theForgeOrdersRoute(self::TRITANIUM) => [$this->esiServiceUnavailable()],
            $this->theForgeOrdersRoute(self::PYERITE) => [$this->esiServiceUnavailable()],
        ]);

        $result = $service->refreshPricesForTypes([self::TRITANIUM, self::PYERITE]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['typeCount']);
        $this->assertSame([], $this->marketCache->getItem(self::SELL_ORDER_BOOK_KEY)->get());
        $this->assertSame([], $this->marketCache->getItem(self::BUY_ORDER_BOOK_KEY)->get());
        $this->assertTrue($service->hasCachedData());
    }

    #[Group('market-audit-6')]
    public function testRefreshCurrentlyKeepsTheStaleOrderBooksOfATypeWhoseOrdersFailedMarketAudit6(): void
    {
        $this->storeInMarketCache(self::SELL_ORDER_BOOK_KEY, [self::PYERITE => [['price' => 9.5, 'volume' => 640]]]);
        $this->storeInMarketCache(self::BUY_ORDER_BOOK_KEY, [self::PYERITE => [['price' => 8.5, 'volume' => 1500]]]);
        $service = $this->createSyncService([], [
            $this->theForgeOrdersRoute(self::PYERITE) => [$this->esiServiceUnavailable()],
        ]);

        $result = $service->refreshPricesForTypes([self::PYERITE]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['typeCount']);
        $this->assertSame([self::PYERITE => 9.5], $service->getPrices([self::PYERITE]));
        $this->assertSame([self::PYERITE => 8.5], $service->getBuyPrices([self::PYERITE]));
    }

    #[Group('market-audit-15')]
    public function testBuyOrderBookCurrentlyIgnoresARegionRangeBuyOrderOutsideJitaStationMarketAudit15(): void
    {
        $service = $this->createSyncService([self::TRITANIUM], [
            $this->theForgeOrdersRoute(self::TRITANIUM) => [[
                ...$this->tritaniumOrders(),
                $this->esiBuyOrder(self::TRITANIUM, 3.5, 2600, self::PERIMETER_STATION_ID, 'region'),
            ]],
        ]);

        $service->syncJitaMarket();

        $this->assertSame(
            [['price' => 3.375, 'volume' => 5300], ['price' => 3.25, 'volume' => 800]],
            $service->getBuyOrders(self::TRITANIUM),
        );
        $this->assertSame([self::TRITANIUM => 3.375], $service->getBuyPrices([self::TRITANIUM]));
    }

    #[Group('market-audit-15')]
    public function testWeightedBuyPriceCurrentlyUsesABuyOrderBelowItsMinimumVolumeMarketAudit15(): void
    {
        $service = $this->createSyncService([self::TRITANIUM], [
            $this->theForgeOrdersRoute(self::TRITANIUM) => [[
                ...$this->tritaniumOrders(),
                $this->esiBuyOrder(self::TRITANIUM, 3.5, 2600, self::JITA_STATION, 'station', 1000),
            ]],
        ]);

        $service->syncJitaMarket();

        $this->assertSame(
            [['price' => 3.5, 'volume' => 2600], ['price' => 3.375, 'volume' => 5300], ['price' => 3.25, 'volume' => 800]],
            $service->getBuyOrders(self::TRITANIUM),
        );
        $this->assertSame([self::TRITANIUM => 3.5], $service->getBuyPrices([self::TRITANIUM]));
        $this->assertSame(
            [self::TRITANIUM => ['weightedPrice' => 3.5, 'coverage' => 1.0, 'ordersUsed' => 1]],
            $service->getWeightedBuyPrices([self::TRITANIUM => 40]),
        );
    }

    private function createSyncService(array $industryMaterialTypeIds, array $esiResponsesByRoute): JitaMarketService
    {
        return $this->createJitaMarketServiceOnSimulatedEsi(
            $esiResponsesByRoute,
            $this->marketCache,
            $this->industryMaterialsConnection($industryMaterialTypeIds),
        );
    }

    private function tritaniumOrders(): array
    {
        return [
            $this->esiSellOrder(self::TRITANIUM, 4.125, 650, self::JITA_STATION),
            $this->esiBuyOrder(self::TRITANIUM, 3.25, 800, self::JITA_STATION),
            $this->esiSellOrder(self::TRITANIUM, 3.875, 1200, self::JITA_STATION),
            $this->esiBuyOrder(self::TRITANIUM, 3.375, 5300, self::JITA_STATION),
        ];
    }

    private function pyeriteOrders(): array
    {
        return [
            $this->esiSellOrder(self::PYERITE, 10.0625, 275, self::JITA_STATION),
            $this->esiBuyOrder(self::PYERITE, 8.625, 1900, self::JITA_STATION),
            $this->esiSellOrder(self::PYERITE, 9.75, 410, self::JITA_STATION),
        ];
    }

    private function mexallonOrders(): array
    {
        return [
            $this->esiSellOrder(self::MEXALLON, 61.5, 37, self::PERIMETER_STATION_ID),
            $this->esiSellOrder(self::MEXALLON, 58.75, 93, self::PERIMETER_STATION_ID),
        ];
    }

    private function isogenOrders(): array
    {
        return [
            $this->esiBuyOrder(self::ISOGEN, 47.125, 120, self::JITA_STATION),
        ];
    }

    private function storeInMarketCache(string $key, mixed $value): void
    {
        $cacheItem = $this->marketCache->getItem($key);
        $cacheItem->set($value);
        $this->marketCache->save($cacheItem);
    }
}
