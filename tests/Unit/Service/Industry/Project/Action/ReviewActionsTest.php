<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Action;

use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Service\Industry\Project\Action\ActionPlace;
use App\Service\Industry\Project\Action\PlacedAction;
use App\Service\Industry\Project\Action\PlacedActionCode;
use App\Service\Industry\Project\Action\ReviewContributionActions;
use App\Service\Industry\Project\Action\ReviewJoinRequestActions;
use App\Tests\Unit\State\Provider\Industry\Project\BuildsWorkshopProjects;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewContributionActions::class)]
#[CoversClass(ReviewJoinRequestActions::class)]
#[Group('issue-1300')]
final class ReviewActionsTest extends TestCase
{
    use BuildsWorkshopProjects;

    public function testOwnerIsAskedToAcceptTheMembersWhoseJoinRequestIsPending(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $owner);
        $this->membershipOf($project, $owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->membershipOf($project, $this->pilot(), ParticipantRole::Member, ParticipantStatus::Pending);
        $this->membershipOf($project, $this->pilot(), ParticipantRole::Member, ParticipantStatus::Pending);
        $this->membershipOf($project, $this->pilot(), ParticipantRole::Member, ParticipantStatus::Accepted);

        $actions = (new ReviewJoinRequestActions())->actionsOf($project, $owner);

        self::assertEquals(
            [new PlacedAction($project->getId()->toRfc4122(), 'Ishtar batch', PlacedActionCode::ReviewJoinRequests, null, null, null, 2, ActionPlace::anywhere())],
            $actions,
        );
    }

    public function testOwnerIsAskedToCheckTheDepositsThatArePending(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $owner);
        $this->membershipOf($project, $owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $contributor = $this->membershipOf($project, $this->pilot(), ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->contributionOf($project, $contributor, 3_000, ContributionStatus::Pending);
        $this->contributionOf($project, $contributor, 450, ContributionStatus::Approved);

        $actions = (new ReviewContributionActions())->actionsOf($project, $owner);

        self::assertEquals(
            [new PlacedAction($project->getId()->toRfc4122(), 'Ishtar batch', PlacedActionCode::ReviewContributions, null, null, null, 1, ActionPlace::anywhere())],
            $actions,
        );
    }

    public function testSimpleMemberIsNeverAskedToReviewAnything(): void
    {
        $owner = $this->pilot();
        $simpleMember = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $owner);
        $this->membershipOf($project, $owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->membershipOf($project, $simpleMember, ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->membershipOf($project, $this->pilot(), ParticipantRole::Member, ParticipantStatus::Pending);
        $contributor = $this->membershipOf($project, $this->pilot(), ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->contributionOf($project, $contributor, 3_000, ContributionStatus::Pending);

        self::assertSame([], $this->reviewActionsOf($project, $simpleMember));
    }

    public function testAcceptedAdminIsAskedToReviewButAnAdminStillPendingIsNot(): void
    {
        $owner = $this->pilot();
        $acceptedAdmin = $this->pilot();
        $pendingAdmin = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $owner);
        $this->membershipOf($project, $owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->membershipOf($project, $acceptedAdmin, ParticipantRole::Admin, ParticipantStatus::Accepted);
        $this->membershipOf($project, $pendingAdmin, ParticipantRole::Admin, ParticipantStatus::Pending);
        $contributor = $this->membershipOf($project, $this->pilot(), ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->contributionOf($project, $contributor, 3_000, ContributionStatus::Pending);

        self::assertSame(
            [[PlacedActionCode::ReviewContributions, 1], [PlacedActionCode::ReviewJoinRequests, 1]],
            $this->reviewActionsOf($project, $acceptedAdmin),
        );
        self::assertSame([], $this->reviewActionsOf($project, $pendingAdmin));
    }

    public function testNothingPendingMeansNoReviewAction(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $owner);
        $this->membershipOf($project, $owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $contributor = $this->membershipOf($project, $this->pilot(), ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->contributionOf($project, $contributor, 3_000, ContributionStatus::Approved);

        self::assertSame([], $this->reviewActionsOf($project, $owner));
    }

    public function testCompletedProjectAsksForNoReview(): void
    {
        $owner = $this->pilot();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::Completed, $owner);
        $this->membershipOf($project, $owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->membershipOf($project, $this->pilot(), ParticipantRole::Member, ParticipantStatus::Pending);
        $contributor = $this->membershipOf($project, $this->pilot(), ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->contributionOf($project, $contributor, 3_000, ContributionStatus::Pending);

        self::assertSame([], $this->reviewActionsOf($project, $owner));
    }

    private function reviewActionsOf(IndustryProject $project, User $reader): array
    {
        return array_map(
            static fn (PlacedAction $action): array => [$action->code, $action->quantity],
            [
                ...(new ReviewContributionActions())->actionsOf($project, $reader),
                ...(new ReviewJoinRequestActions())->actionsOf($project, $reader),
            ],
        );
    }
}
