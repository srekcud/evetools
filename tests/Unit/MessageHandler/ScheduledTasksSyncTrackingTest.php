<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\CheckMiningSiteDepletionAlerts;
use App\Message\CheckMoonChunkAlerts;
use App\Message\PurgeExpiredSharedLists;
use App\Message\PurgeOldMarketHistory;
use App\Message\PurgeOldNotifications;
use App\Message\TriggerMiningOperationRecurrences;
use App\MessageHandler\CheckMiningSiteDepletionAlertsHandler;
use App\MessageHandler\CheckMoonChunkAlertsHandler;
use App\MessageHandler\PurgeExpiredSharedListsHandler;
use App\MessageHandler\PurgeOldMarketHistoryHandler;
use App\MessageHandler\PurgeOldNotificationsHandler;
use App\MessageHandler\TriggerMiningOperationRecurrencesHandler;
use App\Repository\CorporationMiningExtractionRepository;
use App\Repository\MarketPriceHistoryRepository;
use App\Repository\MiningOperationRecurrenceRepository;
use App\Repository\MiningSiteRepository;
use App\Repository\MiningSiteScanRepository;
use App\Repository\MoonChunkAlertRepository;
use App\Repository\MoonRefineryFollowRepository;
use App\Repository\NotificationRepository;
use App\Repository\SharedShoppingListRepository;
use App\Repository\StructureMarketSnapshotRepository;
use App\Repository\StructureTradedVolumeRepository;
use App\Service\Admin\SyncRunLog;
use App\Service\Admin\SyncTracker;
use App\Service\MiningOperation\MiningOperationNotifier;
use App\Service\MiningSite\EveDowntime;
use App\Service\MiningSite\MiningSiteDepletionAlertSender;
use App\Service\MiningSite\MiningSiteLedgerEtaReader;
use App\Service\MoonChunk\MoonChunkAlertSender;
use App\Service\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(TriggerMiningOperationRecurrencesHandler::class)]
#[CoversClass(CheckMoonChunkAlertsHandler::class)]
#[CoversClass(CheckMiningSiteDepletionAlertsHandler::class)]
#[CoversClass(PurgeOldNotificationsHandler::class)]
#[CoversClass(PurgeOldMarketHistoryHandler::class)]
#[CoversClass(PurgeExpiredSharedListsHandler::class)]
#[Group('issue-638')]
final class ScheduledTasksSyncTrackingTest extends TestCase
{
    private const string RECURRENCES_KEY = 'mining-operation-recurrences';
    private const string MOON_ALERTS_KEY = 'moon-chunk-alerts';
    private const string DEPLETION_ALERTS_KEY = 'mining-site-depletion-alerts';
    private const string NOTIFICATIONS_PURGE_KEY = 'notifications-purge';
    private const string MARKET_HISTORY_PURGE_KEY = 'market-history-purge';
    private const string SHARED_LISTS_PURGE_KEY = 'shared-lists-purge';

    public function testRecurringMiningOperationsAreTrackedThenFailureIsRethrown(): void
    {
        $repository = $this->createStub(MiningOperationRecurrenceRepository::class);
        $repository->method('findActive')->willReturn([]);
        $calls = [];
        (new TriggerMiningOperationRecurrencesHandler($repository, $this->createStub(MessageBusInterface::class), $this->trackerRecordingInto($calls)))(new TriggerMiningOperationRecurrences());
        self::assertSame([['start', self::RECURRENCES_KEY], ['complete', self::RECURRENCES_KEY]], $this->withoutMessages($calls));

        $failingRepository = $this->createStub(MiningOperationRecurrenceRepository::class);
        $failingRepository->method('findActive')->willThrowException(new \RuntimeException('database down'));
        $failedCalls = [];
        $this->expectRethrow(
            function () use (&$failedCalls, $failingRepository): void { (new TriggerMiningOperationRecurrencesHandler($failingRepository, $this->createStub(MessageBusInterface::class), $this->trackerRecordingInto($failedCalls)))(new TriggerMiningOperationRecurrences()); },
            'database down',
        );
        self::assertSame([['start', self::RECURRENCES_KEY], ['fail', self::RECURRENCES_KEY, 'database down']], $failedCalls);
    }

