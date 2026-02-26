<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Service\Industry\EsiCostIndexService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(EsiCostIndexService::class)]
class EsiCostIndexServiceTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private CacheItemPoolInterface&MockObject $cache;
    private EsiCostIndexService $service;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->cache = $this->createMock(CacheItemPoolInterface::class);

        $this->service = new EsiCostIndexService(
            $this->httpClient,
            $this->cache,
            new NullLogger(),
        );
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
}
