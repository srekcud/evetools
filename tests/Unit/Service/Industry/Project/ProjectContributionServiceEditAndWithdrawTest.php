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
#[Group('issue-1012')]
#[AllowMockObjectsWithoutExpectations]
final class ProjectContributionServiceEditAndWithdrawTest extends TestCase
{
    private const int TYPE_ID = 34;
    private const int REQUIRED_QUANTITY = 1000;
    private const int FULFILLED_QUANTITY = 700;
    private const int REMAINING_QUANTITY = 300;
    private const int PENDING_QUANTITY = 100;
    private const float PENDING_VALUE = 500.0;
    private const float JITA_UNIT_PRICE = 6.0;

    private ProjectContributionService $service;
    private EntityManagerInterface&MockObject $entityManager;
    private LineReservationService&MockObject $reservations;
    private IndustryProject $project;
    private IndustryProjectBomLine $line;
    private IndustryProjectParticipant $contributor;

    protected function setUp(): void
    {
        $jita = $this->createStub(JitaMarketService::class);
        $jita->method('getCheapestPercentilePrices')->willReturn([self::TYPE_ID => self::JITA_UNIT_PRICE]);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->reservations = $this->createMock(LineReservationService::class);

        $this->service = new ProjectContributionService(
            $this->createStub(IndustryProjectContributionRepository::class),
            $this->entityManager,
            $jita,
            $this->reservations,
            $this->createStub(IndustryProjectService::class),
            $this->createStub(ContributorPaidPurchaseReader::class),
        );

        $this->project = (new IndustryProject())->setOwner(new User());
        $this->project->enrolOwner();
        $this->project->setStatus(ProjectStatus::InProgress);
        $this->contributor = (new IndustryProjectParticipant())->setUser(new User())->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted);
        $this->project->addMember($this->contributor);
        $this->line = (new IndustryProjectBomLine())->setTypeId(self::TYPE_ID)->setTypeName('Tritanium')->setRequiredQuantity(self::REQUIRED_QUANTITY)->setIsJob(false)->setFulfilledQuantity(self::FULFILLED_QUANTITY);
        $this->project->addBomItem($this->line);
    }

    public function testEditingTheQuantityOfAPendingMaterialContributionRevaluesItAtTheDepositDayPrice(): void
    {
        $contribution = $this->pendingContribution(ContributionType::Material);

        $this->service->edit($contribution, 250, null, null);

        self::assertSame(250, $contribution->getQuantity());
        self::assertSame(250 * self::JITA_UNIT_PRICE, $contribution->getEstimatedValue());
        self::assertTrue($contribution->isValuedAtJitaPrice());
        self::assertSame(ContributionStatus::Pending, $contribution->getStatus());
        self::assertNull($contribution->getReviewedBy());
    }

    public function testEditingTheQuantityUpToTheRemainingQuantityIsAccepted(): void
    {
        $contribution = $this->pendingContribution(ContributionType::Material);

        $this->service->edit($contribution, self::REMAINING_QUANTITY, null, null);

        self::assertSame(self::REMAINING_QUANTITY, $contribution->getQuantity());
    }

    public function testEditingTheQuantityAboveTheRemainingQuantityIsRefusedAndNothingChanges(): void
    {
        $contribution = $this->pendingContribution(ContributionType::Material);
        $this->entityManager->expects(self::never())->method('flush');

        try {
            $this->service->edit($contribution, self::REMAINING_QUANTITY + 1, null, 'new note');
            self::fail('The contribution exceeds the remaining quantity of the line');
        } catch (\DomainException) {
            self::assertSame(self::PENDING_QUANTITY, $contribution->getQuantity());
            self::assertSame(self::PENDING_VALUE, $contribution->getEstimatedValue());
            self::assertSame('original note', $contribution->getNote());
        }
    }

    public function testEditingOnlyTheNoteKeepsTheQuantityAndTheValue(): void
    {
        $contribution = $this->pendingContribution(ContributionType::Material);

        $this->service->edit($contribution, null, null, 'bought on the station');

        self::assertSame('bought on the station', $contribution->getNote());
        self::assertSame(self::PENDING_QUANTITY, $contribution->getQuantity());
        self::assertSame(self::PENDING_VALUE, $contribution->getEstimatedValue());
    }

    public function testAnEmptyNoteClearsIt(): void
    {
        $contribution = $this->pendingContribution(ContributionType::Material);

        $this->service->edit($contribution, null, null, '');

        self::assertNull($contribution->getNote());
    }

    public function testAMaterialContributionTakesNoEnteredValue(): void
    {
        $contribution = $this->pendingContribution(ContributionType::Material);

        $this->expectException(\DomainException::class);

        $this->service->edit($contribution, null, 900.0, null);
    }

    public function testEditingTheEnteredValueOfAJobInstallContribution(): void
    {
        $contribution = $this->pendingContribution(ContributionType::JobInstall);

        $this->service->edit($contribution, null, 1200.0, null);

        self::assertSame(1200.0, $contribution->getEstimatedValue());
    }

    public function testTheEnteredValueOfAJobInstallContributionMustStayPositive(): void
    {
        $contribution = $this->pendingContribution(ContributionType::JobInstall);

        try {
            $this->service->edit($contribution, null, 0.0, null);
            self::fail('A job install value must be positive');
        } catch (\DomainException) {
            self::assertSame(self::PENDING_VALUE, $contribution->getEstimatedValue());
        }
    }

    public function testAnEditWithoutAnyChangeIsRefused(): void
    {
        $contribution = $this->pendingContribution(ContributionType::Material);

        $this->expectException(\DomainException::class);

        $this->service->edit($contribution, null, null, null);
    }

    public function testACourierContributionCannotBeEdited(): void
    {
        $contribution = $this->pendingContribution(ContributionType::Courier);

        $this->expectException(\DomainException::class);

        $this->service->edit($contribution, 5, null, null);
    }

    public function testAnApprovedOrRejectedContributionIsLockedForEdit(): void
    {
        foreach ([ContributionStatus::Approved, ContributionStatus::Rejected] as $status) {
            $contribution = $this->pendingContribution(ContributionType::Material)->setStatus($status);

            try {
                $this->service->edit($contribution, 50, null, null);
                self::fail($status->value);
            } catch (\DomainException) {
                self::assertSame(self::PENDING_QUANTITY, $contribution->getQuantity(), $status->value);
            }
        }
    }

    public function testAnAutoDetectedContributionCannotBeEdited(): void
    {
        $contribution = $this->pendingContribution(ContributionType::Material)->setIsAutoDetected(true);

        $this->expectException(\DomainException::class);

        $this->service->edit($contribution, 50, null, null);
    }

    public function testNothingCanBeEditedOnACompletedProject(): void
    {
        $contribution = $this->pendingContribution(ContributionType::Material);
        $this->project->setStatus(ProjectStatus::Completed);

        $this->expectException(\DomainException::class);

        $this->service->edit($contribution, 50, null, null);
    }

    public function testWithdrawingAPendingContributionRemovesItAndKeepsTheLineReservation(): void
    {
        $contribution = $this->pendingContribution(ContributionType::Material);
        $this->entityManager->expects(self::once())->method('remove')->with($contribution);
        $this->entityManager->expects(self::once())->method('flush');
        $this->reservations->expects(self::never())->method(self::anything());

        $this->service->withdraw($contribution);

        self::assertFalse($this->project->getContributions()->contains($contribution));
        self::assertSame(self::FULFILLED_QUANTITY, $this->line->getFulfilledQuantity());
    }

    public function testAnApprovedOrRejectedContributionCannotBeWithdrawn(): void
    {
        foreach ([ContributionStatus::Approved, ContributionStatus::Rejected] as $status) {
            $contribution = $this->pendingContribution(ContributionType::Material)->setStatus($status);
            $this->entityManager->expects(self::never())->method('remove');

            try {
                $this->service->withdraw($contribution);
                self::fail($status->value);
            } catch (\DomainException) {
                self::assertTrue($this->project->getContributions()->contains($contribution), $status->value);
            }
        }
    }

    public function testAnAutoDetectedContributionCannotBeWithdrawn(): void
    {
        $contribution = $this->pendingContribution(ContributionType::Material)->setIsAutoDetected(true);
        $this->entityManager->expects(self::never())->method('remove');

        $this->expectException(\DomainException::class);

        $this->service->withdraw($contribution);
    }

    public function testNothingCanBeWithdrawnOnACompletedProject(): void
    {
        $contribution = $this->pendingContribution(ContributionType::Material);
        $this->project->setStatus(ProjectStatus::Completed);

        $this->expectException(\DomainException::class);

        $this->service->withdraw($contribution);
    }

    private function pendingContribution(ContributionType $type): IndustryProjectContribution
    {
        $contribution = (new IndustryProjectContribution())
            ->setProject($this->project)
            ->setMember($this->contributor)
            ->setBomItem($this->line)
            ->setType($type)
            ->setQuantity(self::PENDING_QUANTITY)
            ->setEstimatedValue(self::PENDING_VALUE)
            ->setStatus(ContributionStatus::Pending)
            ->setNote('original note');
        $this->project->addContribution($contribution);

        return $contribution;
    }
}
