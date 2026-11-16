<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\CachedWalletTransaction;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectPurchase;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ContributionValueSource;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectContributionRepository;
use App\Service\Industry\Project\ContributorPaidPurchaseReader;
use App\Service\Industry\Project\PaidMaterialPurchases;
use App\Service\Industry\Project\PaidTransactionUnits;
use App\Service\Industry\Project\PendingContributionRevaluer;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(PendingContributionRevaluer::class)]
#[Group('issue-1009')]
#[AllowMockObjectsWithoutExpectations]
final class PendingContributionRevaluerTest extends TestCase
{
    private const int TRITANIUM_TYPE_ID = 34;
    private const int DECLARED_QUANTITY = 100;

    private ContributorPaidPurchaseReader&MockObject $paidPurchases;
    private HubInterface&MockObject $hub;
    private PendingContributionRevaluer $revaluer;
    private User $contributor;
    private IndustryProjectParticipant $member;
    private IndustryProjectBomLine $line;
    private IndustryProjectContribution $contribution;

    protected function setUp(): void
    {
        $this->contributor = new User();
        $project = (new IndustryProject())->setOwner(new User());
        $project->enrolOwner();
        $this->member = (new IndustryProjectParticipant())->setUser($this->contributor)->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted);
        $project->addMember($this->member);
        $this->line = (new IndustryProjectBomLine())->setTypeId(self::TRITANIUM_TYPE_ID)->setTypeName('Tritanium')->setRequiredQuantity(10000)->setIsJob(false);
        $project->addBomItem($this->line);

        $this->contribution = (new IndustryProjectContribution())
            ->setProject($project)
            ->setMember($this->member)
            ->setBomItem($this->line)
            ->setType(ContributionType::Material)
            ->setQuantity(self::DECLARED_QUANTITY)
            ->setStatus(ContributionStatus::Pending);

        $contributions = $this->createMock(IndustryProjectContributionRepository::class);
        $contributions->method('findPendingRevaluableOf')->with($this->contributor)->willReturnCallback(fn (): array => [$this->contribution]);
        $this->paidPurchases = $this->createMock(ContributorPaidPurchaseReader::class);
        $this->hub = $this->createMock(HubInterface::class);

