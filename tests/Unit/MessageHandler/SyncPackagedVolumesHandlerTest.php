<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\SyncPackagedVolumes;
use App\MessageHandler\SyncPackagedVolumesHandler;
use App\Service\Admin\SyncTracker;
use App\Service\Assets\PackagedVolumeSyncResult;
use App\Service\Assets\PackagedVolumeSyncService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyncPackagedVolumesHandler::class)]
#[Group('issue-890')]
final class SyncPackagedVolumesHandlerTest extends TestCase
{
    private const string SYNC_TYPE = 'packaged-volumes';

    public function testCompletesTheTrackedRunWithTheCounts(): void
    {
        $calls = [];

        $this->handlerAnswering(new PackagedVolumeSyncResult(synced: 120, failed: 0, aborted: false), $calls)(new SyncPackagedVolumes());

        self::assertSame([['start', self::SYNC_TYPE], ['complete', self::SYNC_TYPE, '120 types synced; 0 failed']], $calls);
    }

    public function testFailedTypesMakeTheRunFailWithTheSameSummary(): void
    {
        $calls = [];

        $this->handlerAnswering(new PackagedVolumeSyncResult(synced: 118, failed: 2, aborted: false), $calls)(new SyncPackagedVolumes());

        self::assertSame([['start', self::SYNC_TYPE], ['fail', self::SYNC_TYPE, '118 types synced; 2 failed']], $calls);
    }

    public function testAbortedRunFails(): void
    {
        $calls = [];

        $this->handlerAnswering(new PackagedVolumeSyncResult(synced: 0, failed: 60, aborted: true), $calls)(new SyncPackagedVolumes());

        self::assertSame('fail', $calls[1][0]);
    }

    public function testExceptionIsTrackedThenRethrown(): void
    {
        $service = $this->createStub(PackagedVolumeSyncService::class);
        $service->method('sync')->willThrowException(new \RuntimeException('esi down'));
        $calls = [];

        try {
            (new SyncPackagedVolumesHandler($service, $this->trackerRecordingInto($calls)))(new SyncPackagedVolumes());
            self::fail('The handler must rethrow the failure');
        } catch (\RuntimeException $failure) {
            self::assertSame('esi down', $failure->getMessage());
        }

        self::assertSame([['start', self::SYNC_TYPE], ['fail', self::SYNC_TYPE, 'esi down']], $calls);
    }

    /**
     * @param list<array<int, string>> $calls
     */
    private function handlerAnswering(PackagedVolumeSyncResult $result, array &$calls): SyncPackagedVolumesHandler
    {
        $service = $this->createStub(PackagedVolumeSyncService::class);
        $service->method('sync')->willReturn($result);

        return new SyncPackagedVolumesHandler($service, $this->trackerRecordingInto($calls));
    }

    /**
     * @param list<array<int, string>> $calls
     */
    private function trackerRecordingInto(array &$calls): SyncTracker
    {
        $tracker = $this->createStub(SyncTracker::class);
        $tracker->method('start')->willReturnCallback(static function (string $type) use (&$calls): void {
            $calls[] = ['start', $type];
        });
        $tracker->method('complete')->willReturnCallback(static function (string $type, ?string $message = null) use (&$calls): void {
            $calls[] = ['complete', $type, $message];
        });
        $tracker->method('fail')->willReturnCallback(static function (string $type, ?string $message = null) use (&$calls): void {
            $calls[] = ['fail', $type, $message];
        });

        return $tracker;
    }
}
