<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\IndustryStructureConfigRepository;
use App\Tests\Integration\IntegrationTestCase;

/**
 * Issue #36: findCorporationSharedStructures feeds GET /industry/corporation-structures.
 * Only the owner can delete a structure; a corporation structure is then soft-deleted
 * (hideForUser(owner) + isDeleted = true) and stays shared with the rest of the corporation.
 */
final class IndustryStructureConfigRepositoryTest extends IntegrationTestCase
{
    private const int CORPORATION_ID = 98_000_001;
    private const int OTHER_CORPORATION_ID = 98_000_002;
    private const int SOTIYO_LOCATION_ID = 1_035_466_617_946;
    private const int AZBEL_LOCATION_ID = 1_035_466_617_947;

    private IndustryStructureConfigRepository $repository;
    private User $owner;
    private User $corpMate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(IndustryStructureConfigRepository::class);
        $this->owner = $this->createUser();
        $this->corpMate = $this->createUser();
    }

    public function testStructureDeletedByOwnerIsAbsentFromOwnerCorporationStructures(): void
    {
        $this->createDeletedCorporationStructure($this->owner, self::SOTIYO_LOCATION_ID);
        $this->flushAndClear();

        self::assertSame([], $this->locationIdsSharedWith($this->owner));
    }

    public function testStructureDeletedByOwnerIsStillListedForAnotherCorporationMember(): void
    {
        $this->createDeletedCorporationStructure($this->owner, self::SOTIYO_LOCATION_ID);
        $this->flushAndClear();

        self::assertSame([self::SOTIYO_LOCATION_ID], $this->locationIdsSharedWith($this->corpMate));
    }

    public function testActiveStructuresOfOtherMembersAreListed(): void
    {
        $this->createCorporationStructure($this->corpMate, self::SOTIYO_LOCATION_ID, 'Corp Sotiyo');
        $this->createCorporationStructure($this->corpMate, self::AZBEL_LOCATION_ID, 'Corp Azbel');
        $this->flushAndClear();

        $shared = $this->repository->findCorporationSharedStructures(self::CORPORATION_ID, $this->owner);

        self::assertEqualsCanonicalizing([self::SOTIYO_LOCATION_ID, self::AZBEL_LOCATION_ID], array_keys($shared));
        self::assertSame('Corp Sotiyo', $shared[self::SOTIYO_LOCATION_ID]->getName());
        self::assertSame('Corp Azbel', $shared[self::AZBEL_LOCATION_ID]->getName());
    }

    public function testActiveStructuresOfCurrentUserAreNotListed(): void
    {
        $this->createCorporationStructure($this->owner, self::SOTIYO_LOCATION_ID, 'My Sotiyo');
        $this->flushAndClear();

        self::assertSame([], $this->locationIdsSharedWith($this->owner));
    }

    public function testStructuresOfOtherCorporationsAreNotListed(): void
    {
        $this->createCorporationStructure($this->corpMate, self::SOTIYO_LOCATION_ID, 'Foreign Sotiyo', self::OTHER_CORPORATION_ID);
        $this->flushAndClear();

        self::assertSame([], $this->locationIdsSharedWith($this->owner));
    }

    /** @return list<int> */
    private function locationIdsSharedWith(User $user): array
    {
        return array_keys($this->repository->findCorporationSharedStructures(self::CORPORATION_ID, $user));
    }

    /** Same state as DeleteStructureProcessor leaves for a corporation structure. */
    private function createDeletedCorporationStructure(User $owner, int $locationId): void
    {
        $structure = $this->createCorporationStructure($owner, $locationId, 'Deleted Sotiyo');
        $this->em->flush(); // makes sure the owner has its UUID before hiding
        $structure->hideForUser((string) $owner->getId()?->toRfc4122());
        $structure->setIsDeleted(true);
    }

    private function createCorporationStructure(
        User $owner,
        int $locationId,
        string $name,
        int $corporationId = self::CORPORATION_ID,
    ): IndustryStructureConfig {
        $structure = (new IndustryStructureConfig())
            ->setUser($owner)
            ->setName($name)
            ->setLocationId($locationId)
            ->setSolarSystemId(30_000_142)
            ->setCorporationId($corporationId)
            ->setIsCorporationStructure(true)
            ->setSecurityType('nullsec')
            ->setStructureType('sotiyo')
            ->setRigs(['Standup XL-Set Equipment and Consumable Manufacturing Efficiency II']);

        $this->em->persist($structure);

        return $structure;
    }
}
