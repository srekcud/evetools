<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\CachedWalletTransaction;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ContributionValueSource;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectContributionRepository;
use App\Service\Industry\Project\ContributorPaidPurchaseReader;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\LineReservationService;
use App\Service\Industry\Project\PaidMaterialPurchases;
use App\Service\Industry\Project\PaidTransactionUnits;
use App\Service\Industry\Project\ProjectContributionService;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectContributionService::class)]
#[Group('issue-1009')]
#[AllowMockObjectsWithoutExpectations]
final class ProjectContributionServiceWalletPurchaseTest extends TestCase
{
    private const int TRITANIUM_TYPE_ID = 34;
    private const float TRITANIUM_JITA_PRICE = 7.0;

    private ContributorPaidPurchaseReader&MockObject $paidPurchases;
    private ProjectContributionService $service;
    private IndustryProject $project;
    private IndustryProjectParticipant $member;
    private IndustryProjectBomLine $line;
    private ?float $jitaPrice = self::TRITANIUM_JITA_PRICE;
    private int $jitaLookups = 0;

    protected function setUp(): void
    {
        $jitaMarket = $this->createMock(JitaMarketService::class);
        $jitaMarket->method('getCheapestPercentilePrices')->willReturnCallback(function (): array {
            ++$this->jitaLookups;

            return [self::TRITANIUM_TYPE_ID => $this->jitaPrice];
        });
        $this->paidPurchases = $this->createMock(ContributorPaidPurchaseReader::class);

        $this->service = new ProjectContributionService(
            $this->createMock(IndustryProjectContributionRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $jitaMarket,
            $this->createStub(LineReservationService::class),
            $this->createStub(IndustryProjectService::class),
            $this->paidPurchases,
        );

        $this->project = (new IndustryProject())->setOwner(new User());
        $this->project->enrolOwner();
        $this->member = (new IndustryProjectParticipant())->setUser(new User())->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted);
        $this->project->addMember($this->member);
        $this->line = (new IndustryProjectBomLine())->setTypeId(self::TRITANIUM_TYPE_ID)->setTypeName('Tritanium')->setRequiredQuantity(10000)->setIsJob(false);
        $this->project->addBomItem($this->line);
    }

    public function testWalletPurchaseFullyMatchedIsValuedAtThePaidPriceAndLinksTheTransactionToTheLine(): void
    {
        $transaction = $this->buy(100, 5.0);
        $this->paidPurchases->method('read')->willReturn($this->paid($transaction));

        $contribution = $this->submitWalletPurchase(100);

        self::assertSame([500.0, 0, ContributionValueSource::WalletPurchase, false, 0], [$contribution->getEstimatedValue(), $contribution->getAwaitingQuantity(), $contribution->getValueSource(), $contribution->isValuedAtJitaPrice(), $this->jitaLookups]);
        $purchases = $this->line->getPurchases()->toArray();
        self::assertCount(1, $purchases);
        self::assertSame([$transaction, $this->member, $contribution, 100, 500.0, self::TRITANIUM_TYPE_ID], [$purchases[0]->getTransaction(), $purchases[0]->getBuyer(), $purchases[0]->getContribution(), $purchases[0]->getQuantity(), $purchases[0]->getTotalPrice(), $purchases[0]->getTypeId()]);
    }

    public function testWalletPurchasePartlyMatchedKeepsTheMissingUnitsAwaitingWithoutAJitaEstimate(): void
    {
        $this->paidPurchases->method('read')->willReturn($this->paid($this->buy(60, 5.0)));

        $contribution = $this->submitWalletPurchase(100);

        self::assertSame([300.0, 40, 0], [$contribution->getEstimatedValue(), $contribution->getAwaitingQuantity(), $this->jitaLookups]);
        self::assertSame(60, $this->line->getPurchasedQuantity());
    }

