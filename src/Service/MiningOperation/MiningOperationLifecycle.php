<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\MiningOperationCharacter;
use App\Entity\MiningOperationMember;
use App\Entity\User;
use App\Repository\Sde\MapSolarSystemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class MiningOperationLifecycle
{
    public function __construct(
        private MapSolarSystemRepository $solarSystemRepository,
        private EntityManagerInterface $entityManager,
        private MiningLedgerSnapshotDispatcher $dispatcher,
        private ClockInterface $clock,
    ) {
    }

    public function start(MiningOperation $operation, ?int $solarSystemId): void
    {
    }

    public function end(MiningOperation $operation): void
    {
    }

    public function depart(MiningOperation $operation, User $user): void
    {
    }

    public function snapshotLateParticipant(MiningOperationMember $member): void
    {
    }

    public function retrySnapshot(MiningOperationCharacter $operationCharacter): void
    {
    }

    public function canRetrySnapshot(MiningOperationCharacter $operationCharacter): bool
    {
        return false;
    }
}
