<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustrySale;
use App\Enum\GroupProjectStatus;
use App\Repository\CachedWalletTransactionRepository;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\RecordsSqlQueries;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-241')]
final class CachedWalletTransactionRepositoryTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    private const int SABRE_TYPE_ID = 22456;
    private const int ISHTAR_TYPE_ID = 12005;
    private const string SABRE_PROJECT_CREATED_AT = '2026-10-20T10:00:00+00:00';

    private CachedWalletTransactionRepository $repository;
    private Character $oMain;
    private Character $oAlt;
    private Character $aMain;
    private GroupIndustryProject $sabreProject;
    private GroupIndustrySale $cutlassSaleLinkedToT6;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(CachedWalletTransactionRepository::class);

        $owner = $this->createUser();
        $this->oMain = $this->createCharacter($owner, 'O-Main')->setEveCharacterId(2120000001);
        $this->oAlt = $this->createCharacter($owner, 'O-Alt')->setEveCharacterId(2120000002);
        $owner->setMainCharacter($this->oMain);
        $admin = $this->createUser();
        $this->aMain = $this->createCharacter($admin, 'A-Main')->setEveCharacterId(2120000011);
        $admin->setMainCharacter($this->aMain);

        $this->sabreProject = (new GroupIndustryProject())->setOwner($owner)->setName('Sabre')->setStatus(GroupProjectStatus::Selling);
        (new \ReflectionProperty(GroupIndustryProject::class, 'createdAt'))->setValue($this->sabreProject, new \DateTimeImmutable(self::SABRE_PROJECT_CREATED_AT));
        $this->em->persist($this->sabreProject);
        $cutlassProject = (new GroupIndustryProject())->setOwner($owner)->setName('Cutlass')->setStatus(GroupProjectStatus::Selling);
        $this->em->persist($cutlassProject);

        $this->transaction(6_100_000_001, $this->oMain, false, self::SABRE_TYPE_ID, 3, 61_250_000.0, '2026-10-24T21:05:00+00:00');
        $this->transaction(6_100_000_002, $this->oMain, true, self::SABRE_TYPE_ID, 5, 58_000_000.0, '2026-10-22T12:00:00+00:00');
        $this->transaction(6_100_000_003, $this->oAlt, false, self::ISHTAR_TYPE_ID, 1, 310_000_000.0, '2026-10-23T15:30:00+00:00');
        $this->transaction(6_100_000_004, $this->oMain, false, self::SABRE_TYPE_ID, 1, 60_000_000.0, '2026-10-19T09:00:00+00:00');
        $this->transaction(6_100_000_005, $this->aMain, false, self::SABRE_TYPE_ID, 2, 61_000_000.0, '2026-10-25T18:00:00+00:00');
        $t6 = $this->transaction(6_100_000_006, $this->oAlt, false, self::SABRE_TYPE_ID, 2, 60_500_000.0, '2026-10-25T20:00:00+00:00');
        $this->transaction(6_100_000_007, $this->oAlt, false, self::SABRE_TYPE_ID, 12, 60_000_000.0, '2026-10-26T11:45:00+00:00');
        $this->cutlassSaleLinkedToT6 = $this->addSale($cutlassProject, 2, 60_500_000.0)->setWalletTransaction($t6);
        $this->em->flush();
    }

    public function testOwnerCharactersUnlinkedSalesOfTheProjectTypeSinceItsCreationAreFoundNewestFirst(): void
    {
        self::assertSame([6_100_000_007, 6_100_000_001], $this->unlinkedSaleTransactionIdsOf([$this->oMain, $this->oAlt], [self::SABRE_TYPE_ID]));
    }

    public function testAdminFindsOnlyTheSaleOfHisCharacter(): void
    {
        self::assertSame([6_100_000_005], $this->unlinkedSaleTransactionIdsOf([$this->aMain], [self::SABRE_TYPE_ID]));
    }

    public function testSalesOfEveryRequestedTypeAreFound(): void
    {
        self::assertSame(
            [6_100_000_007, 6_100_000_001, 6_100_000_003],
            $this->unlinkedSaleTransactionIdsOf([$this->oMain, $this->oAlt], [self::SABRE_TYPE_ID, self::ISHTAR_TYPE_ID]),
        );
    }

    public function testSaleAtTheVeryCreationOfTheProjectIsFoundAndTheOneASecondEarlierIsNot(): void
    {
        $this->transaction(6_100_000_008, $this->oMain, false, self::SABRE_TYPE_ID, 1, 60_250_000.0, self::SABRE_PROJECT_CREATED_AT);
        $this->transaction(6_100_000_009, $this->oMain, false, self::SABRE_TYPE_ID, 1, 60_750_000.0, '2026-10-20T09:59:59+00:00');
        $this->em->flush();

        self::assertSame([6_100_000_007, 6_100_000_001, 6_100_000_008], $this->unlinkedSaleTransactionIdsOf([$this->oMain, $this->oAlt], [self::SABRE_TYPE_ID]));
    }

    public function testSaleLinkedToASaleOfTheProjectIsNoLongerFound(): void
    {
        $t1 = $this->em->getRepository(CachedWalletTransaction::class)->findOneBy(['transactionId' => 6_100_000_001]);
        \assert($t1 instanceof CachedWalletTransaction);
        $this->addSale($this->sabreProject, 3, 61_250_000.0)->setWalletTransaction($t1);
        $this->em->flush();

        self::assertSame([6_100_000_007], $this->unlinkedSaleTransactionIdsOf([$this->oMain, $this->oAlt], [self::SABRE_TYPE_ID]));
    }

    public function testSaleIsFoundAgainOnceTheSaleOfAnotherProjectItWasLinkedToIsDeleted(): void
    {
        $this->em->remove($this->cutlassSaleLinkedToT6);
        $this->em->flush();

        self::assertSame(
            [6_100_000_007, 6_100_000_006, 6_100_000_001],
            $this->unlinkedSaleTransactionIdsOf([$this->oMain, $this->oAlt], [self::SABRE_TYPE_ID]),
        );
    }

    public function testUnlinkedSalesAreReadInASingleQuery(): void
    {
        $this->em->clear();
        $characters = [$this->characterNamed('O-Main'), $this->characterNamed('O-Alt')];

        $queries = $this->sqlExecutedDuring(fn () => $this->repository->findUnlinkedSaleTransactions(
            $characters,
            [self::SABRE_TYPE_ID],
            new \DateTimeImmutable(self::SABRE_PROJECT_CREATED_AT),
        ));

        self::assertCount(1, $queries, implode("\n", $queries));
    }

    public function testUserWithoutCharacterOrProjectWithoutItemFindsNoSale(): void
    {
        self::assertSame(
            [[], []],
            [
                $this->unlinkedSaleTransactionIdsOf([], [self::SABRE_TYPE_ID]),
                $this->unlinkedSaleTransactionIdsOf([$this->oMain, $this->oAlt], []),
            ],
        );
    }

    private function unlinkedSaleTransactionIdsOf(array $characters, array $typeIds): array
    {
        return array_map(
            static fn (CachedWalletTransaction $transaction): int => $transaction->getTransactionId(),
            $this->repository->findUnlinkedSaleTransactions($characters, $typeIds, new \DateTimeImmutable(self::SABRE_PROJECT_CREATED_AT)),
        );
    }

    private function characterNamed(string $name): Character
    {
        $character = $this->em->getRepository(Character::class)->findOneBy(['name' => $name]);
        \assert($character instanceof Character);

        return $character;
    }

    private function transaction(int $transactionId, Character $character, bool $isBuy, int $typeId, int $quantity, float $unitPrice, string $date): CachedWalletTransaction
    {
        $transaction = (new CachedWalletTransaction())
            ->setCharacter($character)
            ->setTransactionId($transactionId)
            ->setIsBuy($isBuy)
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setDate(new \DateTimeImmutable($date))
            ->setLocationId(60003760)
            ->setClientId(2_119_000_000 + $transactionId % 1000);
        $this->em->persist($transaction);

        return $transaction;
    }

    private function addSale(GroupIndustryProject $project, int $quantity, float $unitPrice): GroupIndustrySale
    {
        $sale = (new GroupIndustrySale())
            ->setTypeId(self::SABRE_TYPE_ID)
            ->setTypeName('Sabre')
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->recalculateTotalPrice()
            ->setSoldAt(new \DateTimeImmutable('2026-10-25T20:00:00+00:00'))
            ->setRecordedBy($project->getOwner());
        $project->addSale($sale);

        return $sale;
    }
}
