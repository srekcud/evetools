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
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-706')]
final class ProjectContributionServiceIntermediateRecalculationTest extends TestCase
{
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int HYDROGEN_ISOTOPES = 17889;
    private const float HYDROGEN_FUEL_BLOCK_JITA_PRICE = 2_150.0;
    private const float HYDROGEN_ISOTOPES_JITA_PRICE = 6.25;

    private IndustryProjectService&MockObject $projectService;
    private ProjectContributionService $service;
    private IndustryProject $project;
    private User $owner;
    private User $reviewer;
    private IndustryProjectParticipant $ownerParticipant;
    private IndustryProjectBomLine $hydrogenFuelBlock;
    private IndustryProjectBomLine $hydrogenIsotopes;

    protected function setUp(): void
    {
        $this->projectService = $this->createMock(IndustryProjectService::class);

        $contributionRepository = $this->createStub(IndustryProjectContributionRepository::class);
        $contributionRepository->method('findByMemberBomItemAndType')->willReturn(null);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $work): mixed => $work($entityManager));

        $jitaMarket = $this->createStub(JitaMarketService::class);
        $jitaMarket->method('getCheapestPercentilePrices')->willReturnCallback(
            static fn (array $typeIds): array => array_combine($typeIds, array_map(
                static fn (int $typeId): float => self::HYDROGEN_FUEL_BLOCK === $typeId ? self::HYDROGEN_FUEL_BLOCK_JITA_PRICE : self::HYDROGEN_ISOTOPES_JITA_PRICE,
                $typeIds,
            )),
        );

        $this->service = new ProjectContributionService(
            $contributionRepository,
            $entityManager,
            $jitaMarket,
            $this->createStub(LineReservationService::class),
            $this->projectService,
            $this->createStub(ContributorPaidPurchaseReader::class),
        );

        $this->owner = $this->createStub(User::class);
        $this->reviewer = $this->createStub(User::class);
        $this->project = (new IndustryProject())->setOwner($this->owner);
        $this->project->enrolOwner();
        $this->ownerParticipant = $this->project->getMembers()->first();

        $this->hydrogenFuelBlock = (new IndustryProjectBomLine())
            ->setProject($this->project)
            ->setTypeId(self::HYDROGEN_FUEL_BLOCK)
            ->setTypeName('Hydrogen Fuel Block')
            ->setRequiredQuantity(120)
            ->setIsJob(true)
            ->setJobGroup('component')
            ->setActivityType('manufacturing')
            ->setRuns(3);
        $this->hydrogenIsotopes = (new IndustryProjectBomLine())
            ->setProject($this->project)
            ->setTypeId(self::HYDROGEN_ISOTOPES)
            ->setTypeName('Hydrogen Isotopes')
            ->setRequiredQuantity(1_215)
            ->setIsJob(false);
    }

    public function testApprovingAPendingIntermediateContributionRecalculatesTheBomForTheOwnerOfTheProjectAndRefusesToDropWorkedLines(): void
    {
        $pending = $this->contributionOn($this->hydrogenFuelBlock, ContributionType::Material, 30, ContributionStatus::Pending);
        $this->projectService->expects(self::once())->method('buildBom')->with($this->project, $this->owner, true);

        $this->service->approve($pending, $this->reviewer);
    }

    public function testRejectingAnApprovedIntermediateContributionThatDuplicatesADerivedOneRecalculatesTheBomForTheOwnerOfTheProject(): void
    {
        $declared = $this->contributionOn($this->hydrogenFuelBlock, ContributionType::Material, 30, ContributionStatus::Approved);
        $this->contributionOn($this->hydrogenFuelBlock, ContributionType::Material, 40, ContributionStatus::Approved)->setIsAutoDetected(true);
        $this->projectService->expects(self::once())->method('buildBom')->with($this->project, $this->owner, true);

        $this->service->reject($declared, $this->reviewer);
    }

    public function testRejectingAPendingIntermediateContributionRecalculatesNothing(): void
    {
        $pending = $this->contributionOn($this->hydrogenFuelBlock, ContributionType::Material, 30, ContributionStatus::Pending);
        $this->projectService->expects(self::never())->method('buildBom');

        $this->service->reject($pending, $this->reviewer);

        self::assertSame(ContributionStatus::Rejected, $pending->getStatus());
    }

    public function testMaterialContributionOnAMaterialLineRecalculatesNothing(): void
    {
        $this->projectService->expects(self::never())->method('buildBom');

        $contribution = $this->service->submit($this->ownerParticipant, $this->hydrogenIsotopes, ContributionType::Material, 500);

        self::assertSame([ContributionStatus::Approved, 500], [$contribution->getStatus(), $this->hydrogenIsotopes->getFulfilledQuantity()]);
    }

    public function testJobInstallContributionOnAJobLineRecalculatesNothing(): void
    {
        $this->projectService->expects(self::never())->method('buildBom');

        $contribution = $this->service->submit($this->ownerParticipant, $this->hydrogenFuelBlock, ContributionType::JobInstall, 3, 7_500.0);

        self::assertSame([ContributionStatus::Approved, 3], [$contribution->getStatus(), $this->hydrogenFuelBlock->getFulfilledQuantity()]);
    }

    public function testRefusalOfTheRecalculationIsReportedAsADomainExceptionWithItsReason(): void
    {
        $pending = $this->contributionOn($this->hydrogenFuelBlock, ContributionType::Material, 30, ContributionStatus::Pending);
        $this->projectService->method('buildBom')->willThrowException(new \RuntimeException('The line "Hydrogen Isotopes" has already received more than the plan now needs.'));

        $failure = null;
        try {
            $this->service->approve($pending, $this->reviewer);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\DomainException::class, $failure);
        self::assertStringContainsString('The line "Hydrogen Isotopes" has already received more than the plan now needs.', $failure->getMessage());
    }

    private function contributionOn(IndustryProjectBomLine $line, ContributionType $type, int $quantity, ContributionStatus $status): IndustryProjectContribution
    {
        $contribution = (new IndustryProjectContribution())
            ->setProject($this->project)
            ->setMember($this->ownerParticipant)
            ->setBomItem($line)
            ->setType($type)
            ->setQuantity($quantity)
            ->setEstimatedValue(0.0)
            ->setStatus($status);
        $this->project->addContribution($contribution);

        return $contribution;
    }
}
