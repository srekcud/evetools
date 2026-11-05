<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\EveToken;
use App\Service\ESI\EsiClient;
use App\Service\Mercure\MercurePublisherService;
use App\Service\StructureMarketService;
use App\Service\StructureMarketSnapshotService;
use App\Service\StructureTradedVolumeService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(StructureMarketService::class)]
#[AllowMockObjectsWithoutExpectations]
final class StructureMarketServiceTradedVolumeTest extends TestCase
{
    private const int STRUCTURE_ID = 1_035_466_617_946;
    private const string STRUCTURE_NAME = '4-HWWF - WinterCo. Central Station';
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;

    private EsiClient&Stub $esiClient;
    private StructureMarketSnapshotService&MockObject $snapshotService;
    private StructureTradedVolumeService&MockObject $tradedVolumeService;
    private StructureMarketService $service;

    /** @var list<string> */
    private array $recordingOrder = [];

    protected function setUp(): void
    {
        $this->esiClient = $this->createStub(EsiClient::class);
        $this->snapshotService = $this->createMock(StructureMarketSnapshotService::class);
        $this->tradedVolumeService = $this->createMock(StructureTradedVolumeService::class);

        $this->service = new StructureMarketService(
            $this->esiClient,
            new ArrayAdapter(),
            new NullLogger(),
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
            $this->snapshotService,
            $this->tradedVolumeService,
        );
    }

    public function testRecordsTheTradedVolumeOfTheStructureFromTheOrdersReadAtTheSyncTime(): void
    {
        $orders = [
            ['order_id' => 6_100_001, 'type_id' => self::TRITANIUM, 'price' => 4.5, 'volume_remain' => 1300, 'is_buy_order' => false],
            ['order_id' => 6_100_002, 'type_id' => self::PYERITE, 'price' => 10.75, 'volume_remain' => 415, 'is_buy_order' => true],
        ];
        $this->esiClient->method('getPaginated')->willReturn($orders);
        $calls = [];
        $this->tradedVolumeService->method('recordFromSync')
            ->willReturnCallback(function (int $structureId, iterable $ordersRead, \DateTimeImmutable $syncedAt) use (&$calls): void {
                $calls[] = [$structureId, $ordersRead, $syncedAt];
            });

        $before = new \DateTimeImmutable();
        $result = $this->service->syncStructureMarket(self::STRUCTURE_ID, self::STRUCTURE_NAME, new EveToken());
        $after = new \DateTimeImmutable();

        self::assertTrue($result['success']);
        self::assertCount(1, $calls);
        [$structureId, $ordersRead, $syncedAt] = $calls[0];
        self::assertSame(self::STRUCTURE_ID, $structureId);
        self::assertSame($orders, $ordersRead);
        self::assertGreaterThanOrEqual($before, $syncedAt);
        self::assertLessThanOrEqual($after, $syncedAt);
    }

    public function testRecordsTheTradedVolumeAfterTheDailySnapshots(): void
    {
        $this->esiClient->method('getPaginated')->willReturn([
            ['order_id' => 6_100_003, 'type_id' => self::TRITANIUM, 'price' => 4.5, 'volume_remain' => 1300, 'is_buy_order' => false],
        ]);
        $this->snapshotService->method('recordSnapshots')->willReturnCallback(function (): void {
            $this->recordingOrder[] = 'snapshots';
        });
        $this->tradedVolumeService->method('recordFromSync')->willReturnCallback(function (): void {
            $this->recordingOrder[] = 'tradedVolume';
        });

        $this->service->syncStructureMarket(self::STRUCTURE_ID, self::STRUCTURE_NAME, new EveToken());

        self::assertSame(['snapshots', 'tradedVolume'], $this->recordingOrder);
    }

    public function testSyncFailsWithTheErrorMessageWhenTheTradedVolumeCannotBeRecorded(): void
    {
        $this->esiClient->method('getPaginated')->willReturn([
            ['order_id' => 6_100_004, 'type_id' => self::TRITANIUM, 'price' => 4.5, 'volume_remain' => 1300, 'is_buy_order' => false],
        ]);
        $this->tradedVolumeService->method('recordFromSync')
            ->willThrowException(new \InvalidArgumentException('ESI order without volume_remain'));

        $result = $this->service->syncStructureMarket(self::STRUCTURE_ID, self::STRUCTURE_NAME, new EveToken());

        self::assertSame(['success' => false, 'error' => 'ESI order without volume_remain'], $result);
    }

    public function testDoesNotRecordTheTradedVolumeWhenEsiFails(): void
    {
        $this->esiClient->method('getPaginated')->willThrowException(new \RuntimeException('ESI unavailable'));
        $this->tradedVolumeService->expects($this->never())->method('recordFromSync');

        $result = $this->service->syncStructureMarket(self::STRUCTURE_ID, self::STRUCTURE_NAME, new EveToken());

        self::assertFalse($result['success']);
    }
}