    public function testWalletPurchaseWithoutAnyTransactionIsPendingWithAnUnknownValueNotZero(): void
    {
        $this->paidPurchases->method('read')->willReturn(null);
        $this->jitaPrice = null;

        $contribution = $this->submitWalletPurchase(100);

        self::assertSame([null, 100, ContributionStatus::Pending, 0], [$contribution->getEstimatedValue(), $contribution->getAwaitingQuantity(), $contribution->getStatus(), $this->line->getPurchasedQuantity()]);
    }

    public function testWalletPurchaseOnALargerTransactionLinksOnlyTheDeclaredUnits(): void
    {
        $this->paidPurchases->method('read')->willReturn($this->paid($this->buy(200, 5.0)));

        $contribution = $this->submitWalletPurchase(100);

        self::assertSame([500.0, 0, 100], [$contribution->getEstimatedValue(), $contribution->getAwaitingQuantity(), $this->line->getPurchasedQuantity()]);
    }

    public function testJitaPriceSourceValuesTheUnmatchedUnitsAtJitaAndLinksTheMatchedOnes(): void
    {
        $this->paidPurchases->method('read')->willReturn($this->paid($this->buy(60, 5.0)));

        $contribution = $this->service->submit($this->member, $this->line, ContributionType::Material, 100, null, null, ContributionValueSource::JitaPrice);

        self::assertSame([580.0, true, 0, ContributionValueSource::JitaPrice, 60], [$contribution->getEstimatedValue(), $contribution->isValuedAtJitaPrice(), $contribution->getAwaitingQuantity(), $contribution->getValueSource(), $this->line->getPurchasedQuantity()]);
    }

    public function testJitaPriceSourceWithoutAJitaPriceStillRefusesTheUnmatchedUnits(): void
    {
        $this->paidPurchases->method('read')->willReturn($this->paid($this->buy(60, 5.0)));
        $this->jitaPrice = null;

        $this->expectException(\DomainException::class);

        $this->service->submit($this->member, $this->line, ContributionType::Material, 100, null, null, ContributionValueSource::JitaPrice);
    }

    public function testEditingTheQuantityOfAWalletPurchaseRelinksItsTransactionUnitsAtThePaidPrice(): void
    {
        $this->paidPurchases->method('read')->willReturn($this->paid($this->buy(200, 5.0)));
        $contribution = $this->submitWalletPurchase(100);

        $this->service->edit($contribution, 150, null, null);

        self::assertSame([750.0, 0, ContributionValueSource::WalletPurchase, false, 150, 1, 0], [$contribution->getEstimatedValue(), $contribution->getAwaitingQuantity(), $contribution->getValueSource(), $contribution->isValuedAtJitaPrice(), $this->line->getPurchasedQuantity(), $this->line->getPurchases()->count(), $this->jitaLookups]);
    }

    public function testEditingAWalletPurchaseBeyondItsTransactionsKeepsTheExtraUnitsAwaiting(): void
    {
        $this->paidPurchases->method('read')->willReturn($this->paid($this->buy(100, 5.0)));
        $contribution = $this->submitWalletPurchase(100);

        $this->service->edit($contribution, 120, null, null);

        self::assertSame([500.0, 20, 100, 0], [$contribution->getEstimatedValue(), $contribution->getAwaitingQuantity(), $this->line->getPurchasedQuantity(), $this->jitaLookups]);
    }

    public function testAValueSourceOnAJobInstallIsRefused(): void
    {
        $this->expectException(\DomainException::class);

        $this->service->submit($this->member, $this->line, ContributionType::JobInstall, 1, 1000.0, null, ContributionValueSource::WalletPurchase);
    }

    public function testAContributionAwaitingATransactionIsNotAutoApprovedForTheSoleReviewingOwner(): void
    {
        $this->paidPurchases->method('read')->willReturn(null);
        $ownerMember = $this->project->getOwnerParticipant();

        $contribution = $this->service->submit($ownerMember, $this->line, ContributionType::Material, 100, null, null, ContributionValueSource::WalletPurchase);

        self::assertSame(ContributionStatus::Pending, $contribution->getStatus());
    }

