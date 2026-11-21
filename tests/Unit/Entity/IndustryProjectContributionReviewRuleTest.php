<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProject::class)]
#[Group('issue-1021')]
final class IndustryProjectContributionReviewRuleTest extends TestCase
{
    private User $owner;
    private User $admin;
    private User $contributor;
    private IndustryProject $project;
    private IndustryProjectParticipant $ownerMembership;
    private IndustryProjectParticipant $adminMembership;
    private IndustryProjectParticipant $contributorMembership;

    protected function setUp(): void
    {
        $this->owner = new User();
        $this->admin = new User();
        $this->contributor = new User();
        $this->project = (new IndustryProject())->setOwner($this->owner);
        $this->ownerMembership = $this->membership($this->owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->contributorMembership = $this->membership($this->contributor, ParticipantRole::Member, ParticipantStatus::Accepted);
    }

    public function testManagerCanReviewAnotherMembersContribution(): void
    {
        $this->membership($this->admin, ParticipantRole::Admin, ParticipantStatus::Accepted);
        $contribution = $this->addContribution($this->contributorMembership, ContributionStatus::Pending);

        self::assertTrue($this->project->canReviewContribution($contribution, $this->owner));
        self::assertTrue($this->project->canReviewContribution($contribution, $this->admin));
    }

    public function testOwnerCannotReviewOwnContributionWhileAnotherAcceptedAdminCould(): void
    {
        $this->membership($this->admin, ParticipantRole::Admin, ParticipantStatus::Accepted);
        $contribution = $this->addContribution($this->ownerMembership, ContributionStatus::Pending);

        self::assertFalse($this->project->canReviewContribution($contribution, $this->owner));
        self::assertTrue($this->project->canReviewContribution($contribution, $this->admin));
    }

    public function testOwnerCanReviewOwnContributionWhenNobodyElseCouldReview(): void
    {
        $contribution = $this->addContribution($this->ownerMembership, ContributionStatus::Pending);

        self::assertTrue($this->project->canReviewContribution($contribution, $this->owner));
    }

    public function testPendingAdminDoesNotPreventTheOwnerFromReviewingOwnContribution(): void
    {
        $this->membership($this->admin, ParticipantRole::Admin, ParticipantStatus::Pending);
        $contribution = $this->addContribution($this->ownerMembership, ContributionStatus::Pending);

        self::assertTrue($this->project->canReviewContribution($contribution, $this->owner));
    }

    public function testCountOnlyIncludesPendingContributionsTheViewerMayReview(): void
    {
        $adminMembership = $this->membership($this->admin, ParticipantRole::Admin, ParticipantStatus::Accepted);
        $this->addContribution($this->ownerMembership, ContributionStatus::Pending);
        $this->addContribution($this->ownerMembership, ContributionStatus::Pending);
        $this->addContribution($this->contributorMembership, ContributionStatus::Pending);
        $this->addContribution($adminMembership, ContributionStatus::Pending);
        $this->addContribution($this->contributorMembership, ContributionStatus::Approved);
        $this->addContribution($this->contributorMembership, ContributionStatus::Rejected);

        self::assertSame(2, $this->project->pendingContributionCountReviewableBy($this->owner));
        self::assertSame(3, $this->project->pendingContributionCountReviewableBy($this->admin));
    }

    public function testSoleReviewingOwnerCountsEveryPendingContributionIncludingOwn(): void
    {
        $this->addContribution($this->ownerMembership, ContributionStatus::Pending);
        $this->addContribution($this->contributorMembership, ContributionStatus::Pending);

        self::assertSame(2, $this->project->pendingContributionCountReviewableBy($this->owner));
    }

    #[Group('issue-1176')]
    public function testContributionAwaitingWalletUnitsIsNotCountedToReview(): void
    {
        $this->addContribution($this->ownerMembership, ContributionStatus::Pending)->setAwaitingQuantity(40);

        self::assertSame(0, $this->project->pendingContributionCountReviewableBy($this->owner));
    }

    #[Group('issue-1176')]
    public function testOnlyTheContributionWithoutAwaitedUnitsIsCounted(): void
    {
        $this->addContribution($this->ownerMembership, ContributionStatus::Pending);
        $this->addContribution($this->ownerMembership, ContributionStatus::Pending)->setAwaitingQuantity(40);

        self::assertSame(1, $this->project->pendingContributionCountReviewableBy($this->owner));
    }

    #[Group('issue-1176')]
    public function testContributionIsCountedAgainOnceTheWalletHasSeenEveryUnit(): void
    {
        $contribution = $this->addContribution($this->ownerMembership, ContributionStatus::Pending)->setAwaitingQuantity(40);
        $contribution->setAwaitingQuantity(0);

        self::assertSame(1, $this->project->pendingContributionCountReviewableBy($this->owner));
    }

    private function membership(User $user, ParticipantRole $role, ParticipantStatus $status): IndustryProjectParticipant
    {
        $membership = (new IndustryProjectParticipant())->setUser($user)->setRole($role)->setStatus($status);
        $this->project->addMember($membership);

        return $membership;
    }

    private function addContribution(IndustryProjectParticipant $member, ContributionStatus $status): IndustryProjectContribution
    {
        $contribution = (new IndustryProjectContribution())
            ->setMember($member)
            ->setType(ContributionType::Material)
            ->setQuantity(1)
            ->setEstimatedValue(1_000.0)
            ->setStatus($status);
        $this->project->addContribution($contribution);

        return $contribution;
    }
}
