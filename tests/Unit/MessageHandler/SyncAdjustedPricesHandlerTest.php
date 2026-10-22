<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Exception\EsiApiException;
use App\Message\SyncAdjustedPrices;
use App\MessageHandler\SyncAdjustedPricesHandler;
use App\Service\Admin\SyncTracker;
use App\Service\Industry\EsiCostIndexService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(SyncAdjustedPricesHandler::class)]
final class SyncAdjustedPricesHandlerTest extends TestCase
{
    public function testFailedAdjustedPricesSyncIsMarkedFailedLoggedAndRethrownForRetry(): void
    {
        $esiFailure = new EsiApiException('ESI adjusted prices unavailable', 503, '/markets/prices/');
        $costIndexService = $this->createStub(EsiCostIndexService::class);
        $costIndexService->method('syncAdjustedPrices')->willThrowException($esiFailure);

        $syncTracker = $this->createMock(SyncTracker::class);
        $syncTracker->expects($this->once())->method('fail')->with('adjusted-prices', 'ESI adjusted prices unavailable');
        $syncTracker->expects($this->never())->method('complete');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $handler = new SyncAdjustedPricesHandler($costIndexService, $logger, $syncTracker);

        $this->expectExceptionObject($esiFailure);

        $handler(new SyncAdjustedPrices());
    }

    public function testSuccessfulAdjustedPricesSyncIsCompletedWithTypeCount(): void
    {
        $costIndexService = $this->createStub(EsiCostIndexService::class);
        $costIndexService->method('syncAdjustedPrices')->willReturn(14000);

        $syncTracker = $this->createMock(SyncTracker::class);
        $syncTracker->expects($this->once())->method('complete')->with('adjusted-prices', '14000 types');
        $syncTracker->expects($this->never())->method('fail');

        $handler = new SyncAdjustedPricesHandler($costIndexService, $this->createStub(LoggerInterface::class), $syncTracker);

        $handler(new SyncAdjustedPrices());
    }
}
