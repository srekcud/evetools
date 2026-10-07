<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\StructureConfigResource;
use App\ApiResource\Input\Industry\CreateStructureInput;
use App\Entity\CachedStructure;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\State\Processor\Industry\CreateStructureProcessor;
use App\State\Provider\Industry\IndustryResourceMapper;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Issue #36: re-importing a corporation structure (POST /industry/structures with the same
 * locationId) restores the user's own soft-deleted row instead of creating a duplicate.
 *
 * Integration rather than unit: the processor has no repository method today that finds a
 * soft-deleted row, so a unit test would have to invent its name. Here the real repository
 * and database decide, and we assert on the rows actually stored.
 */
final class CreateStructureProcessorTest extends IntegrationTestCase
{
    private const int CORPORATION_ID = 98_000_001;
    private const int SOTIYO_LOCATION_ID = 1_035_466_617_946;
    private const int OTHER_LOCATION_ID = 1_035_466_617_947;

    private User $owner;
    private User $corpMate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->createCorporationMember('Owner Pilot');
        $this->corpMate = $this->createCorporationMember('Corp Mate Pilot');
        $this->createCachedCorporationStructure(self::SOTIYO_LOCATION_ID);
        $this->createCachedCorporationStructure(self::OTHER_LOCATION_ID);
        $this->em->flush();
    }

    public function testReimportingOwnDeletedCorporationStructureRestoresItInsteadOfDuplicating(): void
    {
        $deletedStructureId = $this->createDeletedCorporationStructure($this->owner, self::SOTIYO_LOCATION_ID);

        $resource = $this->importAs($this->owner, self::SOTIYO_LOCATION_ID);

        $rows = $this->structuresOf($this->owner, self::SOTIYO_LOCATION_ID);
        self::assertCount(1, $rows);
        self::assertSame($deletedStructureId, $rows[0]->getId()->toRfc4122());
        self::assertSame($deletedStructureId, $resource->id);
        self::assertFalse($rows[0]->isDeleted());
        self::assertFalse($rows[0]->isHiddenForUser($this->userIdOf($this->owner)));
    }

    public function testReimportedCorporationStructureIsBackInOwnerStructures(): void
    {
        $deletedStructureId = $this->createDeletedCorporationStructure($this->owner, self::SOTIYO_LOCATION_ID);

        $this->importAs($this->owner, self::SOTIYO_LOCATION_ID);

        $active = $this->repository()->findByUser($this->owner);
        self::assertCount(1, $active);
        self::assertSame($deletedStructureId, $active[0]->getId()->toRfc4122());
    }

    public function testImportingDoesNotTakeOverAnotherMemberDeletedStructure(): void
    {
        $corpMateStructureId = $this->createDeletedCorporationStructure($this->corpMate, self::SOTIYO_LOCATION_ID);

        $resource = $this->importAs($this->owner, self::SOTIYO_LOCATION_ID);

        self::assertNotSame($corpMateStructureId, $resource->id);
        self::assertCount(1, $this->structuresOf($this->owner, self::SOTIYO_LOCATION_ID));
        $corpMateRows = $this->structuresOf($this->corpMate, self::SOTIYO_LOCATION_ID);
        self::assertCount(1, $corpMateRows);
        self::assertTrue($corpMateRows[0]->isDeleted());
    }

    public function testImportingANewCorporationStructureCreatesOneRow(): void
    {
        $this->createDeletedCorporationStructure($this->owner, self::SOTIYO_LOCATION_ID);

        $this->importAs($this->owner, self::OTHER_LOCATION_ID);

        $rows = $this->structuresOf($this->owner, self::OTHER_LOCATION_ID);
        self::assertCount(1, $rows);
        self::assertFalse($rows[0]->isDeleted());
        self::assertTrue($rows[0]->isCorporationStructure());
        self::assertSame(self::CORPORATION_ID, $rows[0]->getCorporationId());
    }

    private function importAs(User $user, int $locationId): StructureConfigResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $processor = new CreateStructureProcessor(
            $security,
            $this->repository(),
            self::getContainer()->get(CachedStructureRepository::class),
            self::getContainer()->get(IndustryResourceMapper::class),
            $this->em,
        );

        // Same payload the frontend sends after picking a structure in the corporation list
        $input = new CreateStructureInput();
        $input->name = 'Corp Sotiyo';
        $input->locationId = $locationId;
        $input->securityType = 'nullsec';
        $input->structureType = 'sotiyo';
        $input->rigs = ['Standup XL-Set Equipment and Consumable Manufacturing Efficiency II'];

        return $processor->process($input, new Post());
    }

    /** Same state as DeleteStructureProcessor leaves for a corporation structure. */
    private function createDeletedCorporationStructure(User $owner, int $locationId): string
    {
        $structure = (new IndustryStructureConfig())
            ->setUser($owner)
            ->setName('Corp Sotiyo')
            ->setLocationId($locationId)
            ->setCorporationId(self::CORPORATION_ID)
            ->setIsCorporationStructure(true)
            ->setSecurityType('nullsec')
            ->setStructureType('sotiyo')
            ->setRigs(['Standup XL-Set Equipment and Consumable Manufacturing Efficiency II'])
            ->hideForUser($this->userIdOf($owner))
            ->setIsDeleted(true);
        $this->em->persist($structure);
        $this->em->flush();

        return $structure->getId()->toRfc4122();
    }

    /** @return list<IndustryStructureConfig> every row, soft-deleted included */
    private function structuresOf(User $user, int $locationId): array
    {
        return array_values($this->repository()->findBy(['user' => $user, 'locationId' => $locationId]));
    }

    private function createCorporationMember(string $name): User
    {
        $character = $this->createCharacter(name: $name); // corporation 98_000_001
        $user = $character->getUser();
        \assert($user instanceof User);
        $user->setMainCharacter($character);

        return $user;
    }

    private function createCachedCorporationStructure(int $locationId): void
    {
        $cached = (new CachedStructure())
            ->setStructureId($locationId)
            ->setName('Corp Sotiyo')
            ->setOwnerCorporationId(self::CORPORATION_ID)
            ->setResolvedAt(new \DateTimeImmutable('2026-10-01'));
        $this->em->persist($cached);
    }

    private function userIdOf(User $user): string
    {
        $id = $user->getId();
        \assert($id instanceof Uuid);

        return $id->toRfc4122();
    }

    private function repository(): IndustryStructureConfigRepository
    {
        return self::getContainer()->get(IndustryStructureConfigRepository::class);
    }
}
