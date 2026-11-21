<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ContributionValueSource;
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
#[Group('issue-1159')]
#[AllowMockObjectsWithoutExpectations]
final class ProjectContributionServiceSoloCorrectionTest extends TestCase
{
    private const int NOCXIUM_TYPE_ID = 38;
    private const int HYDROGEN_FUEL_BLOCK_TYPE_ID = 4246;
    private const int REQUIRED_QUANTITY = 100;
    private const int SUPPLIED_QUANTITY = 40;
    private const int SUPPLIED_BY_OTHERS = 60;
    private const float JITA_UNIT_PRICE = 6.0;
    private const float SUPPLIED_VALUE = 240.0;

    private ProjectContributionService $service;
    private EntityManagerInterface&MockObject $entityManager;
    private IndustryProjectService&MockObject $projectService;
    private IndustryProject $project;
    private User $owner;
    private IndustryProjectParticipant $ownerMembership;
    private IndustryProjectBomLine $line;

    protected function setUp(): void
    {
        $jita = $this->createStub(JitaMarketService::class);
        $jita->method('getCheapestPercentilePrices')->willReturnCallback(
            static fn (array $typeIds): array => array_fill_keys($typeIds, self::JITA_UNIT_PRICE),
        );
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $work): mixed => $work());
        $this->projectService = $this->createMock(IndustryProjectService::class);

        $this->service = new ProjectContributionService(
            $this->createStub(IndustryProjectContributionRepository::class),
            $this->entityManager,
            $jita,
            $this->createStub(LineReservationService::class),
            $this->projectService,
            $this->createStub(ContributorPaidPurchaseReader::class),
        );

        $this->owner = new User();
        $this->project = (new IndustryProject())->setOwner($this->owner);
        $this->project->enrolOwner();
        $this->project->setStatus(ProjectStatus::InProgress);
        $this->ownerMembership = $this->project->getMembers()->first();
        $this->ownerMembership->setUser($this->owner);
        $this->line = $this->materialLine();
    }

    public function testCorrectingTheQuantityOfAnApprovedSoloContributionRevaluesItAndMovesTheFulfilledQuantity(): void
    {
        $contribution = $this->approvedContribution($this->line, ContributionType::Material);

        $this->service->edit($contribution, 25, null, null);

        self::assertSame([25, 25 * self::JITA_UNIT_PRICE, ContributionStatus::Approved], [$contribution->getQuantity(), $contribution->getEstimatedValue(), $contribution->getStatus()]);
        self::assertSame(self::SUPPLIED_BY_OTHERS + 25, $this->line->getFulfilledQuantity());
        self::assertSame(self::REQUIRED_QUANTITY - self::SUPPLIED_BY_OTHERS - 25, $this->line->getRemainingQuantity());
    }

    public function testCorrectingTheQuantityUpToTheRoomLeftByTheOtherSuppliesIsAccepted(): void
    {
        $contribution = $this->approvedContribution($this->line, ContributionType::Material);
        $this->line->setFulfilledQuantity(self::REQUIRED_QUANTITY);

        $this->service->edit($contribution, self::SUPPLIED_QUANTITY, null, 'recounted');

        self::assertSame(self::REQUIRED_QUANTITY, $this->line->getFulfilledQuantity());
        self::assertSame('recounted', $contribution->getNote());
    }

    public function testCorrectingTheQuantityAboveTheRoomLeftByTheOtherSuppliesIsRefusedAndNothingChanges(): void
    {
        $contribution = $this->approvedContribution($this->line, ContributionType::Material);
        $this->line->setFulfilledQuantity(self::REQUIRED_QUANTITY);
        $this->entityManager->expects(self::never())->method('flush');

        try {
            $this->service->edit($contribution, self::SUPPLIED_QUANTITY + 1, null, null);
            self::fail('The corrected quantity exceeds the room left on the line');
        } catch (\DomainException) {
            self::assertSame([self::SUPPLIED_QUANTITY, self::SUPPLIED_VALUE, ContributionStatus::Approved], [$contribution->getQuantity(), $contribution->getEstimatedValue(), $contribution->getStatus()]);
            self::assertSame(self::REQUIRED_QUANTITY, $this->line->getFulfilledQuantity());
        }
    }

    public function testCorrectingOnlyTheNoteOfAnApprovedSoloContributionLeavesTheFulfilledQuantityAlone(): void
    {
        $contribution = $this->approvedContribution($this->line, ContributionType::Material);
        $this->projectService->expects(self::never())->method('buildBom');

        $this->service->edit($contribution, null, null, 'bought in Jita');

        self::assertSame([ContributionStatus::Approved, self::SUPPLIED_QUANTITY, 'bought in Jita'], [$contribution->getStatus(), $contribution->getQuantity(), $contribution->getNote()]);
        self::assertSame(self::SUPPLIED_BY_OTHERS + self::SUPPLIED_QUANTITY, $this->line->getFulfilledQuantity());
    }

    public function testCorrectingAnApprovedSoloContributionWithNothingToChangeIsRefused(): void
    {
        $contribution = $this->approvedContribution($this->line, ContributionType::Material);

        $this->expectException(\DomainException::class);

        $this->service->edit($contribution, null, null, null);
    }

    public function testACorrectedWalletPurchaseThatNoLongerMatchesTheWalletBecomesPendingAndReleasesTheLine(): void
    {
        $contribution = $this->approvedContribution($this->line, ContributionType::Material)->setValueSource(ContributionValueSource::WalletPurchase);

        $this->service->edit($contribution, 30, null, null);

        self::assertSame([ContributionStatus::Pending, 30, 30], [$contribution->getStatus(), $contribution->getQuantity(), $contribution->getAwaitingQuantity()]);
        self::assertSame(self::SUPPLIED_BY_OTHERS, $this->line->getFulfilledQuantity());
    }

    public function testCorrectingAnIntermediateContributionRecalculatesTheBomOnceForTheOwnerAndMovesTheFulfilledQuantity(): void
    {
        $hydrogenFuelBlock = $this->jobLine();
        $contribution = $this->approvedContribution($hydrogenFuelBlock, ContributionType::Material);
        $this->projectService->expects(self::once())->method('buildBom')->with($this->project, $this->owner, true);

        $this->service->edit($contribution, 10, null, null);

        self::assertSame([10, ContributionStatus::Approved, self::SUPPLIED_BY_OTHERS + 10], [$contribution->getQuantity(), $contribution->getStatus(), $hydrogenFuelBlock->getFulfilledQuantity()]);
    }

    public function testARefusedBomRecalculationOfAnIntermediateCorrectionIsReportedAsADomainException(): void
    {
        $contribution = $this->approvedContribution($this->jobLine(), ContributionType::Material);
        $this->projectService->method('buildBom')->willThrowException(new \RuntimeException('The line "Hydrogen Isotopes" has already received more than the plan now needs.'));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('The line "Hydrogen Isotopes" has already received more than the plan now needs.');

        $this->service->edit($contribution, 10, null, null);
    }

    public function testWithdrawingAnApprovedSoloContributionRemovesItAndGivesTheQuantityBackToTheLine(): void
    {
        $contribution = $this->approvedContribution($this->line, ContributionType::Material);
        $this->entityManager->expects(self::once())->method('remove')->with($contribution);
        $this->projectService->expects(self::never())->method('buildBom');

        $this->service->withdraw($contribution);

        self::assertFalse($this->project->getContributions()->contains($contribution));
        self::assertSame(self::SUPPLIED_BY_OTHERS, $this->line->getFulfilledQuantity());
    }

    public function testWithdrawingAnApprovedIntermediateContributionRecalculatesTheBomOnceForTheOwner(): void
    {
        $hydrogenFuelBlock = $this->jobLine();
        $contribution = $this->approvedContribution($hydrogenFuelBlock, ContributionType::Material);
        $this->entityManager->expects(self::once())->method('remove')->with($contribution);
        $this->projectService->expects(self::once())->method('buildBom')->with($this->project, $this->owner, true);

        $this->service->withdraw($contribution);

        self::assertSame(self::SUPPLIED_BY_OTHERS, $hydrogenFuelBlock->getFulfilledQuantity());
    }

    public function testEditingAPendingSoloContributionDoesNotTouchTheFulfilledQuantity(): void
    {
        $contribution = $this->approvedContribution($this->line, ContributionType::Material)->setStatus(ContributionStatus::Pending);
        $this->line->setFulfilledQuantity(self::SUPPLIED_BY_OTHERS);

        $this->service->edit($contribution, 25, null, null);

        self::assertSame([25, ContributionStatus::Pending, self::SUPPLIED_BY_OTHERS], [$contribution->getQuantity(), $contribution->getStatus(), $this->line->getFulfilledQuantity()]);
    }

    public function testNothingIsRevisableOnAProjectInTheSellingPhase(): void
    {
        $contribution = $this->approvedContribution($this->line, ContributionType::Material);
        $this->project->setStatus(ProjectStatus::Selling);
        $this->entityManager->expects(self::never())->method('remove');

        foreach ([fn () => $this->service->edit($contribution, 25, null, null), fn () => $this->service->withdraw($contribution)] as $correction) {
            try {
                $correction();
                self::fail('A selling project locks its approved contributions');
            } catch (\DomainException) {
                self::assertSame([self::SUPPLIED_QUANTITY, self::SUPPLIED_BY_OTHERS + self::SUPPLIED_QUANTITY], [$contribution->getQuantity(), $this->line->getFulfilledQuantity()]);
            }
        }
    }

    private function materialLine(): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId(self::NOCXIUM_TYPE_ID)
            ->setTypeName('Nocxium')
            ->setRequiredQuantity(self::REQUIRED_QUANTITY)
            ->setIsJob(false)
            ->setFulfilledQuantity(self::SUPPLIED_BY_OTHERS + self::SUPPLIED_QUANTITY);
        $this->project->addBomItem($line);

        return $line;
    }

    private function jobLine(): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId(self::HYDROGEN_FUEL_BLOCK_TYPE_ID)
            ->setTypeName('Hydrogen Fuel Block')
            ->setRequiredQuantity(self::REQUIRED_QUANTITY)
            ->setIsJob(true)
            ->setJobGroup('component')
            ->setActivityType('manufacturing')
            ->setRuns(1)
            ->setFulfilledQuantity(self::SUPPLIED_BY_OTHERS + self::SUPPLIED_QUANTITY);
        $this->project->addBomItem($line);

        return $line;
    }

    private function approvedContribution(IndustryProjectBomLine $line, ContributionType $type): IndustryProjectContribution
    {
        $contribution = (new IndustryProjectContribution())
            ->setProject($this->project)
            ->setMember($this->ownerMembership)
            ->setBomItem($line)
            ->setType($type)
            ->setQuantity(self::SUPPLIED_QUANTITY)
            ->setEstimatedValue(self::SUPPLIED_VALUE)
            ->setStatus(ContributionStatus::Approved)
            ->setIsAutoDetected(false)
            ->setValueSource(ContributionValueSource::JitaPrice);
        $this->project->addContribution($contribution);

        return $contribution;
    }
}
