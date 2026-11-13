<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-880')]
final class UserRepositoryMarketStructureIdsTest extends IntegrationTestCase
{
    private const int CJ6MT_ID = 1_049_588_174_021;
    private const int HWWF_ID = 1_035_466_617_946;
    private const int FORTIZAR_ID = 1_028_858_195_912;
    private const int KEEPSTAR_ID = 1_022_734_985_679;

    private UserRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(UserRepository::class);
    }

    public function testUnionOfPreferredStructuresThenListEntriesWithoutDuplicate(): void
    {
        $this->createUser()->setMarketStructures([
            ['id' => self::KEEPSTAR_ID, 'name' => 'Keepstar hub'],
            ['id' => self::CJ6MT_ID, 'name' => 'C-J6MT'],
            ['id' => self::FORTIZAR_ID, 'name' => 'Fortizar hub'],
        ]);
        $this->createUser()->setPreferredMarketStructureId(self::CJ6MT_ID)->setPreferredMarketStructureName('C-J6MT');
        $this->createUser();
        $this->flushAndClear();

        self::assertSame([self::CJ6MT_ID, self::KEEPSTAR_ID, self::FORTIZAR_ID], $this->repository->findDistinctMarketStructureIds());
    }

    public function testListEntriesOfAUserWithoutPreferredStructureAreIncluded(): void
    {
        $this->createUser()->setMarketStructures([
            ['id' => self::CJ6MT_ID, 'name' => 'C-J6MT'],
            ['id' => self::HWWF_ID, 'name' => '4-HWWF'],
        ]);
        $this->flushAndClear();

        self::assertSame([self::CJ6MT_ID, self::HWWF_ID], $this->repository->findDistinctMarketStructureIds());
    }

    public function testFirstListEntryEqualToThePreferredStructureIsReturnedOnce(): void
    {
        $this->createUser()
            ->setPreferredMarketStructureId(self::HWWF_ID)
            ->setPreferredMarketStructureName('4-HWWF')
            ->setMarketStructures([['id' => self::HWWF_ID, 'name' => '4-HWWF']]);
        $this->flushAndClear();

        self::assertSame([self::HWWF_ID], $this->repository->findDistinctMarketStructureIds());
    }

    public function testStructureListedByTwoUsersIsReturnedOnce(): void
    {
        $this->createUser()->setMarketStructures([['id' => self::FORTIZAR_ID, 'name' => 'Fortizar hub']]);
        $this->createUser()->setMarketStructures([['id' => self::FORTIZAR_ID, 'name' => 'Fortizar hub']]);
        $this->flushAndClear();

        self::assertSame([self::FORTIZAR_ID], $this->repository->findDistinctMarketStructureIds());
    }

    public function testNoStructureWhenNoUserHasAPreferredStructureNorAList(): void
    {
        $this->createUser();
        $this->flushAndClear();

        self::assertSame([], $this->repository->findDistinctMarketStructureIds());
    }
}
