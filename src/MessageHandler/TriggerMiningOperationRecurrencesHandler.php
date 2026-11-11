<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CreateRecurringMiningOperations;
use App\Message\TriggerMiningOperationRecurrences;
use App\Repository\MiningOperationRecurrenceRepository;
use App\Service\Admin\SyncTracker;
use App\Service\MiningOperation\PersistedUuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class TriggerMiningOperationRecurrencesHandler
{
    private const string SYNC_KEY = 'mining-operation-recurrences';

    public function __construct(
        private MiningOperationRecurrenceRepository $recurrenceRepository,
        private MessageBusInterface $messageBus,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(TriggerMiningOperationRecurrences $message): void
    {
        $this->syncTracker->start(self::SYNC_KEY);

        try {
            $triggered = 0;
            foreach ($this->recurrenceRepository->findActive() as $recurrence) {
                $this->messageBus->dispatch(new CreateRecurringMiningOperations(PersistedUuid::of($recurrence->getId())));
                ++$triggered;
            }

            $this->syncTracker->complete(self::SYNC_KEY, sprintf('%d recurrence(s) triggered', $triggered));
        } catch (\Throwable $failure) {
            $this->syncTracker->fail(self::SYNC_KEY, $failure->getMessage());

            throw $failure;
        }
    }
}
