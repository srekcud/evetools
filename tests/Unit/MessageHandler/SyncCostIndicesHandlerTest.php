<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Exception\EsiApiException;
use App\Message\SyncCostIndices;
use App\MessageHandler\SyncCostIndicesHandler;
use App\Service\Admin\SyncTracker;
use App\Service\Industry\EsiCostIndexService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(SyncCostIndicesHandler::class)]
final class SyncCostIndicesHandlerTest extends TestCase
{
    public function testFailedCostIndicesSyncIsMarkedFailedLoggedAndRethrownForRetry(): void
    {
        $esiFailure = new EsiApiException('ESI cost indices unavailable', 503, '/industry/systems/');
        $costIndexService = $this->createStub(EsiCostIndexService::class);
        $costIndexService->method('syncCostIndices')->willThrowException($esiFailure);

        $syncTracker = $this->createMock(SyncTracker::class);
        $syncTracker->expects($this->once())->method('fail')->with('cost-indices', 'ESI cost indices unavailable');
        $syncTracker->expects($this->never())->method('complete');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $handler = new SyncCostIndicesHandler($costIndexService, $logger, $syncTracker);

        $this->expectExceptionObject($esiFailure);

        $handler(new SyncCostIndices());
    }

    public function testSuccessfulCostIndicesSyncIsCompletedWithSystemCount(): void
    {
        $costIndexService = $this->createStub(EsiCostIndexService::class);
        $costIndexService->method('syncCostIndices')->willReturn(1234);

        $syncTracker = $this->createMock(SyncTracker::class);
        $syncTracker->expects($this->once())->method('complete')->with('cost-indices', '1234 systems');
        $syncTracker->expects($this->never())->method('fail');

        $handler = new SyncCostIndicesHandler($costIndexService, $this->createStub(LoggerInterface::class), $syncTracker);

        $handler(new SyncCostIndices());
    }
}
