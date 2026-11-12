<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectPurchase;
use App\Entity\User;
use App\Repository\IndustryProjectPurchaseRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\AutomaticPurchaseLinker;
use App\Service\Industry\Project\DerivedContributionService;
use App\Tests\Unit\State\Processor\Industry\TracksJobsAndPurchasesOnIshtarBom;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(AutomaticPurchaseLinker::class)]
#[Group('issue-750')]
#[AllowMockObjectsWithoutExpectations]
final class AutomaticPurchaseLinkerTest extends TestCase
{
    use TracksJobsAndPurchasesOnIshtarBom;

    private const int JITA_4_4_STATION_ID = 60_003_760;
    private const int RAM_BUY_QUANTITY = 50;
    private const float RAM_UNIT_PRICE = 3_040.5;

    private array $openProjects = [];

    private array $persistedEntities = [];

    private int $flushCount = 0;

    private array $storedPurchases = [];

    protected function setUp(): void
    {
        $this->setUpIshtarProject();
        $this->openProjects = [$this->ishtarProject];
    }

    public function testBuyBelowTheRemainingOfTheOnlyMatchingLineIsLinkedAtThePaidPrice(): void
    {
        $buy = $this->buy($this->ownerAltCharacter, 6_750_000_001, self::RAM_TYPE_ID, self::RAM_BUY_QUANTITY, self::RAM_UNIT_PRICE);

        $this->linkNew($this->ownerAltCharacter, [$buy]);

        self::assertCount(1, $this->persistedEntities);
        $purchase = $this->persistedEntities[0];
        self::assertInstanceOf(IndustryProjectPurchase::class, $purchase);
        self::assertSame(
            [self::RAM_TYPE_ID, self::RAM_BUY_QUANTITY, self::RAM_UNIT_PRICE, 152_025.0, 'esi_wallet', $buy, $this->ramLine],
            [$purchase->getTypeId(), $purchase->getQuantity(), $purchase->getUnitPrice(), $purchase->getTotalPrice(), $purchase->getSource(), $purchase->getTransaction(), $purchase->getBomLine()],
        );
        self::assertSame([[$this->ramLine->getId()->toRfc4122(), self::RAM_BUY_QUANTITY]], $this->refreshedLines);
        self::assertSame(1, $this->flushCount);
    }

    public function testBuyEqualToTheRemainingOfTheLineIsLinked(): void
    {
        $buy = $this->buy($this->ownerAltCharacter, 6_750_000_002, self::RAM_TYPE_ID, self::RAM_LINE_QUANTITY, self::RAM_UNIT_PRICE);

        $this->linkNew($this->ownerAltCharacter, [$buy]);

        self::assertSame([self::RAM_LINE_QUANTITY], [$this->ramLine->getPurchasedQuantity()]);
    }

    public function testBuyOfAMaterialLineIsLinkedLikeAComponentLine(): void
    {
        $buy = $this->buy($this->ownerMainCharacter, 6_750_000_003, self::TRITANIUM_TYPE_ID, 2_500, 4.2);

        $this->linkNew($this->ownerMainCharacter, [$buy]);

        self::assertSame([2_500, 10_500.0], [$this->tritaniumLine->getPurchasedQuantity(), $this->persistedEntities[0]->getTotalPrice()]);
    }

    public function testBuyAboveTheRemainingOfTheLineIsLeftToTheSuggestionsWithoutAPartialLink(): void
    {
        $buy = $this->buy($this->ownerAltCharacter, 6_750_000_004, self::RAM_TYPE_ID, self::RAM_LINE_QUANTITY + 1, self::RAM_UNIT_PRICE);

        $this->linkNew($this->ownerAltCharacter, [$buy]);

        self::assertSame([[], [], 0], [$this->persistedEntities, $this->refreshedLines, $this->flushCount]);
    }

    public function testBuyMatchingALineInTwoOpenProjectsIsLeftToTheSuggestions(): void
    {
        $this->openProjects = [$this->ishtarProject, $this->hecateProject];
        $buy = $this->buy($this->ownerMainCharacter, 6_750_000_005, self::TRITANIUM_TYPE_ID, 1_000, 4.4);

        $this->linkNew($this->ownerMainCharacter, [$buy]);

        self::assertSame([[], 0], [$this->persistedEntities, $this->flushCount]);
    }

    public function testBuyMatchingTwoLinesOfTheSameProjectIsLeftToTheSuggestions(): void
    {
        $this->materialLineOf($this->ishtarProject, self::RAM_TYPE_ID, 100);
        $buy = $this->buy($this->ownerAltCharacter, 6_750_000_006, self::RAM_TYPE_ID, 20, self::RAM_UNIT_PRICE);

        $this->linkNew($this->ownerAltCharacter, [$buy]);

        self::assertSame([[], 0], [$this->persistedEntities, $this->flushCount]);
    }

    public function testLineWithNothingLeftToBuyDoesNotMakeTheMatchAmbiguous(): void
    {
        $this->openProjects = [$this->ishtarProject, $this->hecateProject];
        $this->hecateTritaniumLine->setFulfilledQuantity($this->hecateTritaniumLine->getRequiredQuantity());
        $buy = $this->buy($this->ownerMainCharacter, 6_750_000_007, self::TRITANIUM_TYPE_ID, 1_000, 4.4);

        $this->linkNew($this->ownerMainCharacter, [$buy]);

        self::assertSame([$this->tritaniumLine], [$this->persistedEntities[0]->getBomLine()]);
    }

