<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\State\Provider\Industry\Project\ProjectResourceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectResourceMapper::class)]
#[Group('issue-510')]
final class ProjectResourceMapperDuplicateContributionTest extends TestCase
{
    private ProjectResourceMapper $mapper;
    private IndustryProject $project;
    private IndustryProjectParticipant $ownerParticipant;
    private IndustryProjectBomLine $tritaniumLine;

    protected function setUp(): void
    {
        $this->mapper = new ProjectResourceMapper();
        $this->project = (new IndustryProject())->setOwner(new User());
        $this->project->enrolOwner();
        $this->ownerParticipant = $this->project->getMembers()->first();
        $this->tritaniumLine = (new IndustryProjectBomLine())->setTypeId(34)->setTypeName('Tritanium')->setRequiredQuantity(1_000)->setIsJob(false);
        $this->project->addBomItem($this->tritaniumLine);
    }

    public function testApprovedDeclaredContributionDoublingADerivedOneIsFlaggedAsDuplicate(): void
    {
        $declared = $this->contribution(400, ContributionStatus::Approved, false);
        $this->contribution(600, ContributionStatus::Approved, true);

        self::assertTrue($this->mapper->contributionToResource($declared)->duplicatesDerived);
    }

    public function testDerivedContributionIsNotFlaggedAsDuplicate(): void
    {
        $this->contribution(400, ContributionStatus::Approved, false);
        $derived = $this->contribution(600, ContributionStatus::Approved, true);

        self::assertFalse($this->mapper->contributionToResource($derived)->duplicatesDerived);
    }

    public function testApprovedDeclaredContributionWithoutDerivedTwinIsNotFlaggedAsDuplicate(): void
    {
        $declared = $this->contribution(400, ContributionStatus::Approved, false);

        self::assertFalse($this->mapper->contributionToResource($declared)->duplicatesDerived);
    }

    private function contribution(int $quantity, ContributionStatus $status, bool $isAutoDetected): IndustryProjectContribution
    {
        $contribution = (new IndustryProjectContribution())
            ->setProject($this->project)
            ->setMember($this->ownerParticipant)
            ->setBomItem($this->tritaniumLine)
            ->setType(ContributionType::Material)
            ->setQuantity($quantity)
            ->setEstimatedValue(900.0)
            ->setStatus($status)
            ->setIsAutoDetected($isAutoDetected);
        (new \ReflectionProperty(IndustryProjectContribution::class, 'id'))->setValue($contribution, Uuid::v4());
        $this->project->addContribution($contribution);

        return $contribution;
    }
}
