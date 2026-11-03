<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningOperationRecurrenceResource;
use App\Entity\MiningOperationRecurrence;
use App\Enum\MiningOperationRecurrenceKind;
use App\Repository\MiningOperationRecurrenceRepository;
use App\Service\MiningOperation\MiningOperationOccurrenceCreator;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\PersistedUuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;

/**
 * @implements ProviderInterface<MiningOperationRecurrenceResource>
 */
final readonly class MyMiningOperationRecurrencesProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRecurrenceRepository $recurrenceRepository,
        private MiningOperationOccurrenceCreator $occurrenceCreator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<MiningOperationRecurrenceResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = MiningOperationRequest::userOf($this->security);
        $now = $this->clock->now();

        return array_map(
            fn (MiningOperationRecurrence $recurrence): MiningOperationRecurrenceResource => $this->toResource($recurrence, $now),
            $this->recurrenceRepository->findActiveOwnedBy($user),
        );
    }

    private function toResource(MiningOperationRecurrence $recurrence, \DateTimeImmutable $now): MiningOperationRecurrenceResource
    {
        $resource = new MiningOperationRecurrenceResource();
        $resource->id = PersistedUuid::of($recurrence->getId());
        $resource->kind = $recurrence->getKind()->value;
        $resource->label = $recurrence->getLabel();
        $resource->structureId = $recurrence->getStructureId();
        $resource->structureName = $recurrence->getStructureName();
        $resource->nextOccurrenceAt = $recurrence->getKind() === MiningOperationRecurrenceKind::Weekly
            ? $this->occurrenceCreator->nextWeeklyStartAtOf($recurrence, $now)
            : null;
        $resource->createdAt = $recurrence->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'));

        return $resource;
    }
}