        $this->revaluer = new PendingContributionRevaluer(
            $contributions,
            $this->paidPurchases,
            $this->createMock(EntityManagerInterface::class),
            new MercurePublisherService($this->hub, new NullLogger()),
        );
    }

    public function testAwaitingUnitsAreValuedAtThePaidPriceOfTheNewTransactionsAndLinked(): void
    {
        $this->givenAWalletContribution(linkedUnits: 60, linkedUnitPrice: 5.0);
        $newTransaction = $this->buy(40, 6.0);
        $this->paidPurchases->method('read')->willReturn($this->paid($newTransaction));

        $this->revaluer->revalueFor($this->contributor);

        self::assertSame([540.0, 0, 100], [$this->contribution->getEstimatedValue(), $this->contribution->getAwaitingQuantity(), $this->line->getPurchasedQuantity()]);
        $newPurchases = array_values(array_filter($this->line->getPurchases()->toArray(), static fn (IndustryProjectPurchase $purchase): bool => $purchase->getTransaction() === $newTransaction));
        self::assertCount(1, $newPurchases);
        self::assertSame([$this->contribution, $this->member, 40], [$newPurchases[0]->getContribution(), $newPurchases[0]->getBuyer(), $newPurchases[0]->getQuantity()]);
    }

    public function testAContributionWithNothingLinkedYetGetsItsFirstValueFromAPartialMatch(): void
    {
        $this->givenAWalletContribution(linkedUnits: 0, linkedUnitPrice: 0.0);
        $this->paidPurchases->method('read')->willReturn($this->paid($this->buy(30, 5.0)));

        $this->revaluer->revalueFor($this->contributor);

        self::assertSame([150.0, 70], [$this->contribution->getEstimatedValue(), $this->contribution->getAwaitingQuantity()]);
    }

    public function testJitaValuedUnitsKeepTheirImpliedPriceWhenOnlyPartOfThemAreMatched(): void
    {
        $this->givenAJitaContribution(linkedUnits: 60, linkedUnitPrice: 5.0, jitaUnitPrice: 7.0);
        $this->paidPurchases->method('read')->willReturn($this->paid($this->buy(10, 6.0)));

        $this->revaluer->revalueFor($this->contributor);

        self::assertSame([570.0, true], [$this->contribution->getEstimatedValue(), $this->contribution->isValuedAtJitaPrice()]);
    }

    public function testJitaValuedContributionIsNoLongerFlaggedOnceEveryUnitIsPaid(): void
    {
        $this->givenAJitaContribution(linkedUnits: 60, linkedUnitPrice: 5.0, jitaUnitPrice: 7.0);
        $this->paidPurchases->method('read')->willReturn($this->paid($this->buy(40, 6.0)));

        $this->revaluer->revalueFor($this->contributor);

        self::assertSame([540.0, false], [$this->contribution->getEstimatedValue(), $this->contribution->isValuedAtJitaPrice()]);
    }

    public function testRevaluationPublishesAGroupProjectEventPerRevaluedContribution(): void
    {
        $this->givenAWalletContribution(linkedUnits: 60, linkedUnitPrice: 5.0);
        $this->paidPurchases->method('read')->willReturn($this->paid($this->buy(40, 6.0)));
        $published = [];
        $this->hub->method('publish')->willReturnCallback(function (Update $update) use (&$published): string {
            $published[] = $update->getData();

            return 'id';
        });

        $this->revaluer->revalueFor($this->contributor);

        self::assertCount(1, $published);
        self::assertStringContainsString('contribution_valued', $published[0]);
        self::assertStringContainsString($this->contribution->getId()->toRfc4122(), $published[0]);
    }

    public function testNothingNewMeansNoChangeAndNoEvent(): void
    {
        $this->givenAWalletContribution(linkedUnits: 60, linkedUnitPrice: 5.0);
        $this->paidPurchases->method('read')->willReturn(null);
        $this->hub->expects($this->never())->method('publish');

        $this->revaluer->revalueFor($this->contributor);

        self::assertSame([300.0, 40, 60], [$this->contribution->getEstimatedValue(), $this->contribution->getAwaitingQuantity(), $this->line->getPurchasedQuantity()]);
    }

    private function givenAWalletContribution(int $linkedUnits, float $linkedUnitPrice): void
    {
        $this->contribution->setValueSource(ContributionValueSource::WalletPurchase);
        $this->contribution->setAwaitingQuantity(self::DECLARED_QUANTITY - $linkedUnits);
        $this->contribution->setEstimatedValue($linkedUnits > 0 ? $linkedUnits * $linkedUnitPrice : null);
        $this->linkPurchase($linkedUnits, $linkedUnitPrice);
    }

    private function givenAJitaContribution(int $linkedUnits, float $linkedUnitPrice, float $jitaUnitPrice): void
    {
        $this->contribution->setValueSource(ContributionValueSource::JitaPrice);
        $this->contribution->setValuedAtJitaPrice(true);
        $this->contribution->setEstimatedValue($linkedUnits * $linkedUnitPrice + (self::DECLARED_QUANTITY - $linkedUnits) * $jitaUnitPrice);
        $this->linkPurchase($linkedUnits, $linkedUnitPrice);
    }

    private function linkPurchase(int $units, float $unitPrice): void
    {
        if (0 === $units) {
            return;
        }

        $this->line->addPurchase(
            (new IndustryProjectPurchase())
                ->setTransaction($this->buy($units, $unitPrice))
                ->setBuyer($this->member)
                ->setContribution($this->contribution)
                ->setTypeId(self::TRITANIUM_TYPE_ID)
                ->setQuantity($units)
                ->setUnitPrice($unitPrice)
                ->setTotalPrice($units * $unitPrice)
                ->setSource('esi_wallet'),
        );
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
