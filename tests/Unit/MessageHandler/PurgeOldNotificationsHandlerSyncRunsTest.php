<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\PurgeOldNotifications;
use App\MessageHandler\PurgeOldNotificationsHandler;
use App\Repository\NotificationRepository;
use App\Service\Admin\SyncRunLog;
use App\Service\Admin\SyncTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(PurgeOldNotificationsHandler::class)]
#[Group('issue-646')]
final class PurgeOldNotificationsHandlerSyncRunsTest extends TestCase
{
    private const int RETENTION_DAYS = 30;
    private const int TOLERANCE_SECONDS = 5;

    public function testSyncRunsStartedMoreThanThirtyDaysAgoArePurgedWithTheNotifications(): void
    {
        $notifications = $this->createStub(NotificationRepository::class);
        $notifications->method('deleteOlderThan')->willReturn(4);
        $runLog = $this->createMock(SyncRunLog::class);
        $runLog->expects($this->once())->method('deleteStartedBefore')->with($this->callback(
            static fn (\DateTimeImmutable $limit): bool => abs($limit->getTimestamp() - (new \DateTimeImmutable('-' . self::RETENTION_DAYS . ' days'))->getTimestamp()) <= self::TOLERANCE_SECONDS,
        ))->willReturn(17);
        $syncTracker = $this->createMock(SyncTracker::class);
        $syncTracker->expects($this->once())->method('complete')->with('notifications-purge', '4 notification(s) deleted, 17 sync run(s) deleted');

        (new PurgeOldNotificationsHandler($notifications, new NullLogger(), $syncTracker, $runLog))(new PurgeOldNotifications());
    }

    public function testAFailedSyncRunPurgeFailsTheTrackedRun(): void
    {
        $notifications = $this->createStub(NotificationRepository::class);
        $runLog = $this->createStub(SyncRunLog::class);
        $runLog->method('deleteStartedBefore')->willThrowException(new \RuntimeException('runs delete failed'));
        $syncTracker = $this->createMock(SyncTracker::class);
        $syncTracker->expects($this->never())->method('complete');
        $syncTracker->expects($this->once())->method('fail')->with('notifications-purge', 'runs delete failed');

        (new PurgeOldNotificationsHandler($notifications, new NullLogger(), $syncTracker, $runLog))(new PurgeOldNotifications());
    }
}
