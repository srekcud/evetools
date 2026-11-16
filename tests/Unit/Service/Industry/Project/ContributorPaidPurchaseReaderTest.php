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
use App\Repository\CachedWalletTransactionRepository;
use App\Service\Industry\Project\ContributorPaidPurchaseReader;
use App\Service\Industry\Project\PaidTransactionUnits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContributorPaidPurchaseReader::class)]
#[Group('issue-953')]
final class ContributorPaidPurchaseReaderTest extends TestCase
{
    private const int TRITANIUM_TYPE_ID = 34;

    private IndustryProject $project;
    private IndustryProjectParticipant $member;
    private Character $firstCharacter;
    private Character $secondCharacter;
    private IndustryProjectBomLine $line;

    protected function setUp(): void
    {
        $user = new User();
        $this->firstCharacter = new Character();
        $this->secondCharacter = new Character();
        $user->addCharacter($this->firstCharacter);
        $user->addCharacter($this->secondCharacter);

        $this->project = (new IndustryProject())->setOwner(new User());
        $this->member = (new IndustryProjectParticipant())->setUser($user);
        $this->project->addMember($this->member);
        $this->line = (new IndustryProjectBomLine())->setTypeId(self::TRITANIUM_TYPE_ID)->setTypeName('Tritanium')->setRequiredQuantity(1000);
        $this->project->addBomItem($this->line);
    }

    public function testBuysOfAllTheCharactersOfTheMemberAddUpIntoOneQuantityAndOneTotalPrice(): void
    {
        $first = $this->buy(50, 4.0);
        $second = $this->buy(50, 6.0);

        $paid = $this->readerReturning([$first], [$second])->read($this->member, self::TRITANIUM_TYPE_ID);

        $this->assertNotNull($paid);
        $this->assertSame([100, 500.0, 5.0], [$paid->quantity, $paid->totalPrice, $paid->averageUnitPrice()]);
    }

    public function testBuysAreReadForTheTypeSinceTheCreationOfTheProject(): void
    {
        $repository = $this->createMock(CachedWalletTransactionRepository::class);
        $repository->expects($this->exactly(2))->method('findBuysByTypeIds')
            ->with($this->isInstanceOf(Character::class), [self::TRITANIUM_TYPE_ID], $this->project->getCreatedAt())
            ->willReturn([]);

        (new ContributorPaidPurchaseReader($repository))->read($this->member, self::TRITANIUM_TYPE_ID);
    }

    public function testNoBuyMeansNoPaidPriceNotAZeroOne(): void
    {
        $this->assertNull($this->readerReturning([], [])->read($this->member, self::TRITANIUM_TYPE_ID));
    }

    public function testABuyAlreadyLinkedToAPurchaseOfTheProjectIsNotCountedTwice(): void
    {
        $linked = $this->buy(40, 9.0);
        $free = $this->buy(60, 5.0);
        $this->line->addPurchase((new IndustryProjectPurchase())->setTransaction($linked)->setTypeId(self::TRITANIUM_TYPE_ID)->setQuantity(40)->setUnitPrice(9.0)->setTotalPrice(360.0)->setSource('esi_wallet'));

        $paid = $this->readerReturning([$linked, $free], [])->read($this->member, self::TRITANIUM_TYPE_ID);

        $this->assertNotNull($paid);
        $this->assertSame([60, 300.0], [$paid->quantity, $paid->totalPrice]);
    }

    #[Group('issue-1009')]
    public function testTransactionsAreOfferedOldestFirstWhateverTheOrderOfTheRepository(): void
    {
        $newer = $this->buy(50, 6.0)->setDate(new \DateTimeImmutable('2026-11-15 10:00:00'));
        $older = $this->buy(50, 4.0)->setDate(new \DateTimeImmutable('2026-11-14 10:00:00'));

        $paid = $this->readerReturning([$newer], [$older])->read($this->member, self::TRITANIUM_TYPE_ID);

        $this->assertNotNull($paid);
        $this->assertSame([$older, $newer], array_map(static fn (PaidTransactionUnits $units): CachedWalletTransaction => $units->transaction, $paid->transactions));
    }

    #[Group('issue-1009')]
    public function testPartlyLinkedTransactionIsOfferedForItsRemainingQuantityOnly(): void
    {
        $transaction = $this->buy(100, 5.0);
        $this->line->addPurchase((new IndustryProjectPurchase())->setTransaction($transaction)->setTypeId(self::TRITANIUM_TYPE_ID)->setQuantity(40)->setUnitPrice(5.0)->setTotalPrice(200.0)->setSource('esi_wallet'));

        $paid = $this->readerReturning([$transaction], [])->read($this->member, self::TRITANIUM_TYPE_ID);

        $this->assertNotNull($paid);
        $this->assertSame([60, 300.0], [$paid->quantity, $paid->totalPrice]);
        $this->assertSame([60], array_map(static fn (PaidTransactionUnits $units): int => $units->quantity, $paid->transactions));
    }

    public function testMemberWithoutUserHasNoPaidPrice(): void
    {
        $orphan = new IndustryProjectParticipant();
        $this->project->addMember($orphan);

        $this->assertNull($this->readerReturning([], [])->read($orphan, self::TRITANIUM_TYPE_ID));
    }

    /**
     * @param list<CachedWalletTransaction> $firstCharacterBuys
     * @param list<CachedWalletTransaction> $secondCharacterBuys
     */
    private function readerReturning(array $firstCharacterBuys, array $secondCharacterBuys): ContributorPaidPurchaseReader
    {
        $repository = $this->createStub(CachedWalletTransactionRepository::class);
        $repository->method('findBuysByTypeIds')->willReturnCallback(
            fn (Character $character): array => $character === $this->firstCharacter ? $firstCharacterBuys : $secondCharacterBuys,
        );

        return new ContributorPaidPurchaseReader($repository);
    }

    private function buy(int $quantity, float $unitPrice): CachedWalletTransaction
    {
        return (new CachedWalletTransaction())
            ->setTypeId(self::TRITANIUM_TYPE_ID)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setIsBuy(true)
            ->setDate(new \DateTimeImmutable());
    }
}
