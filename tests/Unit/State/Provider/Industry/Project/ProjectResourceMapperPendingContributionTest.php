<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\State\Provider\Industry\Project\ProjectResourceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectResourceMapper::class)]
#[Group('issue-329')]
final class ProjectResourceMapperPendingContributionTest extends TestCase
{
    private ProjectResourceMapper $mapper;
    private IndustryProject $project;
    private IndustryProjectParticipant $ownerMembership;
    private IndustryProjectParticipant $adminMembership;
    private IndustryProjectParticipant $plainMembership;

    protected function setUp(): void
    {
        $this->mapper = new ProjectResourceMapper();
        $this->project = (new IndustryProject())->setOwner(new User())->setName('Sabre batch');
        (new \ReflectionProperty(IndustryProject::class, 'id'))->setValue($this->project, Uuid::v4());
        $this->ownerMembership = $this->membership(ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->adminMembership = $this->membership(ParticipantRole::Admin, ParticipantStatus::Accepted);
        $this->plainMembership = $this->membership(ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->addContribution($this->plainMembership, ContributionStatus::Pending);
        $this->addContribution($this->plainMembership, ContributionStatus::Pending);
        $this->addContribution($this->plainMembership, ContributionStatus::Approved);
        $this->addContribution($this->adminMembership, ContributionStatus::Rejected);
        $this->addContribution($this->adminMembership, ContributionStatus::Pending);
    }

    public function testOwnerSeesEveryPendingContributionOfTheProject(): void
    {
        self::assertSame(3, $this->mapper->projectToResource($this->project, $this->ownerMembership)->pendingContributionCount);
    }

    public function testAdminSeesEveryPendingContributionOfTheProject(): void
    {
        self::assertSame(3, $this->mapper->projectToResource($this->project, $this->adminMembership)->pendingContributionCount);
    }

    public function testOwnerOfAProjectWithoutPendingContributionSeesZero(): void
    {
        $project = (new IndustryProject())->setOwner(new User());
        (new \ReflectionProperty(IndustryProject::class, 'id'))->setValue($project, Uuid::v4());
        $ownerMembership = (new IndustryProjectParticipant())->setUser(new User())->setRole(ParticipantRole::Owner)->setStatus(ParticipantStatus::Accepted);
        $project->addMember($ownerMembership);

        self::assertSame(0, $this->mapper->projectToResource($project, $ownerMembership)->pendingContributionCount);
    }

    public function testPlainMemberCannotReviewSoTheCountIsNotExposed(): void
    {
        self::assertNull($this->mapper->projectToResource($this->project, $this->plainMembership)->pendingContributionCount);
    }

    public function testPendingMembershipOfAnAdminRoleDoesNotExposeTheCount(): void
    {
        $pendingAdmin = $this->membership(ParticipantRole::Admin, ParticipantStatus::Pending);

        self::assertNull($this->mapper->projectToResource($this->project, $pendingAdmin)->pendingContributionCount);
    }

    public function testViewerWithoutMembershipDoesNotSeeTheCount(): void
    {
        self::assertNull($this->mapper->projectToResource($this->project, null)->pendingContributionCount);
    }

    public function testApprovedContributionValueIsStillComputedForTheOwner(): void
    {
        $this->addContribution($this->plainMembership, ContributionStatus::Approved);

        self::assertSame(2.0 * 1_000.0, $this->mapper->projectToResource($this->project, $this->ownerMembership)->approvedContributionValue);
    }

    private function membership(ParticipantRole $role, ParticipantStatus $status): IndustryProjectParticipant
    {
        $membership = (new IndustryProjectParticipant())->setUser(new User())->setRole($role)->setStatus($status);
        $this->project->addMember($membership);

        return $membership;
    }

    private function addContribution(IndustryProjectParticipant $member, ContributionStatus $status): void
    {
        $this->project->addContribution(
            (new IndustryProjectContribution())
                ->setMember($member)
                ->setType(ContributionType::Material)
                ->setQuantity(1)
                ->setEstimatedValue(1_000.0)
                ->setStatus($status),
        );
    }
}
