<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\MiningOperation;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Repository\MiningOperationCharacterRepository;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\MiningOperation\PersistsMiningOperations;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-262')]
final class MiningOperationCharacterRepositoryTest extends IntegrationTestCase
{
    use PersistsMiningOperations;

    private MiningOperationCharacterRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(MiningOperationCharacterRepository::class);
    }

    public function testEachCharacterEnrolledInAnOpenOperationIsHeldByThatOperation(): void
    {
        $cyra = $this->persistMiningPilot($this->em, 'Cyra Lune', self::C2);
        $brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1, ['Brann II', 'Brann IV', 'Brann V']);
        $iceBelt = $this->persistMiningOperation($this->em, $cyra, 'Ice belt — dimanche', '2026-11-08T18:00:00+00:00', corporationId: self::C2);
        $this->addMiningMembership($iceBelt, $brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann IV']);
        $ochreBelt = $this->persistMiningOperation($this->em, $cyra, 'Ochre belt — samedi', '2026-11-07T18:00:00+00:00', MiningOperationStatus::InProgress, corporationId: self::C2);
        $this->addMiningMembership($ochreBelt, $brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Brann II']);
        $closingBelt = $this->persistMiningOperation($this->em, $cyra, 'Kernite — lundi', '2026-11-02T18:00:00+00:00', MiningOperationStatus::Closing, corporationId: self::C2);
        $this->addMiningMembership($closingBelt, $brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann V']);
        $this->flushAndClear();

        self::assertSame(
            [
                'Brann II' => 'Ochre belt — samedi',
                'Brann IV' => 'Ice belt — dimanche',
                'Brann V' => 'Kernite — lundi',
            ],
            $this->heldCharacters(['Brann Holt', 'Brann II', 'Brann IV', 'Brann V']),
        );
    }

    public function testCompletedOperationNoLongerHoldsItsCharacters(): void
    {
        $cyra = $this->persistMiningPilot($this->em, 'Cyra Lune', self::C2);
        $brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1, ['Brann IV']);
        $iceBelt = $this->persistMiningOperation($this->em, $cyra, 'Ice belt — dimanche', '2026-11-01T18:00:00+00:00', MiningOperationStatus::Completed, corporationId: self::C2);
        $this->addMiningMembership($iceBelt, $brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann IV']);
        $this->flushAndClear();

        self::assertSame([], $this->heldCharacters(['Brann IV']));
    }

    public function testRemovedMemberNoLongerHoldsHisCharacters(): void
    {
        $cyra = $this->persistMiningPilot($this->em, 'Cyra Lune', self::C2);
        $brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1, ['Brann II']);
        $ochreBelt = $this->persistMiningOperation($this->em, $cyra, 'Ochre belt — samedi', '2026-11-07T18:00:00+00:00', corporationId: self::C2);
        $brannMembership = $this->addMiningMembership($ochreBelt, $brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Brann II']);
        $this->em->flush();
        $brannMembership->remove();
        $this->flushAndClear();

        self::assertSame([], $this->heldCharacters(['Brann II']));
    }

    private function heldCharacters(array $characterNames): array
    {
        $namesById = [];
        $characters = [];
        foreach ($characterNames as $characterName) {
            $character = $this->em->find($this->miningCharacters[$characterName]::class, $this->miningCharacters[$characterName]->getId());
            $namesById[$character->getId()->toRfc4122()] = $characterName;
            $characters[] = $character;
        }

        $held = [];
        foreach ($this->repository->findOpenEnrolmentsOf($characters) as $characterId => $operation) {
            \assert($operation instanceof MiningOperation);
            $held[$namesById[$characterId]] = $operation->getLabel();
        }
        ksort($held);

        return $held;
    }
}
