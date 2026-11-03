<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningLedgerSnapshot;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class MiningLedgerSnapshotTaker
{
    public function __construct(
        private MiningLedgerReader $reader,
        private EntityManagerInterface $entityManager,
        private MiningLedgerSnapshotDispatcher $dispatcher,
        private MercurePublisherService $mercurePublisher,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    public function take(MiningLedgerSnapshot $snapshot): void
    {
    }
}
