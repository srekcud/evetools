<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Message\CheckAlertPrices;
use App\Message\SyncIndustryJobs;
use App\Message\SyncPublicContracts;
use App\Message\TriggerPlanetarySync;
use App\Scheduler\SyncScheduler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Scheduler\Generator\MessageGenerator;

/**
 * Issue #40 : plusieurs consommateurs de `scheduler_default` (worker + `make scheduler`,
 * ou plusieurs replicas) ne doivent pas émettre deux fois le même message récurrent.
 *
 * Le cache partagé (`stateful`) ne suffit pas : deux consommateurs qui lisent le
 * checkpoint avant que l'autre l'ait sauvegardé émettent tous les deux les messages dus.
 */
#[CoversClass(SyncScheduler::class)]
class SyncSchedulerLockTest extends TestCase
{
    private const SCHEDULE_NAME = 'default';

    public function testScheduleIsLockedSoASecondConsumerCannotAcquireIt(): void
    {
        $sharedLockFactory = new LockFactory(new InMemoryStore());

        $firstConsumerLock = (new SyncScheduler(new ArrayAdapter(), $sharedLockFactory))->getSchedule()->getLock();
        $secondConsumerLock = (new SyncScheduler(new ArrayAdapter(), $sharedLockFactory))->getSchedule()->getLock();

        $this->assertInstanceOf(LockInterface::class, $firstConsumerLock);
        $this->assertInstanceOf(LockInterface::class, $secondConsumerLock);
        $this->assertTrue($firstConsumerLock->acquire());
        $this->assertFalse($secondConsumerLock->acquire());
    }

    public function testTwoConsumersSharingCacheAndLockEmitEachDueMessageOnlyOnce(): void
    {
        $sharedCache = new ArrayAdapter();
        $sharedLockFactory = new LockFactory(new InMemoryStore());
        $clock = new MockClock('2026-10-07 12:00:00');

        $firstConsumer = new MessageGenerator(new SyncScheduler($sharedCache, $sharedLockFactory), self::SCHEDULE_NAME, $clock);
        $secondConsumer = new MessageGenerator(new SyncScheduler($sharedCache, $sharedLockFactory), self::SCHEDULE_NAME, $clock);

        // Premier passage : initialise le checkpoint, rien n'est encore dû.
        $this->assertSame([], $this->drainMessageClasses($firstConsumer->getMessages()));
        $this->assertSame([], $this->drainMessageClasses($secondConsumer->getMessages()));

        // Les 4 messages à 30 minutes deviennent dus.
        $clock->modify('+30 minutes +1 second');

        // Le premier consommateur a commencé à émettre (checkpoint pas encore sauvegardé)
        // quand le second interroge le schedule.
        $firstConsumerMessages = $firstConsumer->getMessages();
        $firstConsumerFirstMessage = $firstConsumerMessages->current();

        $secondConsumerMessageClasses = $this->drainMessageClasses($secondConsumer->getMessages());

        $firstConsumerMessages->next();
        $firstConsumerMessageClasses = [
            $firstConsumerFirstMessage::class,
            ...$this->drainMessageClasses($firstConsumerMessages),
        ];

        $this->assertSame(
            [SyncIndustryJobs::class, TriggerPlanetarySync::class, CheckAlertPrices::class, SyncPublicContracts::class],
            $firstConsumerMessageClasses,
        );
        $this->assertSame([], $secondConsumerMessageClasses);
    }

    /**
     * @param \Generator<mixed, object> $messages
     *
     * @return list<class-string>
     */
    private function drainMessageClasses(\Generator $messages): array
    {
        $classes = [];
        while ($messages->valid()) {
            $classes[] = $messages->current()::class;
            $messages->next();
        }

        return $classes;
    }
}
