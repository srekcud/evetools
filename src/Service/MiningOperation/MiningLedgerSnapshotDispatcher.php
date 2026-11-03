<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningLedgerSnapshot;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MiningLedgerSnapshotDispatcher
{
    public function __construct(
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    public function dispatch(MiningLedgerSnapshot ...$snapshots): void
    {
    }
}
