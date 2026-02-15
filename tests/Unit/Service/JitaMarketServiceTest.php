<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\JitaMarketService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(JitaMarketService::class)]
class JitaMarketServiceTest extends TestCase
{
    private CacheItemPoolInterface $cache;
    private JitaMarketService $service;

    protected function setUp(): void
    {
        $this->cache = $this->createStub(CacheItemPoolInterface::class);

        $this->service = new JitaMarketService(
            $this->createStub(HttpClientInterface::class),
            $this->cache,
            $this->createStub(Connection::class),
            new NullLogger(),
        );
    }

    // ===========================================
    // collectOrderBooks Tests (via reflection since private)
    // ===========================================

    /**
     * @param array<array<string, mixed>> $orders
     * @param array{sell: list<array{price: float, volume: int}>, buy: list<array{price: float, volume: int}>} $expected
     */
    #[DataProvider('collectOrderBooksProvider')]
    public function testCollectOrderBooks(array $orders, array $expected): void
    {
        $result = $this->invokeCollectOrderBooks($orders);

        $this->assertSame($expected['sell'], $result['sell']);
        $this->assertSame($expected['buy'], $result['buy']);
    }

    /**
     * @return iterable<string, array{orders: array, expected: array{sell: list<array{price: float, volume: int}>, buy: list<array{price: float, volume: int}>}}>
     */
    public static function collectOrderBooksProvider(): iterable
    {
        yield 'empty orders returns empty arrays' => [
            'orders' => [],
            'expected' => ['sell' => [], 'buy' => []],
        ];

        yield 'buy orders only returns empty sell' => [
            'orders' => [
                ['price' => 100.0, 'volume_remain' => 500, 'is_buy_order' => true, 'location_id' => 60003760],
                ['price' => 200.0, 'volume_remain' => 300, 'is_buy_order' => true, 'location_id' => 60003760],
            ],
            'expected' => [
                'sell' => [],
                'buy' => [
                    ['price' => 200.0, 'volume' => 300],
                    ['price' => 100.0, 'volume' => 500],
                ],
            ],
        ];

        yield 'single sell order in Jita station' => [
            'orders' => [
                ['price' => 150.50, 'volume_remain' => 1000, 'is_buy_order' => false, 'location_id' => 60003760],
            ],
            'expected' => [
                'sell' => [['price' => 150.50, 'volume' => 1000]],
                'buy' => [],
            ],
        ];

        yield 'prefers Jita station over region orders' => [
            'orders' => [
                ['price' => 100.0, 'volume_remain' => 200, 'is_buy_order' => false, 'location_id' => 99999999],
                ['price' => 200.0, 'volume_remain' => 500, 'is_buy_order' => false, 'location_id' => 60003760],
            ],
            'expected' => [
                'sell' => [['price' => 200.0, 'volume' => 500]],
                'buy' => [],
            ],
        ];

        yield 'falls back to region when no Jita orders' => [
            'orders' => [
                ['price' => 300.0, 'volume_remain' => 100, 'is_buy_order' => false, 'location_id' => 1028858195912],
                ['price' => 250.0, 'volume_remain' => 200, 'is_buy_order' => false, 'location_id' => 1035466617946],
            ],
            'expected' => [
                'sell' => [
                    ['price' => 250.0, 'volume' => 200],
                    ['price' => 300.0, 'volume' => 100],
                ],
                'buy' => [],
            ],
        ];

        yield 'sell orders sorted ascending by price' => [
            'orders' => [
                ['price' => 500.0, 'volume_remain' => 100, 'is_buy_order' => false, 'location_id' => 60003760],
                ['price' => 300.0, 'volume_remain' => 200, 'is_buy_order' => false, 'location_id' => 60003760],
                ['price' => 400.0, 'volume_remain' => 150, 'is_buy_order' => false, 'location_id' => 60003760],
            ],
            'expected' => [
                'sell' => [
                    ['price' => 300.0, 'volume' => 200],
                    ['price' => 400.0, 'volume' => 150],
                    ['price' => 500.0, 'volume' => 100],
                ],
                'buy' => [],
            ],
        ];

        yield 'buy orders sorted descending by price' => [
            'orders' => [
                ['price' => 100.0, 'volume_remain' => 500, 'is_buy_order' => true, 'location_id' => 60003760],
                ['price' => 300.0, 'volume_remain' => 200, 'is_buy_order' => true, 'location_id' => 60003760],
                ['price' => 200.0, 'volume_remain' => 300, 'is_buy_order' => true, 'location_id' => 60003760],
            ],
            'expected' => [
                'sell' => [],
                'buy' => [
                    ['price' => 300.0, 'volume' => 200],
                    ['price' => 200.0, 'volume' => 300],
                    ['price' => 100.0, 'volume' => 500],
                ],
            ],
        ];

        yield 'mixed buy and sell orders' => [
            'orders' => [
                ['price' => 10.0, 'volume_remain' => 100, 'is_buy_order' => true, 'location_id' => 60003760],
                ['price' => 100.0, 'volume_remain' => 500, 'is_buy_order' => false, 'location_id' => 60003760],
            ],
            'expected' => [
                'sell' => [['price' => 100.0, 'volume' => 500]],
                'buy' => [['price' => 10.0, 'volume' => 100]],
            ],
        ];
    }

