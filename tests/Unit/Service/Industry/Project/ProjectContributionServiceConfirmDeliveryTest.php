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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectContributionService::class)]
#[Group('issue-953')]
#[AllowMockObjectsWithoutExpectations]
final class ProjectContributionServiceConfirmDeliveryTest extends TestCase
{
    private const int PROPOSED_QUANTITY = 40;
    private const int FULFILLED_QUANTITY = 100;

    private ProjectContributionService $service;
    private LineReservationService&MockObject $lineReservations;
    private EntityManagerInterface&MockObject $entityManager;
    private IndustryProject $project;
    private IndustryProjectBomLine $line;
    private IndustryProjectParticipant $contributor;

    protected function setUp(): void
    {
        $this->lineReservations = $this->createMock(LineReservationService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->service = new ProjectContributionService(
            $this->createStub(IndustryProjectContributionRepository::class),
            $this->entityManager,
            $this->createStub(JitaMarketService::class),
            $this->lineReservations,
            $this->createStub(IndustryProjectService::class),
            $this->createStub(ContributorPaidPurchaseReader::class),
        );

        $owner = new User();
        $this->project = (new IndustryProject())->setOwner($owner);
        $this->project->enrolOwner();
        $this->project->setStatus(ProjectStatus::InProgress);
        $this->contributor = (new IndustryProjectParticipant())->setUser(new User())->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted);
        $this->project->addMember($this->contributor);
        $this->line = (new IndustryProjectBomLine())->setTypeId(35)->setTypeName('Pyerite')->setRequiredQuantity(1000)->setIsJob(false)->setFulfilledQuantity(self::FULFILLED_QUANTITY);
        $this->project->addBomItem($this->line);
    }

    public function testConfirmingADeliveryApprovesTheProposalWithoutAReviewer(): void
    {
        $proposal = $this->proposal();
        $before = new \DateTimeImmutable();

        $this->service->confirmDelivery($proposal);

        $after = new \DateTimeImmutable();
        $this->assertSame(ContributionStatus::Approved, $proposal->getStatus());
        $this->assertNull($proposal->getReviewedBy());
        $this->assertNotNull($proposal->getReviewedAt());
        $this->assertGreaterThanOrEqual($before, $proposal->getReviewedAt());
        $this->assertLessThanOrEqual($after, $proposal->getReviewedAt());
    }

    public function testConfirmingADeliveryMarksTheProposalVerifiedNow(): void
    {
        $proposal = $this->proposal();
        $before = new \DateTimeImmutable();

        $this->service->confirmDelivery($proposal);

        $after = new \DateTimeImmutable();
        $this->assertTrue($proposal->isVerified());
        $this->assertNotNull($proposal->getVerifiedAt());
        $this->assertGreaterThanOrEqual($before, $proposal->getVerifiedAt());
        $this->assertLessThanOrEqual($after, $proposal->getVerifiedAt());
    }

    public function testConfirmingADeliveryAddsTheQuantityToTheLineAndSettlesItsReservations(): void
    {
        $proposal = $this->proposal();
        $this->lineReservations->expects($this->once())->method('settleApproval')->with($this->line, $this->contributor, self::PROPOSED_QUANTITY);

        $this->service->confirmDelivery($proposal);

        $this->assertSame(self::FULFILLED_QUANTITY + self::PROPOSED_QUANTITY, $this->line->getFulfilledQuantity());
    }

    public function testConfirmingADeliveryLeavesTheFlushToTheCaller(): void
    {
        $this->entityManager->expects($this->never())->method('flush');

        $this->service->confirmDelivery($this->proposal());
    }

    #[DataProvider('statusesOtherThanPending')]
    public function testOnlyAPendingProposalCanBeConfirmed(ContributionStatus $status): void
    {
        $proposal = $this->proposal()->setStatus($status);
        $this->lineReservations->expects($this->never())->method('settleApproval');

        try {
            $this->service->confirmDelivery($proposal);
            $this->fail('A contribution that is not pending cannot be confirmed.');
        } catch (\DomainException) {
            $this->assertSame($status, $proposal->getStatus());
            $this->assertFalse($proposal->isVerified());
            $this->assertNull($proposal->getVerifiedAt());
            $this->assertSame(self::FULFILLED_QUANTITY, $this->line->getFulfilledQuantity());
        }
    }

    /**
     * @return iterable<string, array{ContributionStatus}>
     */
    public static function statusesOtherThanPending(): iterable
    {
        yield 'approved' => [ContributionStatus::Approved];
        yield 'rejected' => [ContributionStatus::Rejected];
    }

    public function testAManualApprovalStillLeavesTheContributionUnverified(): void
    {
        $proposal = $this->proposal();
        $reviewer = new User();

        $this->service->approve($proposal, $reviewer);

        $this->assertSame([ContributionStatus::Approved, $reviewer, false, null], [$proposal->getStatus(), $proposal->getReviewedBy(), $proposal->isVerified(), $proposal->getVerifiedAt()]);
    }

    private function proposal(): IndustryProjectContribution
    {
        $proposal = (new IndustryProjectContribution())
            ->setProject($this->project)
            ->setMember($this->contributor)
            ->setBomItem($this->line)
            ->setType(ContributionType::Material)
            ->setQuantity(self::PROPOSED_QUANTITY)
            ->setEstimatedValue(280.0)
            ->setStatus(ContributionStatus::Pending)
            ->setIsAutoDetected(true);
        $this->project->addContribution($proposal);

        return $proposal;
    }
}
