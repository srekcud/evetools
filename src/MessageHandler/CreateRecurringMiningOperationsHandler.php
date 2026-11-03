<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\MiningOperation;
use App\Message\CreateRecurringMiningOperations;
use App\Repository\MiningOperationRecurrenceRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationOccurrenceCreator;
use App\Service\MiningOperation\PersistedUuid;
use App\Service\UuidOrNotFound;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CreateRecurringMiningOperationsHandler
{
    private const string OCCURRENCE_CREATED_EVENT = 'recurrence_created';

    public function __construct(
        private MiningOperationRecurrenceRepository $recurrenceRepository,
        private MiningOperationOccurrenceCreator $occurrenceCreator,
        private MercurePublisherService $mercurePublisher,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CreateRecurringMiningOperations $message): void
    {
        $recurrence = $this->recurrenceRepository->find(UuidOrNotFound::of($message->recurrenceId, 'Mining operation recurrence not found'));
        if ($recurrence === null) {
            return;
        }

        foreach ($this->occurrenceCreator->createDueOccurrences($recurrence, $this->clock->now()) as $occurrence) {
            $this->announce($occurrence);
        }
    }

    private function announce(MiningOperation $occurrence): void
    {
        $this->mercurePublisher->publishMiningOperationEvent(
            [PersistedUuid::of($occurrence->getOwner()->getId())],
            PersistedUuid::of($occurrence->getId()),
            self::OCCURRENCE_CREATED_EVENT,
        );
    }
}