    // ===========================================
    // getPrice Tests
    // ===========================================

    public function testGetPriceReturnsBestPriceFromOrderBook(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([
            34 => [['price' => 5.50, 'volume' => 1000], ['price' => 6.00, 'volume' => 500]],
            35 => [['price' => 12.00, 'volume' => 200]],
        ]);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $this->assertSame(5.50, $this->service->getPrice(34));
        $this->assertSame(12.00, $this->service->getPrice(35));
    }

    public function testGetPriceReturnsNullForUnknownType(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([
            34 => [['price' => 5.50, 'volume' => 1000]],
        ]);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $this->assertNull($this->service->getPrice(99999));
    }

    public function testGetPriceReturnsNullWhenCacheEmpty(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $this->assertNull($this->service->getPrice(34));
    }

    // ===========================================
    // getPrices Tests
    // ===========================================

    public function testGetPricesReturnsMultiplePrices(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([
            34 => [['price' => 5.50, 'volume' => 1000]],
            35 => [['price' => 12.00, 'volume' => 200]],
            36 => [['price' => 8.0, 'volume' => 500]],
        ]);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $result = $this->service->getPrices([34, 36, 999]);

        $this->assertSame(5.50, $result[34]);
        $this->assertSame(8.0, $result[36]);
        $this->assertNull($result[999]);
    }

    public function testGetPricesReturnsAllNullsWhenCacheEmpty(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $result = $this->service->getPrices([34, 35]);

        $this->assertNull($result[34]);
        $this->assertNull($result[35]);
    }

    public function testGetPricesWithEmptyArray(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([
            34 => [['price' => 5.50, 'volume' => 1000]],
        ]);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $result = $this->service->getPrices([]);

        $this->assertSame([], $result);
    }

    // ===========================================
    // getBuyPrice Tests
    // ===========================================

    public function testGetBuyPriceReturnsBestPriceFromOrderBook(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([
            34 => [['price' => 4.00, 'volume' => 1000], ['price' => 3.50, 'volume' => 500]],
        ]);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $this->assertSame(4.00, $this->service->getBuyPrice(34));
    }

    // ===========================================
    // Weighted Price Tests
    // ===========================================

    public function testGetWeightedSellPriceWithSingleOrder(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([
            34 => [['price' => 5.00, 'volume' => 10000]],
        ]);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $result = $this->service->getWeightedSellPrice(34, 100);

        $this->assertNotNull($result);
        $this->assertSame(5.00, $result['weightedPrice']);
        $this->assertSame(1.0, $result['coverage']);
        $this->assertSame(1, $result['ordersUsed']);
    }

    public function testGetWeightedSellPriceWithMultipleOrders(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([
            34 => [
                ['price' => 5.00, 'volume' => 100],
                ['price' => 6.00, 'volume' => 200],
                ['price' => 7.00, 'volume' => 300],
            ],
        ]);

        $this->cache->method('getItem')->willReturn($cacheItem);

        // Request 250 units: 100 @ 5.00 + 150 @ 6.00 = 500 + 900 = 1400 / 250 = 5.60
        $result = $this->service->getWeightedSellPrice(34, 250);

        $this->assertNotNull($result);
        $this->assertSame(5.60, $result['weightedPrice']);
        $this->assertSame(1.0, $result['coverage']);
        $this->assertSame(2, $result['ordersUsed']);
    }

