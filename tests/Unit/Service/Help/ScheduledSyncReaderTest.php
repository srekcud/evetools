<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Help;

use App\Message\PurgeOldNotifications;
use App\Message\SyncCostIndices;
use App\Message\SyncWalletTransactions;
use App\Message\TriggerAnsiblexSync;
use App\Message\TriggerPveSync;
use App\Scheduler\SyncScheduler;
use App\Service\Help\ScheduledSync;
use App\Service\Help\ScheduledSyncReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(ScheduledSyncReader::class)]
#[CoversClass(ScheduledSync::class)]
final class ScheduledSyncReaderTest extends TestCase
{
    public function testItReadsEveryScheduledMessageWithItsIntervalInSeconds(): void
    {
        $intervals = [];
        foreach ($this->reader()->read() as $sync) {
            $intervals[$sync->messageClass] = $sync->intervalSeconds;
        }

        self::assertSame(300, $intervals[SyncWalletTransactions::class]);
        self::assertSame(3600, $intervals[TriggerPveSync::class]);
        self::assertSame(7200, $intervals[SyncCostIndices::class]);
        self::assertSame(43200, $intervals[TriggerAnsiblexSync::class]);
        self::assertSame(86400, $intervals[PurgeOldNotifications::class]);
    }

    public function testItReadsAsManyEntriesAsTheScheduleHasMessages(): void
    {
        $scheduler = new SyncScheduler(new ArrayAdapter(), new LockFactory(new InMemoryStore()));

        self::assertCount(\count($scheduler->getSchedule()->getRecurringMessages()), $this->reader()->read());
    }

    private function reader(): ScheduledSyncReader
    {
        return new ScheduledSyncReader([new SyncScheduler(new ArrayAdapter(), new LockFactory(new InMemoryStore()))]);
    }
}
