<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Scheduler\SyncScheduler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;

#[CoversClass(SyncScheduler::class)]
class SyncSchedulerRedispatchTest extends TestCase
{
    private const ASYNC_TRANSPORT = 'async';

    public function testEveryScheduledMessageIsRedispatchedToTheAsyncTransport(): void
    {
        $schedule = (new SyncScheduler(new ArrayAdapter(), new LockFactory(new InMemoryStore())))->getSchedule();
        $referenceRun = new \DateTimeImmutable('2026-11-12 12:00:00');
        $transportsByScheduledMessage = [];

        foreach ($schedule->getRecurringMessages() as $recurringMessage) {
            $context = new MessageContext('default', $recurringMessage->getId(), $recurringMessage->getTrigger(), $referenceRun);

            foreach ($recurringMessage->getMessages($context) as $message) {
                $this->assertInstanceOf(RedispatchMessage::class, $message);
                $transportsByScheduledMessage[$message->envelope::class] = $message->transportNames;
            }
        }

        $this->assertCount(\count($schedule->getRecurringMessages()), $transportsByScheduledMessage);
        $this->assertSame(
            array_fill_keys(array_keys($transportsByScheduledMessage), self::ASYNC_TRANSPORT),
            $transportsByScheduledMessage,
        );
    }
}
