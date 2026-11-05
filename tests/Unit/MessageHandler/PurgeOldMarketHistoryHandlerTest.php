<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\PurgeOldMarketHistory;
use App\MessageHandler\PurgeOldMarketHistoryHandler;
use App\Repository\MarketPriceHistoryRepository;
use App\Repository\StructureMarketSnapshotRepository;
use App\Repository\StructureTradedVolumeRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(PurgeOldMarketHistoryHandler::class)]
#[AllowMockObjectsWithoutExpectations]
final class PurgeOldMarketHistoryHandlerTest extends TestCase
{
    private MarketPriceHistoryRepository&MockObject $historyRepository;
    private StructureMarketSnapshotRepository&MockObject $snapshotRepository;
    private StructureTradedVolumeRepository&MockObject $tradedVolumeRepository;
    private PurgeOldMarketHistoryHandler $handler;

    protected function setUp(): void
    {
        $this->historyRepository = $this->createMock(MarketPriceHistoryRepository::class);
        $this->snapshotRepository = $this->createMock(StructureMarketSnapshotRepository::class);
        $this->tradedVolumeRepository = $this->createMock(StructureTradedVolumeRepository::class);

        $this->handler = new PurgeOldMarketHistoryHandler(
            historyRepository: $this->historyRepository,
            snapshotRepository: $this->snapshotRepository,
            tradedVolumeRepository: $this->tradedVolumeRepository,
            logger: new NullLogger(),
        );
    }

    public function testPurgesTheEstimatedTradedVolumeOfStructuresOlderThanNinetyDays(): void
    {
        $this->tradedVolumeRepository->expects($this->once())->method('purgeOlderThan')->with(90)->willReturn(12);

        ($this->handler)(new PurgeOldMarketHistory());
    }

    public function testKeepsPurgingMarketPriceHistoryAfter365DaysAndStructureSnapshotsAfter90Days(): void
    {
        $this->historyRepository->expects($this->once())->method('purgeOlderThan')->with(365)->willReturn(3);
        $this->snapshotRepository->expects($this->once())->method('purgeOlderThan')->with(90)->willReturn(7);

        ($this->handler)(new PurgeOldMarketHistory());
    }
}
