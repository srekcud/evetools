<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\MarketPriceHistory;
use App\Repository\MarketPriceHistoryRepository;
use App\Service\ESI\EsiClient;
use App\Service\MarketHistoryService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Statement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(MarketHistoryService::class)]
class MarketHistoryServiceTest extends TestCase
{
    private EsiClient&Stub $esiClient;
    private MarketPriceHistoryRepository&Stub $historyRepository;
    private MarketHistoryService $service;

    protected function setUp(): void
    {
        $this->esiClient = $this->createStub(EsiClient::class);
        $this->historyRepository = $this->createStub(MarketPriceHistoryRepository::class);

        $this->service = new MarketHistoryService(
            $this->esiClient,
            $this->historyRepository,
            $this->createStub(Connection::class),
            new NullLogger(),
        );
    }

    /**
     * Build a service with a mock Connection for sync-related tests.
     *
     * @return array{MarketHistoryService, Connection&MockObject}
     */
    private function createServiceWithMockConnection(): array
    {
        $connection = $this->createMock(Connection::class);
        $service = new MarketHistoryService(
            $this->esiClient,
            $this->historyRepository,
            $connection,
            new NullLogger(),
        );

        return [$service, $connection];
    }

    // ===========================================
    // get30dPriceChange Tests
    // ===========================================

    public function testGet30dPriceChangePositive(): void
    {
        $this->historyRepository
            ->method('getAveragePriceOnDate')
            ->willReturnCallback(function (int $typeId, \DateTimeInterface $date): float {
                $daysAgo = (int) (new \DateTimeImmutable())->diff($date)->days;
                return $daysAgo < 5 ? 120.0 : 100.0;
            });

        $change = $this->service->get30dPriceChange(34);

        $this->assertNotNull($change);
        $this->assertSame(20.0, $change);
    }

    public function testGet30dPriceChangeNegative(): void
    {
        $this->historyRepository
            ->method('getAveragePriceOnDate')
            ->willReturnCallback(function (int $typeId, \DateTimeInterface $date): float {
                $daysAgo = (int) (new \DateTimeImmutable())->diff($date)->days;
                return $daysAgo < 5 ? 80.0 : 100.0;
            });

        $change = $this->service->get30dPriceChange(34);

        $this->assertNotNull($change);
        $this->assertSame(-20.0, $change);
    }

    public function testGet30dPriceChangeReturnsNullWhenNoData(): void
    {
        $this->historyRepository
            ->method('getAveragePriceOnDate')
            ->willReturn(null);

        $change = $this->service->get30dPriceChange(34);

        $this->assertNull($change);
    }

    public function testGet30dPriceChangeReturnsNullWhenOldPriceIsZero(): void
    {
        $callCount = 0;
        $this->historyRepository
            ->method('getAveragePriceOnDate')
            ->willReturnCallback(function () use (&$callCount): float {
                $callCount++;
                return $callCount === 1 ? 100.0 : 0.0;
            });

        $change = $this->service->get30dPriceChange(34);

        $this->assertNull($change);
    }

    public function testGet30dPriceChangeReturnsNullWhenCurrentPriceIsNull(): void
    {
        $callCount = 0;
        $this->historyRepository
            ->method('getAveragePriceOnDate')
            ->willReturnCallback(function () use (&$callCount): ?float {
                $callCount++;
                return $callCount === 1 ? null : 100.0;
            });

        $change = $this->service->get30dPriceChange(34);

        $this->assertNull($change);
    }

    // ===========================================
    // getHistory Tests
    // ===========================================

    public function testGetHistoryReturnsCachedDataWhenFresh(): void
    {
        /** @var MarketHistoryService $service */
        /** @var Connection&MockObject $connection */
        [$service, $connection] = $this->createServiceWithMockConnection();

        $yesterday = new \DateTime('-12 hours');
        $this->historyRepository
            ->method('getLatestDate')
            ->willReturn($yesterday);

        $history1 = new MarketPriceHistory();
        $history1->setTypeId(34);
        $history1->setDate(new \DateTime('-1 day'));
        $history1->setAverage(5.50);
        $history1->setHighest(6.00);
        $history1->setLowest(5.00);
        $history1->setOrderCount(1000);
        $history1->setVolume(50000);

        $this->historyRepository
            ->method('findHistory')
            ->willReturn([$history1]);

        // ESI should NOT be called since data is fresh
        $connection->expects($this->never())->method('prepare');

        $result = $service->getHistory(34);

        $this->assertCount(1, $result);
        $this->assertSame(34, $result[0]->getTypeId());
    }