    public function testMoonChunkAlertsAreTrackedThenFailureIsRethrown(): void
    {
        $calls = [];
        (new CheckMoonChunkAlertsHandler($this->moonChunkAlertSenderFollowing([]), $this->trackerRecordingInto($calls)))(new CheckMoonChunkAlerts());
        self::assertSame([['start', self::MOON_ALERTS_KEY], ['complete', self::MOON_ALERTS_KEY]], $this->withoutMessages($calls));

        $failedCalls = [];
        $this->expectRethrow(
            function () use (&$failedCalls): void { (new CheckMoonChunkAlertsHandler($this->moonChunkAlertSenderFailing('follow lookup failed'), $this->trackerRecordingInto($failedCalls)))(new CheckMoonChunkAlerts()); },
            'follow lookup failed',
        );
        self::assertSame([['start', self::MOON_ALERTS_KEY], ['fail', self::MOON_ALERTS_KEY, 'follow lookup failed']], $failedCalls);
    }

    public function testMiningSiteDepletionAlertsAreTrackedThenFailureIsRethrown(): void
    {
        $calls = [];
        (new CheckMiningSiteDepletionAlertsHandler($this->depletionAlertSenderOver(null), $this->trackerRecordingInto($calls)))(new CheckMiningSiteDepletionAlerts());
        self::assertSame([['start', self::DEPLETION_ALERTS_KEY], ['complete', self::DEPLETION_ALERTS_KEY]], $this->withoutMessages($calls));

        $failedCalls = [];
        $this->expectRethrow(
            function () use (&$failedCalls): void { (new CheckMiningSiteDepletionAlertsHandler($this->depletionAlertSenderOver(new \RuntimeException('site lookup failed')), $this->trackerRecordingInto($failedCalls)))(new CheckMiningSiteDepletionAlerts()); },
            'site lookup failed',
        );
        self::assertSame([['start', self::DEPLETION_ALERTS_KEY], ['fail', self::DEPLETION_ALERTS_KEY, 'site lookup failed']], $failedCalls);
    }

    public function testNotificationsPurgeIsTrackedAndAFailureIsReportedWithoutBeingRethrown(): void
    {
        $repository = $this->createStub(NotificationRepository::class);
        $repository->method('deleteOlderThan')->willReturn(4);
        $calls = [];
        (new PurgeOldNotificationsHandler($repository, new NullLogger(), $this->trackerRecordingInto($calls), $this->createStub(SyncRunLog::class)))(new PurgeOldNotifications());
        self::assertSame([['start', self::NOTIFICATIONS_PURGE_KEY], ['complete', self::NOTIFICATIONS_PURGE_KEY]], $this->withoutMessages($calls));

        $failingRepository = $this->createStub(NotificationRepository::class);
        $failingRepository->method('deleteOlderThan')->willThrowException(new \RuntimeException('delete failed'));
        $failedCalls = [];
        (new PurgeOldNotificationsHandler($failingRepository, new NullLogger(), $this->trackerRecordingInto($failedCalls), $this->createStub(SyncRunLog::class)))(new PurgeOldNotifications());
        self::assertSame([['start', self::NOTIFICATIONS_PURGE_KEY], ['fail', self::NOTIFICATIONS_PURGE_KEY, 'delete failed']], $failedCalls);
    }

    public function testMarketHistoryPurgeIsTrackedAndEveryFailureIsReportedWhileTheOtherPurgesStillRun(): void
    {
        $calls = [];
        $this->marketHistoryHandler(null, null, $calls)(new PurgeOldMarketHistory());
        self::assertSame([['start', self::MARKET_HISTORY_PURGE_KEY], ['complete', self::MARKET_HISTORY_PURGE_KEY]], $this->withoutMessages($calls));

        $failedCalls = [];
        $this->marketHistoryHandler(new \RuntimeException('history failed'), new \RuntimeException('snapshots failed'), $failedCalls)(new PurgeOldMarketHistory());
        self::assertSame(
            [['start', self::MARKET_HISTORY_PURGE_KEY], ['fail', self::MARKET_HISTORY_PURGE_KEY, 'history failed; snapshots failed']],
            $failedCalls,
        );
    }

    public function testSharedListsPurgeIsTrackedThenFailureIsRethrown(): void
    {
        $repository = $this->createStub(SharedShoppingListRepository::class);
        $repository->method('deleteExpired')->willReturn(2);
        $calls = [];
        (new PurgeExpiredSharedListsHandler($repository, new NullLogger(), $this->trackerRecordingInto($calls)))(new PurgeExpiredSharedLists());
        self::assertSame([['start', self::SHARED_LISTS_PURGE_KEY], ['complete', self::SHARED_LISTS_PURGE_KEY]], $this->withoutMessages($calls));

        $failingRepository = $this->createStub(SharedShoppingListRepository::class);
        $failingRepository->method('deleteExpired')->willThrowException(new \RuntimeException('delete failed'));
        $failedCalls = [];
        $this->expectRethrow(
            function () use (&$failedCalls, $failingRepository): void { (new PurgeExpiredSharedListsHandler($failingRepository, new NullLogger(), $this->trackerRecordingInto($failedCalls)))(new PurgeExpiredSharedLists()); },
            'delete failed',
        );
        self::assertSame([['start', self::SHARED_LISTS_PURGE_KEY], ['fail', self::SHARED_LISTS_PURGE_KEY, 'delete failed']], $failedCalls);
    }

