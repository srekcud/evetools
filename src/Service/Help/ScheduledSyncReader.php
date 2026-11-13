<?php

declare(strict_types=1);

namespace App\Service\Help;

use App\Scheduler\SyncScheduler;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;

final readonly class ScheduledSyncReader
{
    private const string REFERENCE_RUN = '2026-01-01 00:00:00';

    public function __construct(
        private SyncScheduler $scheduler,
    ) {
    }

    /**
     * @return list<ScheduledSync>
     */
    public function read(): array
    {
        $referenceRun = new \DateTimeImmutable(self::REFERENCE_RUN);
        $scheduledSyncs = [];

        foreach ($this->scheduler->getSchedule()->getRecurringMessages() as $recurringMessage) {
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
}