    public function testGetHistoryTriggersSyncWhenStale(): void
    {
        /** @var MarketHistoryService $service */
        /** @var Connection&MockObject $connection */
        [$service, $connection] = $this->createServiceWithMockConnection();

        $oldDate = new \DateTime('-3 days');
        $this->historyRepository
            ->method('getLatestDate')
            ->willReturn($oldDate);

        $this->esiClient
            ->method('get')
            ->willReturn([
                ['date' => '2026-02-15', 'order_count' => 100, 'volume' => 5000, 'lowest' => 5.0, 'highest' => 6.0, 'average' => 5.5],
            ]);

        $stmt = $this->createStub(Statement::class);
        $stmt->method('bindValue')->willReturnSelf();
        $stmt->method('executeStatement')->willReturn(1);
        $connection->expects($this->once())->method('prepare')->willReturn($stmt);

        $this->historyRepository
            ->method('findHistory')
            ->willReturn([]);

        $service->getHistory(34);
    }

    public function testGetHistoryTriggersSyncWhenNoData(): void
    {
        /** @var MarketHistoryService $service */
        /** @var Connection&MockObject $connection */
        [$service, $connection] = $this->createServiceWithMockConnection();

        $this->historyRepository
            ->method('getLatestDate')
            ->willReturn(null);

        $this->esiClient
            ->method('get')
            ->willReturn([]);

        $this->historyRepository
            ->method('findHistory')
            ->willReturn([]);

        $connection->expects($this->never())->method('prepare');

        $service->getHistory(34);
    }

    // ===========================================
    // syncHistory Tests
    // ===========================================

    public function testSyncHistorySkipsEntriesOlderThanLatestDate(): void
    {
        /** @var MarketHistoryService $service */
        /** @var Connection&MockObject $connection */
        [$service, $connection] = $this->createServiceWithMockConnection();

        $latestDate = new \DateTime('2026-02-10');
        $this->historyRepository
            ->method('getLatestDate')
            ->willReturn($latestDate);

        $this->esiClient->method('get')->willReturn([
            ['date' => '2026-02-08', 'order_count' => 50, 'volume' => 1000, 'lowest' => 4.0, 'highest' => 5.0, 'average' => 4.5],
            ['date' => '2026-02-09', 'order_count' => 60, 'volume' => 1100, 'lowest' => 4.1, 'highest' => 5.1, 'average' => 4.6],
            ['date' => '2026-02-10', 'order_count' => 70, 'volume' => 1200, 'lowest' => 4.2, 'highest' => 5.2, 'average' => 4.7],
            ['date' => '2026-02-11', 'order_count' => 80, 'volume' => 1300, 'lowest' => 4.3, 'highest' => 5.3, 'average' => 4.8],
            ['date' => '2026-02-12', 'order_count' => 90, 'volume' => 1400, 'lowest' => 4.4, 'highest' => 5.4, 'average' => 4.9],
        ]);

        $stmt = $this->createMock(Statement::class);
        $stmt->method('bindValue')->willReturnSelf();
        $stmt->expects($this->exactly(2))->method('executeStatement')->willReturn(1);
        $connection->expects($this->once())->method('prepare')->willReturn($stmt);

        $service->syncHistory(34);
    }

    public function testSyncHistoryDoesNothingWhenEsiReturnsEmpty(): void
    {
        /** @var MarketHistoryService $service */
        /** @var Connection&MockObject $connection */
        [$service, $connection] = $this->createServiceWithMockConnection();

        $this->esiClient->method('get')->willReturn([]);
        $connection->expects($this->never())->method('prepare');

        $service->syncHistory(34);
    }

    public function testSyncHistoryInsertsAllWhenNoPriorData(): void
    {
        /** @var MarketHistoryService $service */
        /** @var Connection&MockObject $connection */
        [$service, $connection] = $this->createServiceWithMockConnection();

        $this->historyRepository
            ->method('getLatestDate')
            ->willReturn(null);

        $this->esiClient->method('get')->willReturn([
            ['date' => '2026-02-13', 'order_count' => 100, 'volume' => 5000, 'lowest' => 5.0, 'highest' => 6.0, 'average' => 5.5],
            ['date' => '2026-02-14', 'order_count' => 110, 'volume' => 5500, 'lowest' => 5.1, 'highest' => 6.1, 'average' => 5.6],
        ]);

        $stmt = $this->createMock(Statement::class);
        $stmt->method('bindValue')->willReturnSelf();
        $stmt->expects($this->exactly(2))->method('executeStatement')->willReturn(1);
        $connection->expects($this->once())->method('prepare')->willReturn($stmt);

        $service->syncHistory(34);
    }
}