    public function testBuyMadeBeforeTheCreationOfTheProjectIsNotLinked(): void
    {
        $buy = $this->buy($this->ownerAltCharacter, 6_750_000_008, self::RAM_TYPE_ID, 20, self::RAM_UNIT_PRICE, '2026-10-31T23:59:59+00:00');

        $this->linkNew($this->ownerAltCharacter, [$buy]);

        self::assertSame([[], 0], [$this->persistedEntities, $this->flushCount]);
    }

    public function testTransactionAlreadyLinkedIsNeverLinkedASecondTime(): void
    {
        $buy = $this->buy($this->ownerAltCharacter, 6_750_000_009, self::RAM_TYPE_ID, 20, self::RAM_UNIT_PRICE);
        $this->storedPurchases[] = (new IndustryProjectPurchase())->setTransaction($buy)->setTypeId(self::RAM_TYPE_ID)->setQuantity(20);

        $this->linkNew($this->ownerAltCharacter, [$buy]);

        self::assertSame([[], 0], [$this->persistedEntities, $this->flushCount]);
    }

    public function testSaleIsNeverLinked(): void
    {
        $sale = $this->buy($this->ownerAltCharacter, 6_750_000_010, self::RAM_TYPE_ID, 20, self::RAM_UNIT_PRICE)->setIsBuy(false);

        $this->linkNew($this->ownerAltCharacter, [$sale]);

        self::assertSame([[], 0], [$this->persistedEntities, $this->flushCount]);
    }

    public function testBuyOfTheProducedItemOfAFinalJobLineIsNotLinked(): void
    {
        $buy = $this->buy($this->ownerAltCharacter, 6_750_000_011, self::ISHTAR_TYPE_ID, 1, 250_000_000.0);

        $this->linkNew($this->ownerAltCharacter, [$buy]);

        self::assertSame([[], 0], [$this->persistedEntities, $this->flushCount]);
    }

    public function testSecondBuyOfABatchAboveWhatTheFirstLeftIsNotLinked(): void
    {
        $firstBuy = $this->buy($this->ownerAltCharacter, 6_750_000_012, self::RAM_TYPE_ID, 300, self::RAM_UNIT_PRICE, '2026-11-02T12:00:00+00:00');
        $secondBuy = $this->buy($this->ownerAltCharacter, 6_750_000_013, self::RAM_TYPE_ID, 150, self::RAM_UNIT_PRICE, '2026-11-02T13:00:00+00:00');

        $this->linkNew($this->ownerAltCharacter, [$secondBuy, $firstBuy]);

        self::assertSame([[$firstBuy], 300], [array_map(static fn (IndustryProjectPurchase $purchase): ?CachedWalletTransaction => $purchase->getTransaction(), $this->persistedEntities), $this->ramLine->getPurchasedQuantity()]);
    }

    public function testCharacterWithoutUserLinksNothing(): void
    {
        $orphanCharacter = (new Character())->setName('Orphan');
        $buy = $this->buy($orphanCharacter, 6_750_000_014, self::RAM_TYPE_ID, 20, self::RAM_UNIT_PRICE);

        $this->linkNew($orphanCharacter, [$buy]);

        self::assertSame([[], 0], [$this->persistedEntities, $this->flushCount]);
    }

    private function linkNew(Character $character, array $transactions): void
    {
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('findOpenOwnedBy')->willReturnCallback(
            fn (User $user): array => array_values(array_filter(
                $this->openProjects,
                static fn (IndustryProject $project): bool => $project->getOwner() === $user,
            )),
        );
        $purchaseRepository = $this->createStub(IndustryProjectPurchaseRepository::class);
        $purchaseRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?IndustryProjectPurchase => array_find(
                $this->storedPurchases,
                static fn (IndustryProjectPurchase $purchase): bool => $purchase->getTransaction() === $criteria['transaction'],
            ),
        );
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persistedEntities[] = $entity;
            if ($entity instanceof IndustryProjectPurchase) {
                $this->storedPurchases[] = $entity;
            }
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            $this->flushCount++;
        });
        $derivedContributionService = $this->createStub(DerivedContributionService::class);
        $derivedContributionService->method('refreshLine')->willReturnCallback(function (IndustryProjectBomLine $line): void {
            $line->setFulfilledQuantity($line->getPurchasedQuantity());
            $this->refreshedLines[] = [$line->getId()->toRfc4122(), $line->getPurchasedQuantity()];
        });

        (new AutomaticPurchaseLinker($projectRepository, $purchaseRepository, $entityManager, $derivedContributionService))
            ->linkNewPurchases($character, $transactions);
    }

    private function buy(Character $character, int $transactionId, int $typeId, int $quantity, float $unitPrice, string $date = '2026-11-02T12:00:00+00:00'): CachedWalletTransaction
    {
        return (new CachedWalletTransaction())
            ->setCharacter($character)
            ->setTransactionId($transactionId)
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setIsBuy(true)
            ->setLocationId(self::JITA_4_4_STATION_ID)
            ->setDate(new \DateTimeImmutable($date));
    }

    private function materialLineOf(IndustryProject $project, int $typeId, int $quantity): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName('Material')
            ->setRequiredQuantity($quantity)
            ->setIsJob(false);
        $project->addBomItem($line);

        return $line;
    }
}
