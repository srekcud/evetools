<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\GroupIndustry;

use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Repository\GroupIndustryContributionRepository;
use App\Service\GroupIndustry\GroupIndustryContributionService;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(GroupIndustryContributionService::class)]
#[AllowMockObjectsWithoutExpectations]
class GroupIndustryContributionServiceTest extends TestCase
{
    private GroupIndustryContributionRepository&MockObject $contributionRepository;
    private JitaMarketService&MockObject $jitaMarketService;
    private EntityManagerInterface&MockObject $entityManager;
    private GroupIndustryContributionService $service;

    private GroupIndustryProject $project;
    private GroupIndustryProjectMember $member;
    private GroupIndustryBomItem $bomItem;
    private User $reviewer;

    protected function setUp(): void
    {
        $this->contributionRepository = $this->createMock(GroupIndustryContributionRepository::class);
        $this->jitaMarketService = $this->createMock(JitaMarketService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->service = new GroupIndustryContributionService(
            $this->contributionRepository,
            $this->jitaMarketService,
            $this->entityManager,
        );

        $this->project = new GroupIndustryProject();
        $this->project->setOwner($this->createMock(User::class));

        $this->member = new GroupIndustryProjectMember();
        $this->member->setProject($this->project);
        $this->member->setUser($this->createMock(User::class));

        $this->bomItem = new GroupIndustryBomItem();
        $this->bomItem->setProject($this->project);
        $this->bomItem->setTypeId(34); // Tritanium
        $this->bomItem->setTypeName('Tritanium');
        $this->bomItem->setRequiredQuantity(10000);

        $this->reviewer = $this->createMock(User::class);
    }

    public function testSubmitMaterialCalculatesRavworksPrice(): void
    {
        $this->contributionRepository
            ->method('findByMemberBomItemAndType')
            ->willReturn(null);

        $this->jitaMarketService
            ->expects($this->once())
            ->method('getCheapestPercentilePrices')
            ->with([34])
            ->willReturn([34 => 5.50]);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $contribution = $this->service->submit(
            $this->member,
            $this->bomItem,
            ContributionType::Material,
            1000,
        );

        $this->assertSame(ContributionStatus::Pending, $contribution->getStatus());
        $this->assertSame(ContributionType::Material, $contribution->getType());
        $this->assertSame(1000, $contribution->getQuantity());
        $this->assertEqualsWithDelta(5500.0, $contribution->getEstimatedValue(), 0.01);
        $this->assertFalse($contribution->isAutoDetected());
        $this->assertFalse($contribution->isVerified());
        $this->assertSame($this->project, $contribution->getProject());
        $this->assertSame($this->member, $contribution->getMember());
        $this->assertSame($this->bomItem, $contribution->getBomItem());
    }

    public function testSubmitWithExplicitValueSkipsPriceCalculation(): void
    {
        $this->contributionRepository
            ->method('findByMemberBomItemAndType')
            ->willReturn(null);

        $this->jitaMarketService
            ->expects($this->never())
            ->method('getCheapestPercentilePrices');

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $contribution = $this->service->submit(
            $this->member,
            $this->bomItem,
            ContributionType::Material,
            1000,
            estimatedValue: 9999.99,
            note: 'Manual override',
        );

        $this->assertEqualsWithDelta(9999.99, $contribution->getEstimatedValue(), 0.01);
        $this->assertSame('Manual override', $contribution->getNote());
    }

    public function testSubmitNonMaterialTypeDoesNotCalculatePrice(): void
    {
        $this->contributionRepository
            ->method('findByMemberBomItemAndType')
            ->willReturn(null);

        $this->jitaMarketService
            ->expects($this->never())
            ->method('getCheapestPercentilePrices');

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $contribution = $this->service->submit(
            $this->member,
            $this->bomItem,
            ContributionType::Bpc,
            1,
            estimatedValue: 50000.0,
        );

        $this->assertSame(ContributionType::Bpc, $contribution->getType());
        $this->assertEqualsWithDelta(50000.0, $contribution->getEstimatedValue(), 0.01);
    }

    public function testSubmitAntiDuplicateBlocksPending(): void
    {
        $existingContribution = new GroupIndustryContribution();
        $existingContribution->setStatus(ContributionStatus::Pending);

        $this->contributionRepository
            ->method('findByMemberBomItemAndType')
            ->willReturn($existingContribution);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('A contribution of this type is already pending or approved for this item');

        $this->service->submit(
            $this->member,
            $this->bomItem,
            ContributionType::Material,
            1000,
        );
    }

    public function testSubmitAntiDuplicateBlocksApproved(): void
    {
        $existingContribution = new GroupIndustryContribution();
        $existingContribution->setStatus(ContributionStatus::Approved);

        $this->contributionRepository
            ->method('findByMemberBomItemAndType')
            ->willReturn($existingContribution);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('A contribution of this type is already pending or approved for this item');

        $this->service->submit(
            $this->member,
            $this->bomItem,
            ContributionType::Material,
            1000,
        );
    }

    public function testSubmitAfterRejectionIsAllowed(): void
    {
        // Repository returns null because rejected contributions are not in the filter
        $this->contributionRepository
            ->method('findByMemberBomItemAndType')
            ->willReturn(null);

        $this->jitaMarketService
            ->method('getCheapestPercentilePrices')
            ->willReturn([34 => 5.50]);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $contribution = $this->service->submit(
            $this->member,
            $this->bomItem,
            ContributionType::Material,
            500,
        );

        $this->assertSame(ContributionStatus::Pending, $contribution->getStatus());
    }

    public function testApproveChangeStatusAndIncrementsFulfillment(): void
    {
        $contribution = new GroupIndustryContribution();
        $contribution->setProject($this->project);
        $contribution->setMember($this->member);
        $contribution->setBomItem($this->bomItem);
        $contribution->setType(ContributionType::Material);
        $contribution->setQuantity(500);
        $contribution->setEstimatedValue(2750.0);
        $contribution->setStatus(ContributionStatus::Pending);

        $this->assertSame(0, $this->bomItem->getFulfilledQuantity());

        $this->entityManager->expects($this->once())->method('flush');

        $this->service->approve($contribution, $this->reviewer);

        $this->assertSame(ContributionStatus::Approved, $contribution->getStatus());
        $this->assertSame($this->reviewer, $contribution->getReviewedBy());
        $this->assertNotNull($contribution->getReviewedAt());
        $this->assertSame(500, $this->bomItem->getFulfilledQuantity());
    }

    public function testApproveAlreadyApprovedThrows(): void
    {
        $contribution = new GroupIndustryContribution();
        $contribution->setStatus(ContributionStatus::Approved);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot approve a contribution with status "approved"');

        $this->service->approve($contribution, $this->reviewer);
    }

    public function testRejectChangesStatusWithoutFulfillment(): void
    {
        $contribution = new GroupIndustryContribution();
        $contribution->setProject($this->project);
        $contribution->setMember($this->member);
        $contribution->setBomItem($this->bomItem);
        $contribution->setType(ContributionType::Material);
        $contribution->setQuantity(500);
        $contribution->setEstimatedValue(2750.0);
        $contribution->setStatus(ContributionStatus::Pending);

        $this->assertSame(0, $this->bomItem->getFulfilledQuantity());

        $this->entityManager->expects($this->once())->method('flush');

        $this->service->reject($contribution, $this->reviewer);

        $this->assertSame(ContributionStatus::Rejected, $contribution->getStatus());
        $this->assertSame($this->reviewer, $contribution->getReviewedBy());
        $this->assertNotNull($contribution->getReviewedAt());
        // fulfilledQuantity must NOT change on rejection
        $this->assertSame(0, $this->bomItem->getFulfilledQuantity());
    }

    public function testRejectAlreadyRejectedThrows(): void
    {
        $contribution = new GroupIndustryContribution();
        $contribution->setStatus(ContributionStatus::Rejected);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot reject a contribution with status "rejected"');

        $this->service->reject($contribution, $this->reviewer);
    }

    public function testSubmitAutoDetectedIsApprovedAndIncrementsFulfillment(): void
    {
        $this->contributionRepository
            ->method('findByMemberBomItemAndType')
            ->willReturn(null);

        $this->assertSame(0, $this->bomItem->getFulfilledQuantity());

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $contribution = $this->service->submitAutoDetected(
            $this->member,
            $this->bomItem,
            ContributionType::JobInstall,
            1,
            125000.0,
        );

        $this->assertSame(ContributionStatus::Approved, $contribution->getStatus());
        $this->assertTrue($contribution->isAutoDetected());
        $this->assertTrue($contribution->isVerified());
        $this->assertSame(ContributionType::JobInstall, $contribution->getType());
        $this->assertEqualsWithDelta(125000.0, $contribution->getEstimatedValue(), 0.01);
        $this->assertSame(1, $this->bomItem->getFulfilledQuantity());
    }

    public function testCalculateMaterialValueWithUnknownPrice(): void
    {
        $this->jitaMarketService
            ->method('getCheapestPercentilePrices')
            ->willReturn([99999 => null]);

        $value = $this->service->calculateMaterialValue(99999, 100);

        $this->assertEqualsWithDelta(0.0, $value, 0.01);
    }

    public function testCalculateMaterialValueWithKnownPrice(): void
    {
        $this->jitaMarketService
            ->method('getCheapestPercentilePrices')
            ->with([34])
            ->willReturn([34 => 5.50]);

        $value = $this->service->calculateMaterialValue(34, 200);

        $this->assertEqualsWithDelta(1100.0, $value, 0.01);
    }
}
