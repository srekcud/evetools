<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningLedgerSnapshot;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationCharacter;
use App\Entity\MiningOperationMember;
use App\Entity\User;
use App\Enum\MiningLedgerSnapshotKind;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Repository\Sde\MapSolarSystemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class MiningOperationLifecycle
{
    private const int STALLED_SNAPSHOT_SECONDS = 300;

    public function __construct(
        private MapSolarSystemRepository $solarSystemRepository,
        private EntityManagerInterface $entityManager,
        private MiningLedgerSnapshotDispatcher $dispatcher,
        private ClockInterface $clock,
    ) {
    }

    public function start(MiningOperation $operation, ?int $solarSystemId): void
    {
        if ($operation->getStatus() !== MiningOperationStatus::Planned) {
            throw new BadRequestHttpException('Only a planned operation can be started');
        }

        $solarSystem = $solarSystemId === null
            ? null
            : $this->solarSystemRepository->find($solarSystemId) ?? throw new BadRequestHttpException('Unknown solar system');

        $startedAt = $this->clock->now();
        $operation->start($startedAt, $solarSystem);

        $snapshots = [];
        foreach ($operation->acceptedMembers() as $member) {
            foreach ($member->getCharacters() as $operationCharacter) {
                $snapshots[] = $operationCharacter->scheduleStartSnapshot($startedAt);
            }
        }

        $this->saveAndDispatch($snapshots);
    }

    public function end(MiningOperation $operation): void
    {
        if ($operation->getStatus() !== MiningOperationStatus::InProgress) {
            throw new BadRequestHttpException('Only an operation in progress can be ended');
        }

        $endedAt = $this->clock->now();
        $operation->end($endedAt);

        $snapshots = [];
        foreach ($operation->acceptedMembers() as $member) {
            array_push($snapshots, ...$this->scheduleEndSnapshotsOf($member, $endedAt));
        }

        $this->saveAndDispatch($snapshots);
    }

    public function depart(MiningOperation $operation, User $user): void
    {
        $member = $operation->membershipOf($user);
        if ($member?->getStatus() !== MiningOperationMemberStatus::Accepted) {
            throw new NotFoundHttpException('Membership not found');
        }
        if ($operation->getStatus() !== MiningOperationStatus::InProgress) {
            throw new BadRequestHttpException('Only an operation in progress can be left early');
        }
        if ($member->getDepartedAt() !== null) {
            throw new BadRequestHttpException('You already left this operation');
        }

        $departedAt = $this->clock->now();
        $member->depart($departedAt);

        $this->saveAndDispatch($this->scheduleEndSnapshotsOf($member, $departedAt));
    }

    public function snapshotLateParticipant(MiningOperationMember $member): void
    {
        if ($member->getOperation()->getStatus() !== MiningOperationStatus::InProgress || $member->getStatus() !== MiningOperationMemberStatus::Accepted) {
            return;
        }

        $acceptedAt = $this->clock->now();
        $snapshots = [];
        foreach ($member->getCharacters() as $operationCharacter) {
            if ($operationCharacter->snapshotOf(MiningLedgerSnapshotKind::Start) === null) {
                $snapshots[] = $operationCharacter->scheduleStartSnapshot($acceptedAt);
            }
        }

        $this->saveAndDispatch($snapshots);
    }

    public function retrySnapshot(MiningOperationCharacter $operationCharacter): void
    {
        $snapshot = $this->retryableSnapshotOf($operationCharacter) ?? throw new BadRequestHttpException('This snapshot cannot be retried');

        $now = $this->clock->now();
        if ($snapshot->getKind() === MiningLedgerSnapshotKind::Start) {
            $snapshot->restartFrom($now);
        } else {
            $snapshot->schedule($now);
        }

        $this->saveAndDispatch([$snapshot]);
    }

    public function canRetrySnapshot(MiningOperationCharacter $operationCharacter): bool
    {
        return $this->retryableSnapshotOf($operationCharacter) !== null;
    }

    private function retryableSnapshotOf(MiningOperationCharacter $operationCharacter): ?MiningLedgerSnapshot
    {
        $retryable = match ($operationCharacter->getMember()->getOperation()->getStatus()) {
            MiningOperationStatus::InProgress => $operationCharacter->snapshotOf(MiningLedgerSnapshotKind::Start),
            MiningOperationStatus::Closing => $operationCharacter->snapshotOf(MiningLedgerSnapshotKind::End),
            MiningOperationStatus::Planned, MiningOperationStatus::Completed => null,
        };

        if ($retryable === null) {
            return null;
        }

        return match ($retryable->getStatus()) {
            MiningLedgerSnapshotStatus::ReconnectRequired, MiningLedgerSnapshotStatus::Failed => $retryable,
            MiningLedgerSnapshotStatus::Scheduling, MiningLedgerSnapshotStatus::Waiting => $this->isStalled($retryable) ? $retryable : null,
            MiningLedgerSnapshotStatus::Taken, MiningLedgerSnapshotStatus::OutOfWindow => null,
        };
    }

    private function isStalled(MiningLedgerSnapshot $snapshot): bool
    {
        if ($snapshot->getKind() === MiningLedgerSnapshotKind::Start) {
            return false;
        }

        $readExpectedAt = $snapshot->getReadyAt() ?? $snapshot->getReferenceAt();

        return $this->clock->now() > $readExpectedAt->modify(sprintf('+%d seconds', self::STALLED_SNAPSHOT_SECONDS));
    }

    /**
     * @return list<MiningLedgerSnapshot>
     */
    private function scheduleEndSnapshotsOf(MiningOperationMember $member, \DateTimeImmutable $referenceAt): array
    {
        $snapshots = [];
        foreach ($member->getCharacters() as $operationCharacter) {
            $start = $operationCharacter->snapshotOf(MiningLedgerSnapshotKind::Start);
            if ($start?->getStatus() === MiningLedgerSnapshotStatus::Taken && $operationCharacter->snapshotOf(MiningLedgerSnapshotKind::End) === null) {
                $snapshots[] = $operationCharacter->scheduleEndSnapshot($referenceAt);
            }
        }

        return $snapshots;
    }

    /**
     * @param list<MiningLedgerSnapshot> $snapshots
     */
    private function saveAndDispatch(array $snapshots): void
    {
        $this->entityManager->flush();
        $this->dispatcher->dispatch(...$snapshots);
    }
}
