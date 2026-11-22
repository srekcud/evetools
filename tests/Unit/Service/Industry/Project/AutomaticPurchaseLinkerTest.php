<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectPurchase;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Repository\IndustryProjectPurchaseRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\AutomaticPurchaseLinker;
use App\Service\Industry\Project\ContainerDeliveryConfirmer;
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
    private const float TRITANIUM_SPLIT_UNIT_PRICE = 4.25;

    private array $openProjects = [];

    private array $persistedEntities = [];

    private int $flushCount = 0;

    private array $storedPurchases = [];

    private array $confirmedProjects = [];

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

    #[Group('issue-981')]
    public function testAutomaticallyLinkedPurchaseHasTheOwnerParticipantOfTheProjectAsItsBuyer(): void
    {
        $buy = $this->buy($this->ownerAltCharacter, 6_981_000_001, self::RAM_TYPE_ID, self::RAM_BUY_QUANTITY, self::RAM_UNIT_PRICE);

        $this->linkNew($this->ownerAltCharacter, [$buy]);

        $ownerParticipant = array_find(
            $this->ishtarProject->getMembers()->toArray(),
            fn (IndustryProjectParticipant $participant): bool => $participant->getUser() === $this->owner,
        );
        self::assertNotNull($ownerParticipant);
        self::assertSame($ownerParticipant, $this->persistedEntities[0]->getBuyer());
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

    #[Group('issue-1014')]
    public function testBuyOfTheWholeLineQuantityIsLinkedWhenStockAlreadyCoversPartOfTheLine(): void
    {
        $this->ramLine->setFulfilledQuantity(120);
        $buy = $this->buy($this->ownerAltCharacter, 6_014_000_001, self::RAM_TYPE_ID, self::RAM_LINE_QUANTITY, self::RAM_UNIT_PRICE);

        $this->linkNew($this->ownerAltCharacter, [$buy]);

        self::assertSame([[$buy], self::RAM_LINE_QUANTITY], [array_map(static fn (IndustryProjectPurchase $purchase): ?CachedWalletTransaction => $purchase->getTransaction(), $this->persistedEntities), $this->ramLine->getPurchasedQuantity()]);
    }

    #[Group('issue-1014')]
    public function testBuyAboveTheWholeLineQuantityIsNotLinkedEvenWhenStockCoversPartOfTheLine(): void
    {
        $this->ramLine->setFulfilledQuantity(120);
        $buy = $this->buy($this->ownerAltCharacter, 6_014_000_002, self::RAM_TYPE_ID, self::RAM_LINE_QUANTITY + 1, self::RAM_UNIT_PRICE);

        $this->linkNew($this->ownerAltCharacter, [$buy]);

        self::assertSame([[], 0], [$this->persistedEntities, $this->flushCount]);
    }

    #[Group('issue-1014')]
    public function testBuyAboveWhatEarlierPurchasesLeftIsNotLinkedWhenStockCoversPartOfTheLine(): void
    {
        $this->ramLine->setFulfilledQuantity(120);
        $firstBuy = $this->buy($this->ownerAltCharacter, 6_014_000_003, self::RAM_TYPE_ID, 300, self::RAM_UNIT_PRICE, '2026-11-02T12:00:00+00:00');
        $secondBuy = $this->buy($this->ownerAltCharacter, 6_014_000_004, self::RAM_TYPE_ID, 150, self::RAM_UNIT_PRICE, '2026-11-02T13:00:00+00:00');

        $this->linkNew($this->ownerAltCharacter, [$firstBuy, $secondBuy]);

        self::assertSame([[$firstBuy], 300], [array_map(static fn (IndustryProjectPurchase $purchase): ?CachedWalletTransaction => $purchase->getTransaction(), $this->persistedEntities), $this->ramLine->getPurchasedQuantity()]);
    }

    #[Group('issue-1100')]
    public function testBuyMatchingALineInTwoOpenProjectsIsLinkedToTheOldestProject(): void
    {
        $this->createdAt($this->hecateProject, '2026-10-25T10:00:00+00:00');
        $this->openProjects = [$this->ishtarProject, $this->hecateProject];
        $buy = $this->buy($this->ownerMainCharacter, 6_750_000_005, self::TRITANIUM_TYPE_ID, 1_000, 4.4);

        $this->linkNew($this->ownerMainCharacter, [$buy]);

        self::assertSame([[$this->hecateTritaniumLine], 1_000, 0], [array_map(static fn (IndustryProjectPurchase $purchase): ?IndustryProjectBomLine => $purchase->getBomLine(), $this->persistedEntities), $this->hecateTritaniumLine->getPurchasedQuantity(), $this->tritaniumLine->getPurchasedQuantity()]);
    }

    #[Group('issue-1100')]
    public function testOldestProjectWhoseRemainingToBuyDoesNotCoverTheBuyIsSkipped(): void
    {
        $this->createdAt($this->hecateProject, '2026-10-25T10:00:00+00:00');
        $this->hecateTritaniumLine->setRequiredQuantity(500);
        $this->openProjects = [$this->ishtarProject, $this->hecateProject];
        $buy = $this->buy($this->ownerMainCharacter, 6_100_000_001, self::TRITANIUM_TYPE_ID, 1_000, 4.4);

        $this->linkNew($this->ownerMainCharacter, [$buy]);

        self::assertSame([[$this->tritaniumLine], 1_000, 0], [array_map(static fn (IndustryProjectPurchase $purchase): ?IndustryProjectBomLine => $purchase->getBomLine(), $this->persistedEntities), $this->tritaniumLine->getPurchasedQuantity(), $this->hecateTritaniumLine->getPurchasedQuantity()]);
    }

    #[Group('issue-1100')]
    public function testBuyIsLeftToTheSuggestionsWhenNoOpenProjectCoversIt(): void
    {
        $this->createdAt($this->hecateProject, '2026-10-25T10:00:00+00:00');
        $this->hecateTritaniumLine->setRequiredQuantity(500);
        $this->tritaniumLine->setRequiredQuantity(300);
        $this->openProjects = [$this->ishtarProject, $this->hecateProject];
        $buy = $this->buy($this->ownerMainCharacter, 6_100_000_002, self::TRITANIUM_TYPE_ID, 1_000, 4.4);

        $this->linkNew($this->ownerMainCharacter, [$buy]);

        self::assertSame([[], 0], [$this->persistedEntities, $this->flushCount]);
    }

    #[Group('issue-1100')]
    public function testSecondBuyOfABatchMovesToTheNextProjectOnceTheOldestOneIsFull(): void
    {
        $this->createdAt($this->hecateProject, '2026-10-25T10:00:00+00:00');
        $this->hecateTritaniumLine->setRequiredQuantity(1_000);
        $this->openProjects = [$this->ishtarProject, $this->hecateProject];
        $firstBuy = $this->buy($this->ownerMainCharacter, 6_100_000_003, self::TRITANIUM_TYPE_ID, 600, 4.4, '2026-11-02T12:00:00+00:00');
        $secondBuy = $this->buy($this->ownerMainCharacter, 6_100_000_004, self::TRITANIUM_TYPE_ID, 600, 4.4, '2026-11-02T13:00:00+00:00');

        $this->linkNew($this->ownerMainCharacter, [$secondBuy, $firstBuy]);

        self::assertSame([[$this->hecateTritaniumLine, $this->tritaniumLine], [$firstBuy, $secondBuy]], [
            array_map(static fn (IndustryProjectPurchase $purchase): ?IndustryProjectBomLine => $purchase->getBomLine(), $this->persistedEntities),
            array_map(static fn (IndustryProjectPurchase $purchase): ?CachedWalletTransaction => $purchase->getTransaction(), $this->persistedEntities),
        ]);
    }

    #[Group('issue-1100')]
    public function testOldestProjectWithTwoLinesOfTheTypeLeavesTheBuyToTheSuggestions(): void
    {
        $this->createdAt($this->hecateProject, '2026-10-25T10:00:00+00:00');
        $this->materialLineOf($this->hecateProject, self::TRITANIUM_TYPE_ID, 5_000);
        $this->openProjects = [$this->ishtarProject, $this->hecateProject];
        $buy = $this->buy($this->ownerMainCharacter, 6_100_000_005, self::TRITANIUM_TYPE_ID, 1_000, 4.4);

        $this->linkNew($this->ownerMainCharacter, [$buy]);

        self::assertSame([[], 0], [$this->persistedEntities, $this->flushCount]);
    }

    #[Group('issue-1100')]
    public function testProjectCreatedAfterTheBuyIsNeverChosenEvenWhenItIsTheOldestInTheList(): void
    {
        $this->createdAt($this->hecateProject, '2026-11-03T10:00:00+00:00');
        $this->openProjects = [$this->hecateProject, $this->ishtarProject];
        $buy = $this->buy($this->ownerMainCharacter, 6_100_000_006, self::TRITANIUM_TYPE_ID, 1_000, 4.4);

        $this->linkNew($this->ownerMainCharacter, [$buy]);

        self::assertSame([[$this->tritaniumLine]], [array_map(static fn (IndustryProjectPurchase $purchase): ?IndustryProjectBomLine => $purchase->getBomLine(), $this->persistedEntities)]);
    }

    #[Group('issue-1100')]
    public function testProjectsCreatedAtTheSameInstantKeepTheOrderOfTheRepository(): void
    {
        $this->openProjects = [$this->hecateProject, $this->ishtarProject];
        $buy = $this->buy($this->ownerMainCharacter, 6_100_000_007, self::TRITANIUM_TYPE_ID, 1_000, 4.4);

        $this->linkNew($this->ownerMainCharacter, [$buy]);

        self::assertSame([[$this->hecateTritaniumLine]], [array_map(static fn (IndustryProjectPurchase $purchase): ?IndustryProjectBomLine => $purchase->getBomLine(), $this->persistedEntities)]);
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

    #[Group('issue-953')]
    public function testProjectOfALinkedBuyIsConfirmedAgainstTheContainerOnceTheLinksAreFlushed(): void
    {
        $buy = $this->buy($this->ownerAltCharacter, 6_750_000_015, self::RAM_TYPE_ID, self::RAM_BUY_QUANTITY, self::RAM_UNIT_PRICE);

        $this->linkNew($this->ownerAltCharacter, [$buy]);

        self::assertSame([[$this->ishtarProject->getId()->toRfc4122(), 1]], $this->confirmedProjects);
    }

    #[Group('issue-953')]
    public function testProjectWithTwoLinkedBuysIsConfirmedAgainstTheContainerOnce(): void
    {
        $ramBuy = $this->buy($this->ownerAltCharacter, 6_750_000_016, self::RAM_TYPE_ID, self::RAM_BUY_QUANTITY, self::RAM_UNIT_PRICE);
        $tritaniumBuy = $this->buy($this->ownerAltCharacter, 6_750_000_017, self::TRITANIUM_TYPE_ID, 2_500, 4.2);

        $this->linkNew($this->ownerAltCharacter, [$ramBuy, $tritaniumBuy]);

        self::assertSame([[$this->ishtarProject->getId()->toRfc4122(), 1]], $this->confirmedProjects);
    }

    #[Group('issue-953')]
    public function testNothingIsConfirmedAgainstTheContainerWhenNoBuyIsLinked(): void
    {
        $buy = $this->buy($this->ownerAltCharacter, 6_750_000_018, self::RAM_TYPE_ID, self::RAM_LINE_QUANTITY + 1, self::RAM_UNIT_PRICE);

        $this->linkNew($this->ownerAltCharacter, [$buy]);

        self::assertSame([], $this->confirmedProjects);
    }

    #[Group('issue-1302')]
    public function testBuyNoProjectCoversAloneIsSplitBetweenTheOpenProjectsOldestFirst(): void
    {
        $this->openTwoProjectsLeftToBuy(30, 20);
        $buy = $this->buy($this->ownerMainCharacter, 6_302_000_001, self::TRITANIUM_TYPE_ID, 50, self::TRITANIUM_SPLIT_UNIT_PRICE);

        $this->linkNew($this->ownerMainCharacter, [$buy]);

        self::assertSame(
            [
                [$this->tritaniumLine, 30, 127.5, $buy, $this->ishtarProject->getOwnerParticipant(), 'esi_wallet'],
                [$this->hecateTritaniumLine, 20, 85.0, $buy, $this->hecateProject->getOwnerParticipant(), 'esi_wallet'],
            ],
            array_map(
                static fn (IndustryProjectPurchase $purchase): array => [$purchase->getBomLine(), $purchase->getQuantity(), $purchase->getTotalPrice(), $purchase->getTransaction(), $purchase->getBuyer(), $purchase->getSource()],
                $this->persistedEntities,
            ),
        );
        self::assertSame([[$this->tritaniumLine->getId()->toRfc4122(), 30], [$this->hecateTritaniumLine->getId()->toRfc4122(), 20]], $this->refreshedLines);
        self::assertSame(1, $this->flushCount);
    }

    #[Group('issue-1302')]
    public function testSplitProjectsAreEachConfirmedAgainstTheContainerOnceAfterTheFlush(): void
    {
        $this->openTwoProjectsLeftToBuy(30, 20);
        $buy = $this->buy($this->ownerMainCharacter, 6_302_000_002, self::TRITANIUM_TYPE_ID, 50, self::TRITANIUM_SPLIT_UNIT_PRICE);

        $this->linkNew($this->ownerMainCharacter, [$buy]);

        self::assertSame(
            [[$this->ishtarProject->getId()->toRfc4122(), 1], [$this->hecateProject->getId()->toRfc4122(), 1]],
            $this->confirmedProjects,
        );
    }

    #[Group('issue-1302')]
    public function testBuyAboveTheRemainingToBuyOfAllTheProjectsTogetherIsNotSplit(): void
    {
        $this->openTwoProjectsLeftToBuy(30, 10);
        $buy = $this->buy($this->ownerMainCharacter, 6_302_000_003, self::TRITANIUM_TYPE_ID, 50, self::TRITANIUM_SPLIT_UNIT_PRICE);

        $this->linkNew($this->ownerMainCharacter, [$buy]);

        self::assertSame([[], [], [], 0], [$this->persistedEntities, $this->refreshedLines, $this->confirmedProjects, $this->flushCount]);
    }

    #[Group('issue-1302')]
    public function testBuyIsNotSplitWhenAProjectItWouldReachHasTwoLinesOfTheType(): void
    {
        $this->openTwoProjectsLeftToBuy(30, 20);
        $this->materialLineOf($this->ishtarProject, self::TRITANIUM_TYPE_ID, 10);
        $buy = $this->buy($this->ownerMainCharacter, 6_302_000_004, self::TRITANIUM_TYPE_ID, 50, self::TRITANIUM_SPLIT_UNIT_PRICE);

        $this->linkNew($this->ownerMainCharacter, [$buy]);

        self::assertSame([[], 0], [$this->persistedEntities, $this->flushCount]);
    }

    #[Group('issue-1302')]
    public function testProjectWithNothingLeftToBuyIsSkippedWhenSplittingBetweenTheNextProjects(): void
    {
        $this->openTwoProjectsLeftToBuy(30, 20);
        $this->tritaniumLine->addPurchase((new IndustryProjectPurchase())->setTypeId(self::TRITANIUM_TYPE_ID)->setQuantity(30));
        $thirdProjectLine = $this->openTritaniumProject('Sabre batch', '2026-11-01T12:00:00+00:00', 30);
        $buy = $this->buy($this->ownerMainCharacter, 6_302_000_005, self::TRITANIUM_TYPE_ID, 50, self::TRITANIUM_SPLIT_UNIT_PRICE);

        $this->linkNew($this->ownerMainCharacter, [$buy]);

        self::assertSame(
            [[$this->hecateTritaniumLine, 20], [$thirdProjectLine, 30]],
            array_map(static fn (IndustryProjectPurchase $purchase): array => [$purchase->getBomLine(), $purchase->getQuantity()], $this->persistedEntities),
        );
    }

    #[Group('issue-1302')]
    public function testBuyCoveredByOneProjectIsStillLinkedWholeToTheOldestOneThatCoversIt(): void
    {
        $this->openTwoProjectsLeftToBuy(60, 20);
        $buy = $this->buy($this->ownerMainCharacter, 6_302_000_006, self::TRITANIUM_TYPE_ID, 50, self::TRITANIUM_SPLIT_UNIT_PRICE);

        $this->linkNew($this->ownerMainCharacter, [$buy]);

        self::assertSame([[$this->tritaniumLine, 50]], array_map(static fn (IndustryProjectPurchase $purchase): array => [$purchase->getBomLine(), $purchase->getQuantity()], $this->persistedEntities));
    }

    #[Group('issue-1302')]
    public function testTransactionAlreadySplitIsNeverCompletedNorSplitAgain(): void
    {
        $this->openTwoProjectsLeftToBuy(30, 20);
        $buy = $this->buy($this->ownerMainCharacter, 6_302_000_007, self::TRITANIUM_TYPE_ID, 50, self::TRITANIUM_SPLIT_UNIT_PRICE);
        $this->storedPurchases[] = (new IndustryProjectPurchase())->setTransaction($buy)->setTypeId(self::TRITANIUM_TYPE_ID)->setQuantity(30);
        $this->storedPurchases[] = (new IndustryProjectPurchase())->setTransaction($buy)->setTypeId(self::TRITANIUM_TYPE_ID)->setQuantity(20);

        $this->linkNew($this->ownerMainCharacter, [$buy]);

        self::assertSame([[], 0], [$this->persistedEntities, $this->flushCount]);
    }

    #[Group('issue-1302')]
    public function testBuyMadeBeforeTheCreationOfAProjectIsNotSplitOntoIt(): void
    {
        $this->openTwoProjectsLeftToBuy(30, 20);
        $this->createdAt($this->hecateProject, '2026-11-02T13:00:00+00:00');
        $buy = $this->buy($this->ownerMainCharacter, 6_302_000_008, self::TRITANIUM_TYPE_ID, 50, self::TRITANIUM_SPLIT_UNIT_PRICE);

        $this->linkNew($this->ownerMainCharacter, [$buy]);

        self::assertSame([[], 0], [$this->persistedEntities, $this->flushCount]);
    }

    #[Group('issue-1302')]
    public function testSecondBuyOfABatchAboveWhatTheSplitOfTheFirstOneLeftIsNotLinked(): void
    {
        $this->openTwoProjectsLeftToBuy(30, 25);
        $firstBuy = $this->buy($this->ownerMainCharacter, 6_302_000_009, self::TRITANIUM_TYPE_ID, 50, self::TRITANIUM_SPLIT_UNIT_PRICE, '2026-11-02T12:00:00+00:00');
        $secondBuy = $this->buy($this->ownerMainCharacter, 6_302_000_010, self::TRITANIUM_TYPE_ID, 8, self::TRITANIUM_SPLIT_UNIT_PRICE, '2026-11-02T13:00:00+00:00');

        $this->linkNew($this->ownerMainCharacter, [$secondBuy, $firstBuy]);

        self::assertSame(
            [[$firstBuy, 30], [$firstBuy, 20]],
            array_map(static fn (IndustryProjectPurchase $purchase): array => [$purchase->getTransaction(), $purchase->getQuantity()], $this->persistedEntities),
        );
    }

    #[Group('issue-1302')]
    public function testBuyEqualToTheRemainingOfALaterProjectIsLinkedWholeToItNotSplit(): void
    {
        $this->openTwoProjectsLeftToBuy(30, 50);
        $buy = $this->buy($this->ownerMainCharacter, 6_302_000_011, self::TRITANIUM_TYPE_ID, 50, self::TRITANIUM_SPLIT_UNIT_PRICE);

        $this->linkNew($this->ownerMainCharacter, [$buy]);

        self::assertSame([[$this->hecateTritaniumLine, 50]], array_map(static fn (IndustryProjectPurchase $purchase): array => [$purchase->getBomLine(), $purchase->getQuantity()], $this->persistedEntities));
    }

    #[Group('issue-1302')]
    public function testAmbiguousProjectBetweenTwoProjectsThatTogetherCoverTheBuyBlocksTheSplit(): void
    {
        $this->openTwoProjectsLeftToBuy(30, 20);
        $this->materialLineOf($this->hecateProject, self::TRITANIUM_TYPE_ID, 5);
        $this->openTritaniumProject('Sabre batch', '2026-11-01T12:00:00+00:00', 20);
        $buy = $this->buy($this->ownerMainCharacter, 6_302_000_012, self::TRITANIUM_TYPE_ID, 50, self::TRITANIUM_SPLIT_UNIT_PRICE);

        $this->linkNew($this->ownerMainCharacter, [$buy]);

        self::assertSame([[], 0], [$this->persistedEntities, $this->flushCount]);
    }

    #[Group('issue-1302')]
    public function testBuyPartlyLinkedByHandIsNeverCompletedBySync(): void
    {
        $this->openTwoProjectsLeftToBuy(30, 20);
        $buy = $this->buy($this->ownerMainCharacter, 6_302_000_013, self::TRITANIUM_TYPE_ID, 50, self::TRITANIUM_SPLIT_UNIT_PRICE);
        $this->storedPurchases[] = (new IndustryProjectPurchase())->setTransaction($buy)->setTypeId(self::TRITANIUM_TYPE_ID)->setQuantity(20);

        $this->linkNew($this->ownerMainCharacter, [$buy]);

        self::assertSame([[], 0], [$this->persistedEntities, $this->flushCount]);
    }

    #[Group('issue-1302')]
    public function testSplitUsesTheQuantityLeftToBuyOfALineAlreadyCoveredByStock(): void
    {
        $this->openTwoProjectsLeftToBuy(30, 20);
        $this->tritaniumLine->setFulfilledQuantity(10);
        $buy = $this->buy($this->ownerMainCharacter, 6_302_000_014, self::TRITANIUM_TYPE_ID, 50, self::TRITANIUM_SPLIT_UNIT_PRICE);

        $this->linkNew($this->ownerMainCharacter, [$buy]);

        self::assertSame(
            [[$this->tritaniumLine, 30], [$this->hecateTritaniumLine, 20]],
            array_map(static fn (IndustryProjectPurchase $purchase): array => [$purchase->getBomLine(), $purchase->getQuantity()], $this->persistedEntities),
        );
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

        $containerDeliveryConfirmer = $this->createStub(ContainerDeliveryConfirmer::class);
        $containerDeliveryConfirmer->method('confirmProject')->willReturnCallback(function (IndustryProject $project): int {
            $this->confirmedProjects[] = [$project->getId()->toRfc4122(), $this->flushCount];

            return 0;
        });

        (new AutomaticPurchaseLinker($projectRepository, $purchaseRepository, $entityManager, $derivedContributionService, $containerDeliveryConfirmer))
            ->linkNewPurchases($character, $transactions);
    }

    private function openTwoProjectsLeftToBuy(int $ishtarLeftToBuy, int $hecateLeftToBuy): void
    {
        $this->tritaniumLine->setRequiredQuantity($ishtarLeftToBuy);
        $this->hecateTritaniumLine->setRequiredQuantity($hecateLeftToBuy);
        $this->createdAt($this->hecateProject, '2026-11-01T11:00:00+00:00');
        $this->openProjects = [$this->ishtarProject, $this->hecateProject];
    }

    private function openTritaniumProject(string $name, string $createdAt, int $requiredQuantity): IndustryProjectBomLine
    {
        $project = $this->projectOfTheOwner($name);
        $this->participate($project, $this->owner, ParticipantRole::Owner);
        $this->createdAt($project, $createdAt);
        $line = $this->materialLine($project, $requiredQuantity);
        $this->openProjects[] = $project;

        return $line;
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

    private function createdAt(IndustryProject $project, string $date): void
    {
        (new \ReflectionProperty(IndustryProject::class, 'createdAt'))->setValue($project, new \DateTimeImmutable($date));
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
