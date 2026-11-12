<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ESI\EsiClient;
use App\Service\Mercure\MercurePublisherService;
use App\Service\StructureMarketService;
use App\Service\StructureMarketSnapshotService;
use App\Service\StructureTradedVolumeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(StructureMarketService::class)]
#[Group('issue-762')]
final class StructureMarketServiceMemoizationTest extends TestCase
{
    private const STRUCTURE_ID = 1_035_466_617_946;
    private const OTHER_STRUCTURE_ID = 1_022_734_985_679;
    private const PRICES_KEY = 'structure_market_1035466617946';
    private const ORDER_BOOKS_KEY = 'structure_market_1035466617946_orderbooks';
    private const TRITANIUM = 34;
    private const PYERITE = 35;
    private const MEXALLON = 36;

    private ReadCountingCachePool $cache;
    private StructureMarketService $service;

    protected function setUp(): void
    {
        $this->cache = new ReadCountingCachePool();
        $this->seed(self::PRICES_KEY, [
            self::TRITANIUM => ['sell' => 4.5, 'buy' => 4.0],
            self::PYERITE => ['sell' => 10.25, 'buy' => null],
        ]);
        $this->seed(self::ORDER_BOOKS_KEY, [
            self::TRITANIUM => ['sell' => [['price' => 4.5, 'volume' => 1000]], 'buy' => [['price' => 4.0, 'volume' => 800]]],
        ]);
        $this->service = new StructureMarketService(
            $this->createStub(EsiClient::class),
            $this->cache,
            new NullLogger(),
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
            $this->createStub(StructureMarketSnapshotService::class),
            $this->createStub(StructureTradedVolumeService::class),
        );
    }

    public function testReadsThePriceTableOnceWhateverTheNumberOfSingleTypeReads(): void
    {
        $this->service->getLowestSellPrice(self::STRUCTURE_ID, self::TRITANIUM);
        $this->service->getLowestSellPrice(self::STRUCTURE_ID, self::PYERITE);
        $this->service->getHighestBuyPrice(self::STRUCTURE_ID, self::TRITANIUM);
        $this->service->getLowestSellPrices(self::STRUCTURE_ID, [self::TRITANIUM, self::MEXALLON]);
        $this->service->getHighestBuyPrices(self::STRUCTURE_ID, [self::TRITANIUM]);

        $this->assertSame(1, $this->cache->readsOf(self::PRICES_KEY));
    }

    public function testReadsTheOrderBooksOnceWhateverTheNumberOfSingleTypeReads(): void
    {
        $this->service->getSellOrders(self::STRUCTURE_ID, self::TRITANIUM);
        $this->service->getSellOrders(self::STRUCTURE_ID, self::PYERITE);
        $this->service->getBuyOrders(self::STRUCTURE_ID, self::TRITANIUM);

        $this->assertSame(1, $this->cache->readsOf(self::ORDER_BOOKS_KEY));
    }

    public function testResetForcesTheNextReadToLoadTheStructureAgain(): void
    {
        $this->service->getLowestSellPrice(self::STRUCTURE_ID, self::TRITANIUM);
        $this->service->getSellOrders(self::STRUCTURE_ID, self::TRITANIUM);
        $this->service->reset();
        $this->service->getLowestSellPrice(self::STRUCTURE_ID, self::PYERITE);
        $this->service->getSellOrders(self::STRUCTURE_ID, self::PYERITE);

        $this->assertSame(2, $this->cache->readsOf(self::PRICES_KEY));
        $this->assertSame(2, $this->cache->readsOf(self::ORDER_BOOKS_KEY));
    }

    public function testReturnsTheSamePricesAndOrdersAsTheCachedStructureMarket(): void
    {
        $this->assertSame(4.5, $this->service->getLowestSellPrice(self::STRUCTURE_ID, self::TRITANIUM));
        $this->assertSame(10.25, $this->service->getLowestSellPrice(self::STRUCTURE_ID, self::PYERITE));
        $this->assertNull($this->service->getLowestSellPrice(self::STRUCTURE_ID, self::MEXALLON));
        $this->assertSame(4.0, $this->service->getHighestBuyPrice(self::STRUCTURE_ID, self::TRITANIUM));
        $this->assertNull($this->service->getHighestBuyPrice(self::STRUCTURE_ID, self::PYERITE));
        $this->assertSame([['price' => 4.5, 'volume' => 1000]], $this->service->getSellOrders(self::STRUCTURE_ID, self::TRITANIUM));
        $this->assertSame([['price' => 4.0, 'volume' => 800]], $this->service->getBuyOrders(self::STRUCTURE_ID, self::TRITANIUM));
        $this->assertSame([], $this->service->getBuyOrders(self::STRUCTURE_ID, self::PYERITE));
    }

    public function testKeepsTheStructuresApart(): void
    {
        $this->assertSame(4.5, $this->service->getLowestSellPrice(self::STRUCTURE_ID, self::TRITANIUM));

        $this->assertNull($this->service->getLowestSellPrice(self::OTHER_STRUCTURE_ID, self::TRITANIUM));
    }

    public function testStopsServingAStructureOnceItsCacheIsCleared(): void
    {
        $this->assertSame(4.5, $this->service->getLowestSellPrice(self::STRUCTURE_ID, self::TRITANIUM));
        $this->assertCount(1, $this->service->getSellOrders(self::STRUCTURE_ID, self::TRITANIUM));

        $this->service->clearCache(self::STRUCTURE_ID);

        $this->assertNull($this->service->getLowestSellPrice(self::STRUCTURE_ID, self::TRITANIUM));
        $this->assertSame([], $this->service->getSellOrders(self::STRUCTURE_ID, self::TRITANIUM));
    }

    /**
     * @param array<int, mixed> $payload
     */
    private function seed(string $key, array $payload): void
    {
        $item = $this->cache->getItem($key);
        $item->set($payload);
        $this->cache->save($item);
        $this->cache->resetReadCounts();
    }
}
