<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Admin;

use App\Service\Admin\DbalSyncRunLog;
use App\Service\Admin\SyncRunLog;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

#[CoversClass(DbalSyncRunLog::class)]
#[Group('issue-646')]
final class DbalSyncRunLogTest extends IntegrationTestCase
{
    private DbalSyncRunLog $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = new DbalSyncRunLog(self::getContainer()->get(Connection::class), new NullLogger());
    }

    public function testABegunRunIsReadBackAsRunningWithoutAnEnd(): void
    {
        $this->log->begin('industry', new \DateTimeImmutable('2026-11-12 08:00:00'));

        $run = $this->log->recentRunsByType(20)['industry'][0];

        self::assertSame(SyncRunLog::STATUS_RUNNING, $run['status']);
        self::assertNull($run['completed_at']);
        self::assertEquals(new \DateTimeImmutable('2026-11-12 08:00:00'), new \DateTimeImmutable($run['started_at']));
    }

    public function testFinishingEndsTheMostRecentRunningRunOfThatTypeOnly(): void
    {
        $this->log->begin('industry', new \DateTimeImmutable('2026-11-12 07:00:00'));
        $this->log->begin('industry', new \DateTimeImmutable('2026-11-12 08:00:00'));
        $this->log->begin('pve', new \DateTimeImmutable('2026-11-12 08:01:00'));

        $this->log->finish('industry', SyncRunLog::STATUS_OK, '3 jobs', new \DateTimeImmutable('2026-11-12 08:05:00'));

        $runs = $this->log->recentRunsByType(20);
        self::assertSame([SyncRunLog::STATUS_OK, SyncRunLog::STATUS_RUNNING], array_column($runs['industry'], 'status'));
        self::assertSame('3 jobs', $runs['industry'][0]['message']);
        self::assertEquals(new \DateTimeImmutable('2026-11-12 08:05:00'), new \DateTimeImmutable((string) $runs['industry'][0]['completed_at']));
        self::assertSame(SyncRunLog::STATUS_RUNNING, $runs['pve'][0]['status']);
    }

    public function testFinishingWithoutARunningRunRecordsAFinishedRunStartingWhenItEnds(): void
    {
        $this->log->finish('industry', SyncRunLog::STATUS_ERROR, 'boom', new \DateTimeImmutable('2026-11-12 08:05:00'));

        $runs = $this->log->recentRunsByType(20)['industry'];

        self::assertCount(1, $runs);
        self::assertSame(SyncRunLog::STATUS_ERROR, $runs[0]['status']);
        self::assertEquals(new \DateTimeImmutable($runs[0]['started_at']), new \DateTimeImmutable((string) $runs[0]['completed_at']));
    }

    public function testAMessageLongerThanFiveHundredCharactersIsTruncated(): void
    {
        $this->log->begin('industry', new \DateTimeImmutable('2026-11-12 08:00:00'));
        $this->log->finish('industry', SyncRunLog::STATUS_ERROR, str_repeat('é', 800), new \DateTimeImmutable('2026-11-12 08:05:00'));

        $message = (string) $this->log->recentRunsByType(20)['industry'][0]['message'];

        self::assertSame(SyncRunLog::MESSAGE_MAX_LENGTH, mb_strlen($message));
    }

    public function testRecentRunsAreLimitedPerTypeAndSortedNewestFirst(): void
    {
        for ($minute = 0; $minute < 5; ++$minute) {
            $this->log->begin('industry', new \DateTimeImmutable("2026-11-12 08:0{$minute}:00"));
            $this->log->finish('industry', SyncRunLog::STATUS_OK, null, new \DateTimeImmutable("2026-11-12 08:0{$minute}:30"));
        }
        $this->log->begin('pve', new \DateTimeImmutable('2026-11-12 07:00:00'));

        $runs = $this->log->recentRunsByType(3);

        self::assertCount(3, $runs['industry']);
        self::assertEquals(new \DateTimeImmutable('2026-11-12 08:04:00'), new \DateTimeImmutable($runs['industry'][0]['started_at']));
        self::assertEquals(new \DateTimeImmutable('2026-11-12 08:02:00'), new \DateTimeImmutable($runs['industry'][2]['started_at']));
        self::assertCount(1, $runs['pve']);
    }

    public function testFinishedRunCountsIgnoreRunningRunsAndRunsStartedBeforeTheWindow(): void
    {
        $this->log->begin('industry', new \DateTimeImmutable('2026-11-10 08:00:00'));
        $this->log->finish('industry', SyncRunLog::STATUS_ERROR, null, new \DateTimeImmutable('2026-11-10 08:00:30'));
        $this->log->begin('industry', new \DateTimeImmutable('2026-11-12 08:00:00'));
        $this->log->finish('industry', SyncRunLog::STATUS_OK, null, new \DateTimeImmutable('2026-11-12 08:00:30'));
        $this->log->begin('industry', new \DateTimeImmutable('2026-11-12 09:00:00'));
        $this->log->begin('pve', new \DateTimeImmutable('2026-11-12 09:00:00'));

        $counts = $this->log->finishedRunCountsSince(new \DateTimeImmutable('2026-11-11 12:00:00'));

        self::assertSame(['industry' => ['ok' => 1, 'error' => 0]], $counts);
    }

    public function testDeletingRunsStartedBeforeALimitReturnsTheirNumberAndKeepsTheOthers(): void
    {
        $this->log->begin('industry', new \DateTimeImmutable('2026-10-01 08:00:00'));
        $this->log->begin('pve', new \DateTimeImmutable('2026-10-02 08:00:00'));
        $this->log->begin('industry', new \DateTimeImmutable('2026-10-13 08:00:00'));

        $deleted = $this->log->deleteStartedBefore(new \DateTimeImmutable('2026-10-13 08:00:00'));

        self::assertSame(2, $deleted);
        self::assertSame(['industry'], array_keys($this->log->recentRunsByType(20)));
    }
}
