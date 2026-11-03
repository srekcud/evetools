<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningLedgerSnapshot;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationCharacter;
use App\Entity\Sde\MapSolarSystem;
use App\Enum\MiningLedgerSnapshotKind;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Repository\MiningOperationCharacterRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\MiningOperation\MinedQuantity;
use App\Service\MiningOperation\MiningLedgerEntry;
use App\Service\MiningOperation\MiningLedgerReading;
use App\Service\MiningOperation\MiningLedgerSnapshotDispatcher;
use App\Service\MiningOperation\MiningOperationLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

trait BuildsLedgerSnapshots
{
    private const int SPODUMAIN = 19;
    private const int BISTOT = 1223;
    private const int S1 = 30_003_504;
    private const string S1_NAME = 'Aldranette';
    private const int S2 = 30_002_813;
    private const string S2_NAME = 'Tama';
    private const int UNKNOWN_SOLAR_SYSTEM = 1;
    private const string STARTED_AT = '2026-11-05T20:00:00+00:00';
    private const string ENDED_AT = '2026-11-05T21:30:00+00:00';
    private const string SNAPSHOT_SYNC_TYPE = 'mining-operation-snapshot';

    private function solarSystemRepositoryKnowingS1AndS2(): MapSolarSystemRepository
    {
        $systems = [self::S1 => self::S1_NAME, self::S2 => self::S2_NAME];
        $resolve = static function (mixed $solarSystemId) use ($systems): ?MapSolarSystem {
            if (!isset($systems[$solarSystemId])) {
                return null;
            }

            return (new MapSolarSystem())->setSolarSystemId($solarSystemId)->setSolarSystemName($systems[$solarSystemId]);
        };
        $repository = $this->createStub(MapSolarSystemRepository::class);
        $repository->method('find')->willReturnCallback($resolve);
        $repository->method('findBySolarSystemId')->willReturnCallback($resolve);

        return $repository;
    }

    private function s1(): MapSolarSystem
    {
        return (new MapSolarSystem())->setSolarSystemId(self::S1)->setSolarSystemName(self::S1_NAME);
    }

