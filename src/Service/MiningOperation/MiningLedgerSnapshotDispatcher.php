<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningLedgerSnapshot;
use App\Message\TakeMiningLedgerSnapshot;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final readonly class MiningLedgerSnapshotDispatcher
{
    private const int MILLISECONDS_PER_SECOND = 1000;
    private const int MICROSECONDS_PER_MILLISECOND = 1000;

    public function __construct(
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    public function dispatch(MiningLedgerSnapshot ...$snapshots): void
    {
        foreach ($snapshots as $snapshot) {
            $delayInMilliseconds = $this->delayBeforeReadingInMilliseconds($snapshot);

            $this->bus->dispatch(
                new TakeMiningLedgerSnapshot(PersistedUuid::of($snapshot->getId())),
                $delayInMilliseconds > 0 ? [new DelayStamp($delayInMilliseconds)] : [],
            );
        }
    }

    private function delayBeforeReadingInMilliseconds(MiningLedgerSnapshot $snapshot): int
    {
        $readyAt = $snapshot->getReadyAt();
        if ($readyAt === null) {
            return 0;
        }

        $now = $this->clock->now();

        return ($readyAt->getTimestamp() - $now->getTimestamp()) * self::MILLISECONDS_PER_SECOND
            + intdiv((int) $readyAt->format('u') - (int) $now->format('u'), self::MICROSECONDS_PER_MILLISECOND);
    }
}