    public function testAContributionAwaitingATransactionCannotBeApproved(): void
    {
        $this->paidPurchases->method('read')->willReturn($this->paid($this->buy(60, 5.0)));
        $contribution = $this->submitWalletPurchase(100);

        try {
            $this->service->approve($contribution, new User());
            self::fail('The approval of a contribution awaiting units must be refused');
        } catch (\DomainException) {
            self::assertSame(ContributionStatus::Pending, $contribution->getStatus());
        }
    }

    public function testSwitchingToTheJitaPriceValuesTheAwaitingUnitsAndLetsTheContributionBeApproved(): void
    {
        $this->paidPurchases->method('read')->willReturn($this->paid($this->buy(60, 5.0)));
        $contribution = $this->submitWalletPurchase(100);

        $this->service->valueAtJitaPrice($contribution);

        self::assertSame([580.0, 0, ContributionValueSource::JitaPrice, true], [$contribution->getEstimatedValue(), $contribution->getAwaitingQuantity(), $contribution->getValueSource(), $contribution->isValuedAtJitaPrice()]);
        $this->service->approve($contribution, new User());
        self::assertSame(ContributionStatus::Approved, $contribution->getStatus());
    }

    public function testSwitchingAContributionWithNoMatchedUnitValuesTheWholeQuantityAtJita(): void
    {
        $this->paidPurchases->method('read')->willReturn(null);
        $contribution = $this->submitWalletPurchase(100);
        self::assertSame(100, $contribution->getAwaitingQuantity());

        $this->service->valueAtJitaPrice($contribution);

        self::assertSame([700.0, 0], [$contribution->getEstimatedValue(), $contribution->getAwaitingQuantity()]);
    }

    public function testSwitchingWithoutAJitaPriceChangesNothing(): void
    {
        $this->paidPurchases->method('read')->willReturn($this->paid($this->buy(60, 5.0)));
        $contribution = $this->submitWalletPurchase(100);
        $this->jitaPrice = null;

        try {
            $this->service->valueAtJitaPrice($contribution);
            self::fail('The switch without a Jita price must be refused');
        } catch (\DomainException) {
            self::assertSame([300.0, 40, ContributionValueSource::WalletPurchase], [$contribution->getEstimatedValue(), $contribution->getAwaitingQuantity(), $contribution->getValueSource()]);
        }
    }

    public function testSwitchingIsRefusedWhenNothingIsAwaiting(): void
    {
        $this->paidPurchases->method('read')->willReturn($this->paid($this->buy(100, 5.0)));
        $contribution = $this->submitWalletPurchase(100);

        $this->expectException(\DomainException::class);

        $this->service->valueAtJitaPrice($contribution);
    }

    public function testRejectingADeclaredContributionFreesItsLinkedTransactions(): void
    {
        $this->paidPurchases->method('read')->willReturn($this->paid($this->buy(100, 5.0)));
        $contribution = $this->submitWalletPurchase(100);
        self::assertSame(100, $this->line->getPurchasedQuantity());

        $this->service->reject($contribution, new User());

        self::assertSame([ContributionStatus::Rejected, 0], [$contribution->getStatus(), $this->line->getPurchasedQuantity()]);
    }

    private function submitWalletPurchase(int $quantity): IndustryProjectContribution
    {
        return $this->service->submit($this->member, $this->line, ContributionType::Material, $quantity, null, null, ContributionValueSource::WalletPurchase);
    }

    private function buy(int $quantity, float $unitPrice): CachedWalletTransaction
    {
        return (new CachedWalletTransaction())->setTypeId(self::TRITANIUM_TYPE_ID)->setQuantity($quantity)->setUnitPrice($unitPrice)->setIsBuy(true)->setDate(new \DateTimeImmutable());
    }

    private function paid(CachedWalletTransaction ...$transactions): PaidMaterialPurchases
    {
        return new PaidMaterialPurchases(array_map(static fn (CachedWalletTransaction $transaction): PaidTransactionUnits => new PaidTransactionUnits($transaction, $transaction->getQuantity()), $transactions));
    }
}
