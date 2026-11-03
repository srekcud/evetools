<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningOperationLedgerResource;
use App\Entity\MiningLedgerSnapshot;
use App\Entity\MiningOperationCharacter;
use App\Entity\MiningOperationMember;
use App\Entity\User;
use App\Enum\MinedQuantityState;
use App\Enum\MiningLedgerSnapshotKind;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MinedQuantity;
use App\Service\MiningOperation\MinedQuantityCalculator;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationLifecycle;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\PersistedUuid;
use App\Service\TypeNameResolver;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MiningOperationLedgerResource>
 */
final readonly class MiningOperationLedgerProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private MinedQuantityCalculator $calculator,
        private MiningOperationLifecycle $lifecycle,
        private TypeNameResolver $typeNameResolver,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningOperationLedgerResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['operationId']);

        $this->access->assertCanParticipate($user, $miningOperation);

        $participants = $miningOperation->participantsInLedgerOrder();
        $endSnapshots = $this->endSnapshotsOf($participants);
        $waitingReadTimes = array_map(
            static fn (MiningLedgerSnapshot $snapshot): \DateTimeImmutable => $snapshot->getReadyAt() ?? throw new \LogicException('A waiting snapshot has a read time'),
            array_filter($endSnapshots, static fn (MiningLedgerSnapshot $snapshot): bool => $snapshot->getStatus() === MiningLedgerSnapshotStatus::Waiting),
        );

        $ledger = new MiningOperationLedgerResource();
        $ledger->operationId = PersistedUuid::of($miningOperation->getId());
        $ledger->status = $miningOperation->getStatus()->value;
        $ledger->startedAt = $this->inUtc($miningOperation->getStartedAt());
        $ledger->endedAt = $this->inUtc($miningOperation->getEndedAt());
        $ledger->solarSystemId = $miningOperation->getSolarSystemId();
        $ledger->solarSystemName = $miningOperation->getSolarSystemName();
        $ledger->endSnapshotsReadyAt = $waitingReadTimes === [] ? null : $this->inUtc(max($waitingReadTimes));
        $ledger->endSnapshotsPending = array_filter($endSnapshots, static fn (MiningLedgerSnapshot $snapshot): bool => $snapshot->getStatus() === MiningLedgerSnapshotStatus::Scheduling) !== [];
        $ledger->endSnapshotsSettled = count(array_filter($endSnapshots, static fn (MiningLedgerSnapshot $snapshot): bool => $snapshot->getStatus()->isSettled()));
        $ledger->endSnapshotsTotal = count($endSnapshots);
        $ledger->participants = array_map(fn (MiningOperationMember $member): array => $this->participantToArray($member, $user), $participants);

        return $ledger;
    }

    /**
     * @param list<MiningOperationMember> $participants
     *
     * @return list<MiningLedgerSnapshot>
     */
    private function endSnapshotsOf(array $participants): array
    {
        $endSnapshots = [];
        foreach ($participants as $member) {
            foreach ($member->getCharacters() as $operationCharacter) {
                $endSnapshot = $operationCharacter->snapshotOf(MiningLedgerSnapshotKind::End);
                if ($endSnapshot !== null) {
                    $endSnapshots[] = $endSnapshot;
                }
            }
        }

        return $endSnapshots;
    }

    /**
     * @return array<string, mixed>
     */
    private function participantToArray(MiningOperationMember $member, User $viewer): array
    {
        $quantity = $this->calculator->ofParticipant($member);

        return [
            'memberId' => PersistedUuid::of($member->getId()),
            'characterName' => $member->getUser()->getMainCharacter()?->getName(),
            'departedAt' => $this->formatted($member->getDepartedAt()),
            'state' => $quantity->state->value,
            'totalQuantity' => $quantity->totalQuantity(),
            'quantities' => $this->quantitiesToArray($quantity),
            'characters' => array_map(
                fn (MiningOperationCharacter $operationCharacter): array => $this->characterToArray($operationCharacter, $viewer),
                $this->charactersByName($member),
            ),
        ];
    }

    /**
     * @return list<MiningOperationCharacter>
     */
    private function charactersByName(MiningOperationMember $member): array
    {
        $operationCharacters = array_values($member->getCharacters()->toArray());
        usort($operationCharacters, static fn (MiningOperationCharacter $left, MiningOperationCharacter $right): int => $left->getCharacter()->getName() <=> $right->getCharacter()->getName());

        return $operationCharacters;
    }

    /**
     * @return array<string, mixed>
     */
    private function characterToArray(MiningOperationCharacter $operationCharacter, User $viewer): array
    {
        $quantity = $this->calculator->ofCharacter($operationCharacter);
        $measured = $this->calculator->measuredOf($operationCharacter);
        $corrections = $operationCharacter->latestCorrections();

        $quantities = [];
        foreach ($quantity->quantitiesByTypeId as $typeId => $typeQuantity) {
            $correction = $corrections[$typeId] ?? null;
            $quantities[] = [
                'typeId' => $typeId,
                'typeName' => $this->typeNameOf($typeId),
                'quantity' => $typeQuantity,
                'measuredQuantity' => $this->measuredQuantityOf($measured, $typeId),
                'correction' => $correction === null ? null : [
                    'originalQuantity' => $correction->getOriginalQuantity(),
                    'correctedQuantity' => $correction->getCorrectedQuantity(),
                    'correctedByName' => $correction->getCorrectedByName(),
                    'correctedAt' => $this->formatted($correction->getCorrectedAt()),
                ],
            ];
        }
        usort($quantities, static fn (array $left, array $right): int => $left['typeName'] <=> $right['typeName']);

        return [
            'id' => PersistedUuid::of($operationCharacter->getId()),
            'eveCharacterId' => $operationCharacter->getCharacter()->getEveCharacterId(),
            'name' => $operationCharacter->getCharacter()->getName(),
            'state' => $quantity->state->value,
            'unknownReason' => $quantity->unknownReason?->value,
            'totalQuantity' => $quantity->totalQuantity(),
            'startSnapshot' => $this->snapshotToArray($operationCharacter->snapshotOf(MiningLedgerSnapshotKind::Start)),
            'endSnapshot' => $this->snapshotToArray($operationCharacter->snapshotOf(MiningLedgerSnapshotKind::End)),
            'canRetry' => $this->lifecycle->canRetrySnapshot($operationCharacter)
                && $this->access->canRetrySnapshotOf($viewer, $operationCharacter),
            'quantities' => $quantities,
        ];
    }

    private function measuredQuantityOf(MinedQuantity $measured, int $typeId): ?int
    {
        if ($measured->state !== MinedQuantityState::Known) {
            return null;
        }

        return array_key_exists($typeId, $measured->quantitiesByTypeId) ? $measured->quantitiesByTypeId[$typeId] : 0;
    }

    /**
     * @return list<array{typeId: int, typeName: string, quantity: int}>
     */
    private function quantitiesToArray(MinedQuantity $quantity): array
    {
        $quantities = [];
        foreach ($quantity->quantitiesByTypeId as $typeId => $typeQuantity) {
            $quantities[] = ['typeId' => $typeId, 'typeName' => $this->typeNameOf($typeId), 'quantity' => $typeQuantity];
        }
        usort($quantities, static fn (array $left, array $right): int => $left['typeName'] <=> $right['typeName']);

        return $quantities;
    }

    /**
     * @return array{status: string, referenceAt: string|null, readyAt: string|null, takenAt: string|null}|null
     */
    private function snapshotToArray(?MiningLedgerSnapshot $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        return [
            'status' => $snapshot->getStatus()->value,
            'referenceAt' => $this->formatted($snapshot->getReferenceAt()),
            'readyAt' => $this->formatted($snapshot->getReadyAt()),
            'takenAt' => $this->formatted($snapshot->getTakenAt()),
        ];
    }

    private function typeNameOf(int $typeId): string
    {
        return $this->typeNameResolver->findName($typeId) ?? sprintf('Type %d', $typeId);
    }

    private function inUtc(?\DateTimeImmutable $moment): ?\DateTimeImmutable
    {
        return $moment?->setTimezone(new \DateTimeZone('UTC'));
    }

    private function formatted(?\DateTimeImmutable $moment): ?string
    {
        return $this->inUtc($moment)?->format(\DateTimeInterface::ATOM);
    }
}
