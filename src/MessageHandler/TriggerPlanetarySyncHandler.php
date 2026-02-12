<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncPlanetaryColonies;
use App\Message\TriggerPlanetarySync;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class TriggerPlanetarySyncHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(TriggerPlanetarySync $message): void
    {
        $this->logger->info('Triggering scheduled planetary sync');

        $this->messageBus->dispatch(new SyncPlanetaryColonies());
    }
}