    /** @param list<array<int, string|null>> $calls */
    private function marketHistoryHandler(?\Throwable $historyFailure, ?\Throwable $snapshotFailure, array &$calls): PurgeOldMarketHistoryHandler
    {
        $history = $this->createStub(MarketPriceHistoryRepository::class);
        $snapshots = $this->createStub(StructureMarketSnapshotRepository::class);
        $tradedVolumes = $this->createStub(StructureTradedVolumeRepository::class);
        if ($historyFailure !== null) {
            $history->method('purgeOlderThan')->willThrowException($historyFailure);
        } else {
            $history->method('purgeOlderThan')->willReturn(1);
        }
        if ($snapshotFailure !== null) {
            $snapshots->method('purgeOlderThan')->willThrowException($snapshotFailure);
        } else {
            $snapshots->method('purgeOlderThan')->willReturn(1);
        }
        $tradedVolumes->method('purgeOlderThan')->willReturn(1);

        return new PurgeOldMarketHistoryHandler($history, $snapshots, $tradedVolumes, new NullLogger(), $this->trackerRecordingInto($calls));
    }

    /** @param list<never> $follows */
    private function moonChunkAlertSenderFollowing(array $follows): MoonChunkAlertSender
    {
        $followRepository = $this->createStub(MoonRefineryFollowRepository::class);
        $followRepository->method('findAll')->willReturn($follows);

        return $this->moonChunkAlertSenderOver($followRepository);
    }

    private function moonChunkAlertSenderFailing(string $message): MoonChunkAlertSender
    {
        $followRepository = $this->createStub(MoonRefineryFollowRepository::class);
        $followRepository->method('findAll')->willThrowException(new \RuntimeException($message));

        return $this->moonChunkAlertSenderOver($followRepository);
    }

    private function moonChunkAlertSenderOver(MoonRefineryFollowRepository $followRepository): MoonChunkAlertSender
    {
        return new MoonChunkAlertSender(
            $followRepository,
            $this->createStub(CorporationMiningExtractionRepository::class),
            $this->createStub(MoonChunkAlertRepository::class),
            $this->createStub(NotificationDispatcher::class),
            $this->createStub(EntityManagerInterface::class),
            new MockClock('2026-11-11 12:00:00', 'UTC'),
        );
    }

    private function depletionAlertSenderOver(?\Throwable $siteLookupFailure): MiningSiteDepletionAlertSender
    {
        $siteRepository = $this->createStub(MiningSiteRepository::class);
        if ($siteLookupFailure !== null) {
            $siteRepository->method('findActiveOfRunningOperations')->willThrowException($siteLookupFailure);
        } else {
            $siteRepository->method('findActiveOfRunningOperations')->willReturn([]);
        }

        return new MiningSiteDepletionAlertSender(
            $siteRepository,
            $this->createStub(MiningSiteScanRepository::class),
            (new \ReflectionClass(MiningSiteLedgerEtaReader::class))->newInstanceWithoutConstructor(),
            new MiningOperationNotifier($this->createStub(NotificationDispatcher::class)),
            $this->createStub(EntityManagerInterface::class),
            new MockClock('2026-11-11 12:00:00', 'UTC'),
            new EveDowntime(),
        );
    }

    private function expectRethrow(callable $invocation, string $expectedMessage): void
    {
        try {
            $invocation();
            self::fail('The handler must rethrow the failure');
        } catch (\RuntimeException $failure) {
            self::assertSame($expectedMessage, $failure->getMessage());
        }
    }

    /**
     * @param list<array<int, string|null>> $calls
     *
     * @return list<array<int, string|null>>
     */
    private function withoutMessages(array $calls): array
    {
        return array_map(static fn (array $call): array => \array_slice($call, 0, 2), $calls);
    }

    /** @param list<array<int, string|null>> $calls */
    private function trackerRecordingInto(array &$calls): SyncTracker
    {
        $tracker = $this->createStub(SyncTracker::class);
        $tracker->method('start')->willReturnCallback(static function (string $type) use (&$calls): void {
            $calls[] = ['start', $type];
        });
        $tracker->method('complete')->willReturnCallback(static function (string $type, ?string $message = null) use (&$calls): void {
            $calls[] = ['complete', $type, $message];
        });
        $tracker->method('fail')->willReturnCallback(static function (string $type, string $message) use (&$calls): void {
            $calls[] = ['fail', $type, $message];
        });

        return $tracker;
    }
}
