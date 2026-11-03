<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CreateRecurringMiningOperations;
use App\Message\TriggerMiningOperationRecurrences;
use App\Repository\MiningOperationRecurrenceRepository;
use App\Service\MiningOperation\PersistedUuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class TriggerMiningOperationRecurrencesHandler
{
    public function __construct(
        private MiningOperationRecurrenceRepository $recurrenceRepository,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(TriggerMiningOperationRecurrences $message): void
    {
        foreach ($this->recurrenceRepository->findActive() as $recurrence) {
            $this->messageBus->dispatch(new CreateRecurringMiningOperations(PersistedUuid::of($recurrence->getId())));
        }
    }
}
