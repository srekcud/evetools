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
    // findBestPrice Tests (via reflection since private)
    // ===========================================

    /**
     * @param array<array<string, mixed>> $orders
     */
    #[DataProvider('findBestPriceProvider')]
    public function testFindBestPrice(array $orders, ?float $expected): void
    {
        $result = $this->invokeFindBestPrice($orders);

        if ($expected === null) {
            $this->assertNull($result);
        } else {
            $this->assertSame($expected, $result);
        }
    }

    /**
     * @return iterable<string, array{orders: array, expected: ?float}>
     */
    public static function findBestPriceProvider(): iterable
    {
        yield 'empty orders returns null' => [
            'orders' => [],
            'expected' => null,
        ];

        yield 'buy orders only returns null' => [
            'orders' => [
                ['price' => 100.0, 'is_buy_order' => true, 'location_id' => 60003760],
                ['price' => 200.0, 'is_buy_order' => true, 'location_id' => 60003760],
            ],
            'expected' => null,
        ];

        yield 'single sell order in Jita station' => [
            'orders' => [
                ['price' => 150.50, 'is_buy_order' => false, 'location_id' => 60003760],
            ],
            'expected' => 150.50,
        ];

        yield 'prefers Jita station over cheaper region order' => [
            'orders' => [
                ['price' => 100.0, 'is_buy_order' => false, 'location_id' => 99999999], // Cheaper but not Jita
                ['price' => 200.0, 'is_buy_order' => false, 'location_id' => 60003760], // Jita station
            ],
            'expected' => 200.0,
        ];

        yield 'falls back to region price when no Jita orders' => [
            'orders' => [
                ['price' => 300.0, 'is_buy_order' => false, 'location_id' => 1028858195912],
                ['price' => 250.0, 'is_buy_order' => false, 'location_id' => 1035466617946],
            ],
            'expected' => 250.0,
        ];

        yield 'picks lowest sell in Jita station' => [
            'orders' => [
                ['price' => 500.0, 'is_buy_order' => false, 'location_id' => 60003760],
                ['price' => 300.0, 'is_buy_order' => false, 'location_id' => 60003760],
                ['price' => 400.0, 'is_buy_order' => false, 'location_id' => 60003760],
            ],
            'expected' => 300.0,
        ];

        yield 'ignores buy orders when finding best sell' => [
            'orders' => [
                ['price' => 10.0, 'is_buy_order' => true, 'location_id' => 60003760],
                ['price' => 100.0, 'is_buy_order' => false, 'location_id' => 60003760],
            ],
            'expected' => 100.0,
        ];

        yield 'mixed Jita and region sell orders prefer Jita' => [
            'orders' => [
                ['price' => 50.0, 'is_buy_order' => false, 'location_id' => 99999999],
                ['price' => 80.0, 'is_buy_order' => false, 'location_id' => 60003760],
                ['price' => 60.0, 'is_buy_order' => false, 'location_id' => 60003760],
            ],
            'expected' => 60.0,
        ];
    }

    // ===========================================
    // getPrice Tests
    // ===========================================

    public function testGetPriceReturnsPriceFromCache(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([34 => 5.50, 35 => 12.00]);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $this->assertSame(5.50, $this->service->getPrice(34));
        $this->assertSame(12.00, $this->service->getPrice(35));
    }

    public function testGetPriceReturnsNullForUnknownType(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([34 => 5.50]);

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
        $cacheItem->method('get')->willReturn([34 => 5.50, 35 => 12.00, 36 => 8.0]);

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
        $cacheItem->method('get')->willReturn([34 => 5.50]);

        $this->cache->method('getItem')->willReturn($cacheItem);

        $result = $this->service->getPrices([]);

        $this->assertSame([], $result);
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

    private function invokeFindBestPrice(array $orders): ?float
    {
        $reflection = new \ReflectionMethod(JitaMarketService::class, 'findBestPrice');

        return $reflection->invoke($this->service, $orders);
    }
}
