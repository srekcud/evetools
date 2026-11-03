<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\TakeMiningLedgerSnapshot;
use App\Repository\MiningLedgerSnapshotRepository;
use App\Service\MiningOperation\MiningLedgerSnapshotTaker;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class TakeMiningLedgerSnapshotHandler
{
    public function __construct(
        private MiningLedgerSnapshotRepository $snapshotRepository,
        private MiningLedgerSnapshotTaker $taker,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(TakeMiningLedgerSnapshot $message): void
    {
    }
}
