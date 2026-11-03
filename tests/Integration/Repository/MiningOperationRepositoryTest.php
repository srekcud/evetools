<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\MiningOperation;
use App\Entity\User;
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

    #[Group('issue-265')]
    public function testBrannFindsTheOperationsHeOwnsOrIsPendingOrAcceptedInExceptTheArchivedOnesByPlannedStartDescending(): void
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

        self::assertSame(['O8', 'O1', 'O6'], $this->labelsOf($this->repository->findForParticipant($brann)));
    }

    #[Group('issue-265')]
    public function testBrannFindsTheArchivedOperationsHeTookPartInOrHisCorporationMayReadLatestArchiveFirst(): void
    {
        $aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1);
        $brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1);
        $cyra = $this->persistMiningPilot($this->em, 'Cyra Lune', self::C2);
        $a1 = $this->archivedOperation($aria, 'A1', MiningOperationStatus::Settled, '2026-11-05T23:35:00+00:00');
        $this->addMiningMembership($a1, $brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, []);
        $this->archivedOperation($brann, 'A2', MiningOperationStatus::Completed, '2026-11-04T22:10:00+00:00');
        $a3 = $this->archivedOperation($cyra, 'A3', MiningOperationStatus::Settled, '2026-11-05T22:00:00+00:00', MiningOperationVisibility::Private, self::C2);
        $this->addMiningMembership($a3, $brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, []);
        $a4 = $this->archivedOperation($cyra, 'A4', MiningOperationStatus::Settled, '2026-11-05T21:00:00+00:00', MiningOperationVisibility::Private, self::C2);
        $this->addMiningMembership($a4, $brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Removed, []);
        $this->archivedOperation($aria, 'A5', MiningOperationStatus::Completed, '2026-11-03T21:00:00+00:00');
        $this->archivedOperation($aria, 'A6', MiningOperationStatus::Completed, '2026-11-05T20:00:00+00:00', MiningOperationVisibility::Private);
        $this->archivedOperation($cyra, 'A7', MiningOperationStatus::Settled, '2026-11-05T20:30:00+00:00', corporationId: self::C2);
        $a8 = $this->persistMiningOperation($this->em, $aria, 'A8', '2026-11-05T20:00:00+00:00', MiningOperationStatus::Shares);
        $this->addMiningMembership($a8, $brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, []);
        $this->flushAndClear();
        $brann = $this->em->find($brann::class, $brann->getId());

        self::assertSame(['A1', 'A2', 'A5'], $this->labelsOf($this->repository->findArchivedReadableBy($brann)));
    }

    private function archivedOperation(
        User $owner,
        string $label,
        MiningOperationStatus $status,
        string $archivedAt,
        MiningOperationVisibility $visibility = MiningOperationVisibility::Corporation,
        int $corporationId = self::C1,
    ): MiningOperation {
        $operation = $this->persistMiningOperation($this->em, $owner, $label, '2026-11-01T20:00:00+00:00', visibility: $visibility, corporationId: $corporationId);
        if ($status === MiningOperationStatus::Settled) {
            $operation->settle(new \DateTimeImmutable($archivedAt), $owner->getMainCharacter()?->getName());
        } else {
            $operation->complete(new \DateTimeImmutable($archivedAt), null, [], [], $owner->getMainCharacter()?->getName());
        }
        $operation->setStatus($status);

        return $operation;
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
