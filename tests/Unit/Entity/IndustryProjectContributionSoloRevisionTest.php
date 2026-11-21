<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProjectContribution::class)]
#[Group('issue-1159')]
final class IndustryProjectContributionSoloRevisionTest extends TestCase
{
    private IndustryProject $project;
    private IndustryProjectParticipant $ownerMembership;
    private IndustryProjectBomLine $line;

    protected function setUp(): void
    {
        $this->project = (new IndustryProject())->setOwner(new User());
        $this->project->enrolOwner();
        $this->project->setStatus(ProjectStatus::InProgress);
        $this->ownerMembership = $this->project->getMembers()->first();
        $this->line = (new IndustryProjectBomLine())->setTypeId(38)->setTypeName('Nocxium')->setRequiredQuantity(100)->setIsJob(false)->setFulfilledQuantity(100);
        $this->project->addBomItem($this->line);
    }

    public function testAnApprovedMaterialContributionOfASoloProjectCanBeRevisedByItsContributor(): void
    {
        self::assertTrue($this->contribution(ContributionStatus::Approved)->isEditableByItsContributor());
    }

    public function testAPendingContributionStaysEditableByItsContributor(): void
    {
        self::assertTrue($this->contribution(ContributionStatus::Pending)->isEditableByItsContributor());
    }

    public function testARejectedContributionIsNotEditable(): void
    {
        self::assertFalse($this->contribution(ContributionStatus::Rejected)->isEditableByItsContributor());
    }

    public function testAnApprovedContributionIsLockedOnceAnotherMemberHasJoined(): void
    {
        $contribution = $this->contribution(ContributionStatus::Approved);
        $this->project->addMember((new IndustryProjectParticipant())->setUser(new User())->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted));

        self::assertFalse($contribution->isEditableByItsContributor());
    }

    public function testAnApprovedContributionIsLockedOnceTheProjectIsSelling(): void
    {
        $contribution = $this->contribution(ContributionStatus::Approved);
        $this->project->setStatus(ProjectStatus::Selling);

        self::assertFalse($contribution->isEditableByItsContributor());
    }

    public function testAnApprovedContributionIsLockedOnceTheProjectIsCompleted(): void
    {
        $contribution = $this->contribution(ContributionStatus::Approved);
        $this->project->setStatus(ProjectStatus::Completed);

        self::assertFalse($contribution->isEditableByItsContributor());
    }

    public function testAnApprovedAutoDetectedContributionIsNotRevisable(): void
    {
        self::assertFalse($this->contribution(ContributionStatus::Approved)->setIsAutoDetected(true)->isEditableByItsContributor());
    }

    public function testAnApprovedContributionOfAnotherTypeIsNotRevisable(): void
    {
        self::assertFalse($this->contribution(ContributionStatus::Approved)->setType(ContributionType::JobInstall)->isEditableByItsContributor());
    }

    private function contribution(ContributionStatus $status): IndustryProjectContribution
    {
        $contribution = (new IndustryProjectContribution())
            ->setProject($this->project)
            ->setMember($this->ownerMembership)
            ->setBomItem($this->line)
            ->setType(ContributionType::Material)
            ->setQuantity(100)
            ->setEstimatedValue(600.0)
            ->setStatus($status)
            ->setIsAutoDetected(false);
        $this->project->addContribution($contribution);

        return $contribution;
    }
}