    private function spodumainOperationWithBrann(): MiningOperation
    {
        $operation = $this->spodumainOperation();
        $this->addMembership($operation, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt', 'Brann II']);
        $this->identifyEnrolments();

        return $operation;
    }

    private function startedSpodumainOperation(string $startedAt = self::STARTED_AT): MiningOperation
    {
        $operation = $this->spodumainOperationWithBrann();
        $operation->start(new \DateTimeImmutable($startedAt), $this->s1());

        return $operation;
    }

    private function identifyEnrolments(): void
    {
        foreach ($this->operations as $operation) {
            foreach ($operation->getMembers() as $member) {
                foreach ($member->getCharacters() as $enrolment) {
                    if ($enrolment->getId() === null) {
                        (new \ReflectionProperty(MiningOperationCharacter::class, 'id'))->setValue($enrolment, Uuid::v4());
                    }
                    foreach ($enrolment->getSnapshots() as $snapshot) {
                        $this->identify($snapshot);
                    }
                }
            }
        }
    }

    private function identify(MiningLedgerSnapshot $snapshot): MiningLedgerSnapshot
    {
        if ($snapshot->getId() === null) {
            (new \ReflectionProperty(MiningLedgerSnapshot::class, 'id'))->setValue($snapshot, Uuid::v4());
        }

        return $snapshot;
    }

    private function identifyingOnFlush(EntityManagerInterface&MockObject $entityManager, ?\ArrayObject $journal = null): void
    {
        $entityManager->method('flush')->willReturnCallback(function () use ($journal): void {
            $journal?->append('flush');
            $this->identifyEnrolments();
        });
    }

    private function enrolmentOf(string $characterName, ?MiningOperation $operation = null): MiningOperationCharacter
    {
        foreach ($operation === null ? $this->operations : [$operation] as $candidate) {
            foreach ($candidate->getMembers() as $member) {
                foreach ($member->getCharacters() as $enrolment) {
                    if ($enrolment->getCharacter()->getName() === $characterName) {
                        return $enrolment;
                    }
                }
            }
        }

        throw new \LogicException(sprintf('%s is not enrolled', $characterName));
    }

    private function entry(string $date, int $typeId, int $solarSystemId, int $quantity): MiningLedgerEntry
    {
        return new MiningLedgerEntry(new \DateTimeImmutable($date . 'T00:00:00+00:00'), $typeId, $solarSystemId, $quantity);
    }

    private function reading(array $entries, ?string $expiresAt = null): MiningLedgerReading
    {
        return new MiningLedgerReading($entries, $expiresAt === null ? null : new \DateTimeImmutable($expiresAt));
    }

    private function takenStartSnapshot(string $characterName, array $entries, string $takenAt = self::STARTED_AT): MiningLedgerSnapshot
    {
        $snapshot = $this->identify($this->enrolmentOf($characterName)->scheduleStartSnapshot(new \DateTimeImmutable($takenAt)));
        $snapshot->take(new \DateTimeImmutable($takenAt), $this->reading($entries));

        return $snapshot;
    }

    private function failedStartSnapshot(string $characterName, MiningLedgerSnapshotStatus $status, string $referenceAt = self::STARTED_AT): MiningLedgerSnapshot
    {
        $snapshot = $this->identify($this->enrolmentOf($characterName)->scheduleStartSnapshot(new \DateTimeImmutable($referenceAt)));
        $snapshot->fail($status, 'ESI server error');

        return $snapshot;
    }

    private function waitingEndSnapshot(string $characterName, string $readyAt, string $referenceAt = self::ENDED_AT): MiningLedgerSnapshot
    {
        $snapshot = $this->identify($this->enrolmentOf($characterName)->scheduleEndSnapshot(new \DateTimeImmutable($referenceAt)));
        $snapshot->schedule(new \DateTimeImmutable($readyAt));

        return $snapshot;
    }

    private function takenEndSnapshot(string $characterName, array $entries, string $takenAt = '2026-11-05T21:42:00+00:00', string $referenceAt = self::ENDED_AT): MiningLedgerSnapshot
    {
        $snapshot = $this->waitingEndSnapshot($characterName, $takenAt, $referenceAt);
        $snapshot->take(new \DateTimeImmutable($takenAt), $this->reading($entries));

        return $snapshot;
    }

    private function failedEndSnapshot(string $characterName, MiningLedgerSnapshotStatus $status): MiningLedgerSnapshot
    {
        $snapshot = $this->waitingEndSnapshot($characterName, '2026-11-05T21:37:17+00:00');
        $snapshot->fail($status, 'ESI server error');

        return $snapshot;
    }

    private function snapshotStateOf(?MiningLedgerSnapshot $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        return [
            'kind' => $snapshot->getKind()->value,
            'status' => $snapshot->getStatus()->value,
            'referenceAt' => $snapshot->getReferenceAt()->format(\DateTimeInterface::ATOM),
            'readyAt' => $snapshot->getReadyAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function snapshotStatesOf(MiningOperation $operation, MiningLedgerSnapshotKind $kind): array
    {
        $states = [];
        foreach ($operation->getMembers() as $member) {
            foreach ($member->getCharacters() as $enrolment) {
                $snapshot = $enrolment->snapshotOf($kind);
                if ($snapshot !== null) {
                    $states[$enrolment->getCharacter()->getName()] = $this->snapshotStateOf($snapshot);
                }
            }
        }
        ksort($states);

        return $states;
    }

    private function quantityOf(MinedQuantity $quantity): array
    {
        $quantities = $quantity->quantitiesByTypeId;
        ksort($quantities);

        return [
            'state' => $quantity->state->value,
            'unknownReason' => $quantity->unknownReason?->value,
            'quantities' => $quantities,
            'totalQuantity' => $quantity->totalQuantity(),
        ];
    }

    private function busCapturing(\ArrayObject $dispatched, ?\ArrayObject $journal = null): MessageBusInterface
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message, array $stamps = []) use ($dispatched, $journal): Envelope {
            $envelope = Envelope::wrap($message, $stamps);
            $dispatched->append($envelope);
            $journal?->append('dispatch');

            return $envelope;
        });

        return $bus;
    }

    private function dispatchedSnapshots(\ArrayObject $dispatched): array
    {
        return array_map(static fn (Envelope $envelope): array => [
            'snapshotId' => $envelope->getMessage()->snapshotId,
            'delay' => $envelope->last(DelayStamp::class)?->getDelay(),
        ], $dispatched->getArrayCopy());
    }

    private function operationCharacterRepositoryReadingOperations(): MiningOperationCharacterRepository
    {
        $repository = $this->createStub(MiningOperationCharacterRepository::class);
        $repository->method('find')->willReturnCallback(function (mixed $id): ?MiningOperationCharacter {
            foreach ($this->operations as $operation) {
                foreach ($operation->getMembers() as $member) {
                    foreach ($member->getCharacters() as $enrolment) {
                        if ((string) $enrolment->getId() === (string) $id) {
                            return $enrolment;
                        }
                    }
                }
            }

            return null;
        });

        return $repository;
    }

    private function lifecycleWith(EntityManagerInterface $entityManager, \ArrayObject $dispatched, ClockInterface $clock): MiningOperationLifecycle
    {
        return new MiningOperationLifecycle(
            $this->solarSystemRepositoryKnowingS1AndS2(),
            $entityManager,
            new MiningLedgerSnapshotDispatcher($this->busCapturing($dispatched), $clock),
            $clock,
        );
    }
}
