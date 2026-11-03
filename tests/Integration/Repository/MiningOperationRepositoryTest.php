<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\MiningOperation;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningOperationVisibility;
use App\Repository\MiningOperationRepository;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\MiningOperation\PersistsMiningOperations;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-262')]
final class MiningOperationRepositoryTest extends IntegrationTestCase
{
    use PersistsMiningOperations;

    private MiningOperationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(MiningOperationRepository::class);
    }

    public function testBrannFindsTheOpenCorporationOperationsOfHisCorporationHeHasNoMembershipInByPlannedStart(): void
    {
        $aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1);
        $brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1);
        $cyra = $this->persistMiningPilot($this->em, 'Cyra Lune', self::C2);
        $this->persistMiningOperation($this->em, $aria, 'O1', '2026-11-05T20:00:00+00:00');
        $this->persistMiningOperation($this->em, $aria, 'O2', '2026-11-05T21:00:00+00:00', visibility: MiningOperationVisibility::Private);
        $this->persistMiningOperation($this->em, $aria, 'O3', '2026-11-01T20:00:00+00:00', MiningOperationStatus::Completed);
        $this->persistMiningOperation($this->em, $aria, 'O4', '2026-11-02T20:00:00+00:00', MiningOperationStatus::Closing);
        $this->persistMiningOperation($this->em, $aria, 'O5', '2026-11-04T20:00:00+00:00', MiningOperationStatus::InProgress);
        $o6 = $this->persistMiningOperation($this->em, $aria, 'O6', '2026-11-03T20:00:00+00:00');
        $this->addMiningMembership($o6, $brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, []);
        $o7 = $this->persistMiningOperation($this->em, $aria, 'O7', '2026-11-03T21:00:00+00:00');
        $this->addMiningMembership($o7, $brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Removed, []);
        $this->persistMiningOperation($this->em, $brann, 'O8', '2026-11-06T20:00:00+00:00');
        $this->persistMiningOperation($this->em, $cyra, 'O9', '2026-11-05T19:00:00+00:00', corporationId: self::C2);
        $this->flushAndClear();
        $brann = $this->em->find($brann::class, $brann->getId());

        self::assertSame(['O5', 'O1'], $this->labelsOf($this->repository->findAvailableForCorporation(self::C1, $brann)));
    }

    public function testBrannFindsTheOperationsHeOwnsOrIsPendingOrAcceptedInByPlannedStartDescending(): void
    {
        $aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1);
        $brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1);
        $this->persistMiningOperation($this->em, $brann, 'O8', '2026-11-06T20:00:00+00:00');
        $o1 = $this->persistMiningOperation($this->em, $aria, 'O1', '2026-11-05T20:00:00+00:00');
        $this->addMiningMembership($o1, $brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, []);
        $o6 = $this->persistMiningOperation($this->em, $aria, 'O6', '2026-11-03T20:00:00+00:00');
        $this->addMiningMembership($o6, $brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, []);
        $o7 = $this->persistMiningOperation($this->em, $aria, 'O7', '2026-11-04T20:00:00+00:00');
        $this->addMiningMembership($o7, $brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Removed, []);
        $o3 = $this->persistMiningOperation($this->em, $aria, 'O3', '2026-11-01T20:00:00+00:00', MiningOperationStatus::Completed);
        $this->addMiningMembership($o3, $brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, []);
        $this->persistMiningOperation($this->em, $aria, 'O2', '2026-11-02T20:00:00+00:00');
        $this->flushAndClear();
        $brann = $this->em->find($brann::class, $brann->getId());

        self::assertSame(['O8', 'O1', 'O6', 'O3'], $this->labelsOf($this->repository->findForParticipant($brann)));
    }

    public function testRenewedJoinCodeFindsTheOperationAndThePreviousOneNoLonger(): void
    {
        $aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1);
        $operation = $this->persistMiningOperation($this->em, $aria, 'Anomalie Spodumain — 20:00', '2026-11-05T20:00:00+00:00');
        $operation->setJoinCode('7f3a9c2e1b');
        $this->em->flush();
        $operation->renewJoinCode();
        $renewedJoinCode = $operation->getJoinCode();
        $this->flushAndClear();

        self::assertNull($this->repository->findOneByJoinCode('7f3a9c2e1b'));
        self::assertSame('Anomalie Spodumain — 20:00', $this->repository->findOneByJoinCode($renewedJoinCode)?->getLabel());
    }

    private function labelsOf(array $operations): array
    {
        return array_map(static fn (MiningOperation $operation): string => $operation->getLabel(), $operations);
    }
}
