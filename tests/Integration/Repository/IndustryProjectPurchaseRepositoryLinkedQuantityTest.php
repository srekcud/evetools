<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectPurchase;
use App\Repository\IndustryProjectPurchaseRepository;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\RecordsSqlQueries;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-1302')]
final class IndustryProjectPurchaseRepositoryLinkedQuantityTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    private const int TRITANIUM_TYPE_ID = 34;
    private const int RAM_TYPE_ID = 11_478;
    private const int SABRE_TYPE_ID = 22_456;

    private IndustryProjectPurchaseRepository $repository;
    private Character $character;
    private CachedWalletTransaction $tritaniumBuySplitInTwo;
    private CachedWalletTransaction $ramBuyLinkedOnce;
    private CachedWalletTransaction $sabreBuyNeverLinked;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(IndustryProjectPurchaseRepository::class);
        $this->character = $this->createCharacter(null, 'Aria Solberg');

        $this->tritaniumBuySplitInTwo = $this->transaction(6_302_000_201, self::TRITANIUM_TYPE_ID, 100, 4.1);
        $this->ramBuyLinkedOnce = $this->transaction(6_302_000_202, self::RAM_TYPE_ID, 80, 2_990.75);
        $this->sabreBuyNeverLinked = $this->transaction(6_302_000_203, self::SABRE_TYPE_ID, 5, 61_250_000.0);

        $firstProjectLine = $this->lineOfANewProject('Ishtar batch', self::TRITANIUM_TYPE_ID);
        $secondProjectLine = $this->lineOfANewProject('Hecate batch', self::TRITANIUM_TYPE_ID);
        $ramLine = $this->lineOfANewProject('Sabre batch', self::RAM_TYPE_ID);
        $this->linkShare($firstProjectLine, $this->tritaniumBuySplitInTwo, 40);
        $this->linkShare($secondProjectLine, $this->tritaniumBuySplitInTwo, 60);
        $this->linkShare($ramLine, $this->ramBuyLinkedOnce, 25);
        $this->em->flush();
        $this->em->clear();
    }

    public function testQuantityOfATransactionSplitInTwoSharesIsTheSumOfTheSharesOfBothProjects(): void
    {
        self::assertSame(100, $this->repository->linkedQuantityOf($this->reload($this->tritaniumBuySplitInTwo)));
    }

    public function testQuantityOfATransactionLinkedOnceIsTheQuantityOfItsPurchase(): void
    {
        self::assertSame(25, $this->repository->linkedQuantityOf($this->reload($this->ramBuyLinkedOnce)));
    }

    public function testQuantityOfATransactionWithoutAnyPurchaseIsZero(): void
    {
        self::assertSame(0, $this->repository->linkedQuantityOf($this->reload($this->sabreBuyNeverLinked)));
    }

    public function testQuantitiesOfSeveralTransactionsAreKeyedByUuidWithZeroForTheOnesWithoutPurchase(): void
    {
        $transactions = [$this->reload($this->tritaniumBuySplitInTwo), $this->reload($this->ramBuyLinkedOnce), $this->reload($this->sabreBuyNeverLinked)];

        $quantities = $this->repository->linkedQuantitiesOf($transactions);

        $expected = [
            $this->tritaniumBuySplitInTwo->getId()->toRfc4122() => 100,
            $this->ramBuyLinkedOnce->getId()->toRfc4122() => 25,
            $this->sabreBuyNeverLinked->getId()->toRfc4122() => 0,
        ];
        ksort($expected);
        ksort($quantities);
        self::assertSame($expected, $quantities);
    }

    public function testQuantitiesOfNoTransactionAreEmpty(): void
    {
        self::assertSame([], $this->repository->linkedQuantitiesOf([]));
    }

    public function testQuantitiesOfSeveralTransactionsAreReadInASingleQuery(): void
    {
        $transactions = [$this->reload($this->tritaniumBuySplitInTwo), $this->reload($this->ramBuyLinkedOnce), $this->reload($this->sabreBuyNeverLinked)];

        $queries = $this->sqlExecutedDuring(fn () => $this->repository->linkedQuantitiesOf($transactions));

        self::assertCount(1, $queries, implode("\n", $queries));
    }

    private function reload(CachedWalletTransaction $transaction): CachedWalletTransaction
    {
        $reloaded = $this->em->find(CachedWalletTransaction::class, $transaction->getId());
        \assert($reloaded instanceof CachedWalletTransaction);

        return $reloaded;
    }

    private function transaction(int $transactionId, int $typeId, int $quantity, float $unitPrice): CachedWalletTransaction
    {
        $transaction = (new CachedWalletTransaction())
            ->setCharacter($this->character)
            ->setTransactionId($transactionId)
            ->setIsBuy(true)
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setDate(new \DateTimeImmutable('2026-11-02T12:00:00+00:00'))
            ->setLocationId(60_003_760);
        $this->em->persist($transaction);

        return $transaction;
    }

    private function lineOfANewProject(string $name, int $typeId): IndustryProjectBomLine
    {
        $project = (new IndustryProject())->setOwner($this->createUser())->setName($name);
        $this->em->persist($project);
        $line = (new IndustryProjectBomLine())->setTypeId($typeId)->setTypeName('Type '.$typeId)->setRequiredQuantity(500)->setIsJob(false);
        $project->addBomItem($line);

        return $line;
    }

    private function linkShare(IndustryProjectBomLine $line, CachedWalletTransaction $transaction, int $quantity): void
    {
        $purchase = (new IndustryProjectPurchase())
            ->setTransaction($transaction)
            ->setTypeId($transaction->getTypeId())
            ->setQuantity($quantity)
            ->setUnitPrice($transaction->getUnitPrice())
            ->setTotalPrice($transaction->getUnitPrice() * $quantity)
            ->setSource('esi_wallet');
        $line->addPurchase($purchase);
        $this->em->persist($purchase);
    }
}
