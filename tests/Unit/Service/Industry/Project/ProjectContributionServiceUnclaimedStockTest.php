<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ContributionValueSource;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectContributionRepository;
use App\Service\Industry\Project\ContributorPaidPurchaseReader;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\LineReservationService;
use App\Service\Industry\Project\ProjectContributionService;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectContributionService::class)]
#[Group('issue-1050')]
#[AllowMockObjectsWithoutExpectations]
final class ProjectContributionServiceUnclaimedStockTest extends TestCase
{
    private const int TRITANIUM_TYPE_ID = 34;
    private const float TRITANIUM_LAUNCH_PRICE = 12.5;

    private JitaMarketService&MockObject $jitaMarket;
    private ContributorPaidPurchaseReader&MockObject $paidPurchases;
    private EntityManagerInterface&MockObject $entityManager;
    private ProjectContributionService $service;
    private IndustryProject $project;
    private User $owner;
    private IndustryProjectParticipant $member;
    private IndustryProjectBomLine $line;

    protected function setUp(): void
    {
        $this->jitaMarket = $this->createMock(JitaMarketService::class);
        $this->paidPurchases = $this->createMock(ContributorPaidPurchaseReader::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->service = new ProjectContributionService(
            $this->createMock(IndustryProjectContributionRepository::class),
            $this->entityManager,
            $this->jitaMarket,
            $this->createStub(LineReservationService::class),
            $this->createStub(IndustryProjectService::class),
            $this->paidPurchases,
        );

        $this->owner = new User();
        $this->project = (new IndustryProject())->setOwner($this->owner)->setStatus(ProjectStatus::InProgress);
        $this->project->enrolOwner();
        $this->member = (new IndustryProjectParticipant())->setUser(new User())->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted);
        $this->project->addMember($this->member);
        $this->line = (new IndustryProjectBomLine())->setTypeId(self::TRITANIUM_TYPE_ID)->setTypeName('Tritanium')->setRequiredQuantity(100)->setIsJob(false)->setLaunchPrice(self::TRITANIUM_LAUNCH_PRICE);
        $this->project->addBomItem($this->line);
    }

    public function testClaimIsValuedAtTheFrozenLaunchPriceWithoutReadingJitaNorTheWallet(): void
    {
        $this->jitaMarket->expects($this->never())->method('getCheapestPercentilePrices');
        $this->paidPurchases->expects($this->never())->method('read');

        $contribution = $this->service->submit($this->member, $this->line, ContributionType::Material, 50, null, null, ContributionValueSource::LaunchPrice);

        $this->assertSame([625.0, ContributionValueSource::LaunchPrice, false, ContributionStatus::Pending, 0], [
            $contribution->getEstimatedValue(),
            $contribution->getValueSource(),
            $contribution->isValuedAtJitaPrice(),
            $contribution->getStatus(),
            $contribution->getAwaitingQuantity(),
        ]);
    }

    public function testClaimWithoutAFrozenLaunchPriceIsRefusedWithoutAFallbackPrice(): void
    {
        $this->line->setLaunchPrice(null);
        $this->jitaMarket->expects($this->never())->method('getCheapestPercentilePrices');
        $this->entityManager->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('launch price');

        $this->service->submit($this->member, $this->line, ContributionType::Material, 50, null, null, ContributionValueSource::LaunchPrice);
    }

    public function testClaimTakesNoEnteredValue(): void
    {
        $this->expectException(\DomainException::class);

        $this->service->submit($this->member, $this->line, ContributionType::Material, 50, 999.0, null, ContributionValueSource::LaunchPrice);
    }

    public function testEditingAPendingClaimRevaluesTheNewQuantityAtTheLaunchPrice(): void
    {
        $contribution = $this->service->submit($this->member, $this->line, ContributionType::Material, 50, null, null, ContributionValueSource::LaunchPrice);

        $this->service->edit($contribution, 20, null, null);

        $this->assertSame([250.0, ContributionValueSource::LaunchPrice, 20], [$contribution->getEstimatedValue(), $contribution->getValueSource(), $contribution->getQuantity()]);
    }

    public function testAssignmentIsAnApprovedContributionOfTheRecipientValuedAtTheLaunchPrice(): void
    {
        $contribution = $this->service->assignUnclaimedStock($this->member, $this->line, 50, $this->owner);

        $this->assertSame(
            [$this->member, 625.0, ContributionValueSource::LaunchPrice, ContributionStatus::Approved, $this->owner, ContributionType::Material, 50],
            [$contribution->getMember(), $contribution->getEstimatedValue(), $contribution->getValueSource(), $contribution->getStatus(), $contribution->getReviewedBy(), $contribution->getType(), $contribution->getQuantity()],
        );
    }

    public function testAssignmentAdvancesTheFulfilledQuantityOfTheLine(): void
    {
        $this->service->assignUnclaimedStock($this->member, $this->line, 50, $this->owner);

        $this->assertSame(50, $this->line->getFulfilledQuantity());
    }

    public function testAssignmentWithoutAFrozenLaunchPriceIsRefusedAndNothingIsStored(): void
    {
        $this->line->setLaunchPrice(null);
        $this->entityManager->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('launch price');

        $this->service->assignUnclaimedStock($this->member, $this->line, 50, $this->owner);
    }

    public function testDonationIsAnApprovedContributionOfTheOwnerWorthNothing(): void
    {
        $contribution = $this->service->donateUnclaimedStock($this->project->getOwnerParticipant(), $this->line, 30, $this->owner);

        $this->assertSame(
            [$this->project->getOwnerParticipant(), 0.0, ContributionValueSource::Donation, ContributionStatus::Approved, $this->owner, 30],
            [$contribution->getMember(), $contribution->getEstimatedValue(), $contribution->getValueSource(), $contribution->getStatus(), $contribution->getReviewedBy(), $this->line->getFulfilledQuantity()],
        );
    }

    public function testDonationNeedsNoLaunchPrice(): void
    {
        $this->line->setLaunchPrice(null);

        $contribution = $this->service->donateUnclaimedStock($this->project->getOwnerParticipant(), $this->line, 30, $this->owner);

        $this->assertSame(0.0, $contribution->getEstimatedValue());
    }

    public function testAllocationOnAJobLineIsRefused(): void
    {
        $jobLine = (new IndustryProjectBomLine())->setTypeId(11478)->setTypeName('R.A.M.- Starship Tech')->setRequiredQuantity(100)->setIsJob(true);
        $this->project->addBomItem($jobLine);

        $this->expectException(\DomainException::class);

        $this->service->donateUnclaimedStock($this->project->getOwnerParticipant(), $jobLine, 10, $this->owner);
    }
}
