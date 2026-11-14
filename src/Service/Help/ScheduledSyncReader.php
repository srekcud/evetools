<?php

declare(strict_types=1);

namespace App\Service\Help;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

final readonly class ScheduledSyncReader
{
    private const string REFERENCE_RUN = '2026-01-01 00:00:00';

    /**
     * @param iterable<ScheduleProviderInterface> $scheduleProviders
     */
    public function __construct(
        #[AutowireIterator('scheduler.schedule_provider')]
        private iterable $scheduleProviders,
    ) {
    }

    /**
     * @return list<ScheduledSync>
     */
    public function read(): array
    {
        $referenceRun = new \DateTimeImmutable(self::REFERENCE_RUN);
        $scheduledSyncs = [];

        foreach ($this->recurringMessages() as $recurringMessage) {
            $trigger = $recurringMessage->getTrigger();
            $nextRun = $trigger->getNextRunDate($referenceRun);
            if (null === $nextRun) {
                throw new \LogicException(\sprintf('Scheduled message %s has no next run', $recurringMessage->getId()));
            }
            $context = new MessageContext('default', $recurringMessage->getId(), $trigger, $referenceRun);

            foreach ($recurringMessage->getMessages($context) as $redispatchMessage) {
                if (!$redispatchMessage instanceof RedispatchMessage) {
                    throw new \LogicException(\sprintf('Scheduled message %s is not redispatched', $recurringMessage->getId()));
                }
                $scheduledSyncs[] = new ScheduledSync(
                    $redispatchMessage->envelope::class,
                    $nextRun->getTimestamp() - $referenceRun->getTimestamp(),
                );
            }
        }

        return $scheduledSyncs;
    }

    /**
     * @return iterable<RecurringMessage>
     */
    private function recurringMessages(): iterable
    {
        foreach ($this->scheduleProviders as $scheduleProvider) {
            yield from $scheduleProvider->getSchedule()->getRecurringMessages();
        }
    }
}
