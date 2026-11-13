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
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectContributionService::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-726')]
final class ProjectContributionServiceSoleReviewingOwnerTest extends TestCase
{
    private const int REQUIRED_QUANTITY = 10_000;
    private const int CONTRIBUTED_QUANTITY = 1_500;
    private const float JOB_INSTALL_VALUE = 250_000.0;

    private ProjectContributionService $service;
    private User $owner;
    private IndustryProject $project;
    private IndustryProjectBomLine $line;
    private IndustryProjectParticipant $ownerMember;

    protected function setUp(): void
    {
        $contributionRepository = $this->createStub(IndustryProjectContributionRepository::class);
        $contributionRepository->method('findByMemberBomItemAndType')->willReturn(null);

        $this->service = new ProjectContributionService(
            $contributionRepository,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(JitaMarketService::class),
            $this->createStub(LineReservationService::class),
            $this->createStub(IndustryProjectService::class),
            $this->createStub(ContributorPaidPurchaseReader::class),
        );

        $this->owner = $this->createStub(User::class);
        $this->project = new IndustryProject();
        $this->project->setOwner($this->owner);
        $this->ownerMember = $this->addMember($this->owner, ParticipantRole::Owner);

        $this->line = new IndustryProjectBomLine();
        $this->line->setProject($this->project);
        $this->line->setTypeId(34);
        $this->line->setTypeName('Tritanium');
        $this->line->setRequiredQuantity(self::REQUIRED_QUANTITY);
    }

    public function testOwnerOfAGroupProjectWithNoOtherAcceptedReviewerIsApprovedAtSubmission(): void
    {
        $this->addMember($this->createStub(User::class), ParticipantRole::Member);

        $contribution = $this->submitByTheOwner();

        self::assertSame(
            [ContributionStatus::Approved, $this->owner, self::CONTRIBUTED_QUANTITY],
            [$contribution->getStatus(), $contribution->getReviewedBy(), $this->line->getFulfilledQuantity()],
        );
    }

    public function testOwnerWhoseOnlyAdminHasNotAcceptedYetIsStillApprovedAtSubmission(): void
    {
        $this->addMember($this->createStub(User::class), ParticipantRole::Admin, ParticipantStatus::Pending);

        $contribution = $this->submitByTheOwner();

        self::assertSame(
            [ContributionStatus::Approved, self::CONTRIBUTED_QUANTITY],
            [$contribution->getStatus(), $this->line->getFulfilledQuantity()],
        );
    }

    public function testOwnerContributionStaysPendingWhenAnotherAdminIsAccepted(): void
    {
        $this->addMember($this->createStub(User::class), ParticipantRole::Admin);

        $contribution = $this->submitByTheOwner();

        self::assertSame(
            [ContributionStatus::Pending, null, 0],
            [$contribution->getStatus(), $contribution->getReviewedBy(), $this->line->getFulfilledQuantity()],
        );
    }

    public function testMemberContributionStaysPendingEvenWhenTheOwnerIsTheOnlyReviewer(): void
    {
        $memberUser = $this->createStub(User::class);
        $member = $this->addMember($memberUser, ParticipantRole::Member);

        $contribution = $this->service->submit($member, $this->line, ContributionType::JobInstall, self::CONTRIBUTED_QUANTITY, self::JOB_INSTALL_VALUE);

        self::assertSame(
            [ContributionStatus::Pending, 0],
            [$contribution->getStatus(), $this->line->getFulfilledQuantity()],
        );
    }

    public function testAdminContributionStaysPendingEvenWhenTheOwnerIsTheOnlyOtherReviewer(): void
    {
        $adminUser = $this->createStub(User::class);
        $admin = $this->addMember($adminUser, ParticipantRole::Admin);

        $contribution = $this->service->submit($admin, $this->line, ContributionType::JobInstall, self::CONTRIBUTED_QUANTITY, self::JOB_INSTALL_VALUE);

        self::assertSame(ContributionStatus::Pending, $contribution->getStatus());
    }

    private function submitByTheOwner(): IndustryProjectContribution
    {
        return $this->service->submit($this->ownerMember, $this->line, ContributionType::JobInstall, self::CONTRIBUTED_QUANTITY, self::JOB_INSTALL_VALUE);
    }

    private function addMember(User $user, ParticipantRole $role, ParticipantStatus $status = ParticipantStatus::Accepted): IndustryProjectParticipant
    {
        $member = new IndustryProjectParticipant();
        $member->setUser($user);
        $member->setRole($role);
        $member->setStatus($status);
        $this->project->addMember($member);

        return $member;
    }
}
