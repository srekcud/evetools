<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\JitaMarketService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(JitaMarketService::class)]
class JitaMarketServiceCheapestPercentileTest extends TestCase
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
    // Normal case: multiple orders, partial fill
    // ===========================================

    public function testNormalCaseMultipleOrdersPartialFill(): void
    {
        // 3 orders: total volume = 1000 + 2000 + 7000 = 10000
        // 5% of 10000 = 500 units
        // Cheapest first: 500 units @ 100.0 (all from first order)
        // Weighted avg = 100.0
        $this->configureCacheWithOrders([
            34 => [
                ['price' => 100.0, 'volume' => 1000],
                ['price' => 110.0, 'volume' => 2000],
                ['price' => 120.0, 'volume' => 7000],
            ],
        ]);

        $result = $this->service->getCheapestPercentilePrices([34]);

        $this->assertSame(100.0, $result[34]);
    }

    public function testNormalCaseSpansMultipleOrders(): void
    {
        // 3 orders: total volume = 200 + 300 + 500 = 1000
        // 5% of 1000 = 50 units
        // Cheapest first: 50 units from first order (volume 200) @ 10.0
        // Weighted avg = 10.0
        $this->configureCacheWithOrders([
            34 => [
                ['price' => 10.0, 'volume' => 200],
                ['price' => 20.0, 'volume' => 300],
                ['price' => 30.0, 'volume' => 500],
            ],
        ]);

        $result = $this->service->getCheapestPercentilePrices([34]);

        $this->assertSame(10.0, $result[34]);
    }

    public function testPercentileSpansAcrossOrderBoundary(): void
    {
        // 3 orders: total volume = 100 + 400 + 500 = 1000
        // 20% of 1000 = 200 units
        // Take: 100 @ 5.0 (full first order) + 100 @ 10.0 (partial second order)
        // Weighted avg = (100*5.0 + 100*10.0) / 200 = (500 + 1000) / 200 = 7.5
        $this->configureCacheWithOrders([
            34 => [
                ['price' => 5.0, 'volume' => 100],
                ['price' => 10.0, 'volume' => 400],
                ['price' => 20.0, 'volume' => 500],
            ],
        ]);

        $result = $this->service->getCheapestPercentilePrices([34], 0.20);

        $this->assertSame(7.5, $result[34]);
    }

    // ===========================================
    // Single order
    // ===========================================

    public function testSingleOrderReturnsThatOrderPrice(): void
    {
        // 1 order: total volume = 5000
        // 5% of 5000 = 250 units, all from the single order @ 42.50
        $this->configureCacheWithOrders([
            34 => [
                ['price' => 42.50, 'volume' => 5000],
            ],
        ]);

        $result = $this->service->getCheapestPercentilePrices([34]);

        $this->assertSame(42.50, $result[34]);
    }

    // ===========================================
    // No orders
    // ===========================================

    public function testNoOrdersReturnsNull(): void
    {
        $this->configureCacheWithOrders([
            34 => [],
        ]);

        $result = $this->service->getCheapestPercentilePrices([34]);

        $this->assertNull($result[34]);
    }

    public function testUnknownTypeReturnsNull(): void
    {
        $this->configureCacheWithOrders([
            34 => [['price' => 5.0, 'volume' => 100]],
        ]);

        $result = $this->service->getCheapestPercentilePrices([99999]);

        $this->assertNull($result[99999]);
    }

    public function testCacheMissReturnsNull(): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $this->cache->method('getItem')->willReturn($cacheItem);

        $result = $this->service->getCheapestPercentilePrices([34]);

        $this->assertNull($result[34]);
    }

    // ===========================================
    // Small volume: target < 1 order's volume
    // ===========================================

    public function testSmallPercentileTargetLessThanOneUnit(): void
    {
        // 1 order: total volume = 10
        // 0.05 * 10 = 0.5 (< 1.0) => fallback to cheapest price
        $this->configureCacheWithOrders([
            34 => [
                ['price' => 99.99, 'volume' => 10],
            ],
        ]);

        $result = $this->service->getCheapestPercentilePrices([34]);

        $this->assertSame(99.99, $result[34]);
    }

    public function testVerySmallPercentileUseCheapestOrder(): void
    {
        // total volume = 100 + 200 = 300
        // 0.001 * 300 = 0.3 (< 1.0) => cheapest price
        $this->configureCacheWithOrders([
            34 => [
                ['price' => 5.0, 'volume' => 100],
                ['price' => 10.0, 'volume' => 200],
            ],
        ]);

        $result = $this->service->getCheapestPercentilePrices([34], 0.001);

        $this->assertSame(5.0, $result[34]);
    }

    // ===========================================
    // All same price
    // ===========================================

    public function testAllSamePriceReturnsThatPrice(): void
    {
        $this->configureCacheWithOrders([
            34 => [
                ['price' => 15.0, 'volume' => 100],
                ['price' => 15.0, 'volume' => 200],
                ['price' => 15.0, 'volume' => 300],
            ],
        ]);

        $result = $this->service->getCheapestPercentilePrices([34]);

        $this->assertSame(15.0, $result[34]);
    }

    // ===========================================
    // Multiple typeIds batch
    // ===========================================

    public function testMultipleTypeIdsReturnsCorrectPrices(): void
    {
        $this->configureCacheWithOrders([
            34 => [
                ['price' => 5.0, 'volume' => 1000],
                ['price' => 6.0, 'volume' => 9000],
            ],
            35 => [
                ['price' => 100.0, 'volume' => 2000],
                ['price' => 200.0, 'volume' => 8000],
            ],
            36 => [],
        ]);

        $result = $this->service->getCheapestPercentilePrices([34, 35, 36, 99999]);

        // Type 34: total=10000, 5%=500, all from first order (1000 vol) @ 5.0
        $this->assertSame(5.0, $result[34]);

        // Type 35: total=10000, 5%=500, all from first order (2000 vol) @ 100.0
        $this->assertSame(100.0, $result[35]);

        // Type 36: no orders
        $this->assertNull($result[36]);

        // Type 99999: not in cache
        $this->assertNull($result[99999]);
    }

    // ===========================================
    // Percentile = 1.0 (all orders)
    // ===========================================

    public function testPercentileOneReturnsWeightedAverageOfAllOrders(): void
    {
        // 3 orders: total volume = 100 + 200 + 300 = 600
        // 100% = 600 units
        // Weighted avg = (100*5.0 + 200*10.0 + 300*15.0) / 600
        //              = (500 + 2000 + 4500) / 600 = 7000 / 600 = 11.6666...
        $this->configureCacheWithOrders([
            34 => [
                ['price' => 5.0, 'volume' => 100],
                ['price' => 10.0, 'volume' => 200],
                ['price' => 15.0, 'volume' => 300],
            ],
        ]);

        $result = $this->service->getCheapestPercentilePrices([34], 1.0);

        $this->assertNotNull($result[34]);
        $this->assertEqualsWithDelta(11.6667, $result[34], 0.001);
    }

    // ===========================================
    // Percentile = 0.0
    // ===========================================

    public function testPercentileZeroReturnsNull(): void
    {
        $this->configureCacheWithOrders([
            34 => [
                ['price' => 5.0, 'volume' => 1000],
            ],
        ]);

        $result = $this->service->getCheapestPercentilePrices([34], 0.0);

        $this->assertNull($result[34]);
    }

    // ===========================================
    // Percentile > 1.0 is clamped to 1.0
    // ===========================================

    public function testPercentileAboveOneClampedToOne(): void
    {
        // Same as percentile=1.0: should average ALL orders
        $this->configureCacheWithOrders([
            34 => [
                ['price' => 10.0, 'volume' => 100],
                ['price' => 20.0, 'volume' => 100],
            ],
        ]);

        $resultFull = $this->service->getCheapestPercentilePrices([34], 1.0);
        $resultOver = $this->service->getCheapestPercentilePrices([34], 2.0);

        $this->assertSame($resultFull[34], $resultOver[34]);
    }

    // ===========================================
    // Precise weighted average calculation
    // ===========================================

    public function testPreciseWeightedAverageWithPartialLastOrder(): void
    {
        // 4 orders: total volume = 50 + 150 + 300 + 500 = 1000
        // 10% of 1000 = 100 units
        // Take: 50 @ 2.0 + 50 @ 3.0 (partial second order)
        // Weighted avg = (50*2.0 + 50*3.0) / 100 = (100 + 150) / 100 = 2.5
        $this->configureCacheWithOrders([
            34 => [
                ['price' => 2.0, 'volume' => 50],
                ['price' => 3.0, 'volume' => 150],
                ['price' => 5.0, 'volume' => 300],
                ['price' => 8.0, 'volume' => 500],
            ],
        ]);

        $result = $this->service->getCheapestPercentilePrices([34], 0.10);

        $this->assertSame(2.5, $result[34]);
    }

    public function testLargePercentileSpansAllOrders(): void
    {
        // 2 orders: total volume = 300 + 700 = 1000
        // 50% of 1000 = 500 units
        // Take: 300 @ 10.0 (full) + 200 @ 20.0 (partial)
        // Weighted avg = (300*10 + 200*20) / 500 = (3000 + 4000) / 500 = 14.0
        $this->configureCacheWithOrders([
            34 => [
                ['price' => 10.0, 'volume' => 300],
                ['price' => 20.0, 'volume' => 700],
            ],
        ]);

        $result = $this->service->getCheapestPercentilePrices([34], 0.50);

        $this->assertSame(14.0, $result[34]);
    }

    // ===========================================
    // Orders with zero volume
    // ===========================================

    public function testOrdersWithAllZeroVolumeReturnsNull(): void
    {
        $this->configureCacheWithOrders([
            34 => [
                ['price' => 5.0, 'volume' => 0],
                ['price' => 10.0, 'volume' => 0],
            ],
        ]);

        $result = $this->service->getCheapestPercentilePrices([34]);

        $this->assertNull($result[34]);
    }

    // ===========================================
    // Empty typeIds array
    // ===========================================

    public function testEmptyTypeIdsReturnsEmptyArray(): void
    {
        $this->configureCacheWithOrders([]);

        $result = $this->service->getCheapestPercentilePrices([]);

        $this->assertSame([], $result);
    }

    // ===========================================
    // Helper
    // ===========================================

    /**
     * @param array<int, list<array{price: float, volume: int}>> $orderBook
     */
    private function configureCacheWithOrders(array $orderBook): void
    {
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn($orderBook);

        $this->cache->method('getItem')->willReturn($cacheItem);
    }
}