    public function testGetWeightedSellPriceWithPartialCoverage(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([
            34 => [
                ['price' => 5.00, 'volume' => 50],
                ['price' => 6.00, 'volume' => 50],
            ],
        ]);

        $this->cache->method('getItem')->willReturn($cacheItem);

        // Request 200 units but only 100 available: coverage = 0.5
        $result = $this->service->getWeightedSellPrice(34, 200);

        $this->assertNotNull($result);
        $this->assertSame(5.50, $result['weightedPrice']); // (50*5 + 50*6) / 100
        $this->assertSame(0.5, $result['coverage']);
        $this->assertSame(2, $result['ordersUsed']);
    }

    public function testGetWeightedSellPriceReturnsNullForUnknownType(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([]);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $this->assertNull($this->service->getWeightedSellPrice(99999, 100));
    }

    public function testGetWeightedSellPriceReturnsNullForZeroQuantity(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([
            34 => [['price' => 5.00, 'volume' => 100]],
        ]);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $this->assertNull($this->service->getWeightedSellPrice(34, 0));
    }

    public function testGetWeightedSellPriceReturnsNullWhenCacheEmpty(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $this->assertNull($this->service->getWeightedSellPrice(34, 100));
    }

    public function testGetWeightedBuyPriceWithMultipleOrders(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([
            34 => [
                ['price' => 4.50, 'volume' => 100], // Best buy (highest)
                ['price' => 4.00, 'volume' => 200],
                ['price' => 3.50, 'volume' => 300],
            ],
        ]);

        $this->cache->method('getItem')->willReturn($cacheItem);

        // Request 150: 100 @ 4.50 + 50 @ 4.00 = 450 + 200 = 650 / 150 = 4.333...
        $result = $this->service->getWeightedBuyPrice(34, 150);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(4.333, $result['weightedPrice'], 0.001);
        $this->assertSame(1.0, $result['coverage']);
        $this->assertSame(2, $result['ordersUsed']);
    }

    public function testGetWeightedSellPricesBatch(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([
            34 => [['price' => 5.00, 'volume' => 10000]],
            35 => [['price' => 12.00, 'volume' => 500]],
        ]);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $result = $this->service->getWeightedSellPrices([34 => 100, 35 => 200, 999 => 50]);

        $this->assertNotNull($result[34]);
        $this->assertSame(5.00, $result[34]['weightedPrice']);
        $this->assertNotNull($result[35]);
        $this->assertSame(12.00, $result[35]['weightedPrice']);
        $this->assertNull($result[999]);
    }

    public function testGetWeightedBuyPricesBatch(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([
            34 => [['price' => 4.00, 'volume' => 10000]],
        ]);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $result = $this->service->getWeightedBuyPrices([34 => 100]);

        $this->assertNotNull($result[34]);
        $this->assertSame(4.00, $result[34]['weightedPrice']);
        $this->assertSame(1.0, $result[34]['coverage']);
    }

    // ===========================================
    // hasCachedData Tests
    // ===========================================

    public function testHasCachedDataReturnsTrueWhenCacheHit(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $this->assertTrue($this->service->hasCachedData());
    }

    public function testHasCachedDataReturnsFalseWhenCacheMiss(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $this->assertFalse($this->service->hasCachedData());
    }

    // ===========================================
    // getLastSyncTime Tests
    // ===========================================

    public function testGetLastSyncTimeReturnsDateFromMeta(): void
    {
        $syncTime = new \DateTimeImmutable('2026-02-14 10:00:00');

        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn(['syncedAt' => $syncTime, 'typeCount' => 100]);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $this->assertSame($syncTime, $this->service->getLastSyncTime());
    }

    public function testGetLastSyncTimeReturnsNullWhenNoMeta(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $this->assertNull($this->service->getLastSyncTime());
    }

    // ===========================================
    // Private method invocation helper
    // ===========================================

    /**
     * @param list<array<string, mixed>> $orders
     * @return array{sell: list<array{price: float, volume: int}>, buy: list<array{price: float, volume: int}>}
     */
    private function invokeCollectOrderBooks(array $orders): array
    {
        $reflection = new \ReflectionMethod(JitaMarketService::class, 'collectOrderBooks');

        return $reflection->invoke($this->service, $orders);
    }
}
