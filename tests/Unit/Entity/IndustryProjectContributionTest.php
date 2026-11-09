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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProjectContribution::class)]
#[Group('issue-510')]
final class IndustryProjectContributionTest extends TestCase
{
    private IndustryProject $project;
    private IndustryProjectParticipant $ownerParticipant;
    private IndustryProjectParticipant $otherParticipant;
    private IndustryProjectBomLine $tritaniumLine;
    private IndustryProjectBomLine $pyeriteLine;

    protected function setUp(): void
    {
        $this->project = (new IndustryProject())->setOwner(new User());
        $this->project->enrolOwner();
        $this->ownerParticipant = $this->project->getMembers()->first();
        $this->otherParticipant = (new IndustryProjectParticipant())
            ->setUser(new User())
            ->setRole(ParticipantRole::Member)
            ->setStatus(ParticipantStatus::Accepted);
        $this->project->addMember($this->otherParticipant);
        $this->tritaniumLine = $this->lineOf(34, 'Tritanium');
        $this->pyeriteLine = $this->lineOf(35, 'Pyerite');
    }

    public function testApprovedDeclaredContributionDuplicatesTheDerivedOneOfTheSameParticipantLineAndType(): void
    {
        $declared = $this->contribution($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, 400, ContributionStatus::Approved, false);
        $this->contribution($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, 600, ContributionStatus::Approved, true);

        self::assertTrue($declared->duplicatesDerived());
    }

    public function testApprovedDeclaredContributionWithoutDerivedTwinDuplicatesNothing(): void
    {
        $declared = $this->contribution($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, 400, ContributionStatus::Approved, false);

        self::assertFalse($declared->duplicatesDerived());
    }

    public function testDerivedContributionNeverDuplicatesItself(): void
    {
        $derived = $this->contribution($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, 600, ContributionStatus::Approved, true);

        self::assertFalse($derived->duplicatesDerived());
    }

    public function testPendingDeclaredContributionIsNotYetADuplicate(): void
    {
        $declared = $this->contribution($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, 400, ContributionStatus::Pending, false);
        $this->contribution($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, 600, ContributionStatus::Approved, true);

        self::assertFalse($declared->duplicatesDerived());
    }

    public function testRejectedDeclaredContributionIsNoLongerADuplicate(): void
    {
        $declared = $this->contribution($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, 400, ContributionStatus::Rejected, false);
        $this->contribution($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, 600, ContributionStatus::Approved, true);

        self::assertFalse($declared->duplicatesDerived());
    }

    public function testDerivedContributionOfAnotherParticipantMakesNoDuplicate(): void
    {
        $declared = $this->contribution($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, 400, ContributionStatus::Approved, false);
        $this->contribution($this->otherParticipant, $this->tritaniumLine, ContributionType::Material, 600, ContributionStatus::Approved, true);

        self::assertFalse($declared->duplicatesDerived());
    }

    public function testDerivedContributionOnAnotherLineMakesNoDuplicate(): void
    {
        $declared = $this->contribution($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, 400, ContributionStatus::Approved, false);
        $this->contribution($this->ownerParticipant, $this->pyeriteLine, ContributionType::Material, 600, ContributionStatus::Approved, true);

        self::assertFalse($declared->duplicatesDerived());
    }

    public function testDerivedContributionOfAnotherTypeMakesNoDuplicate(): void
    {
        $declared = $this->contribution($this->ownerParticipant, $this->tritaniumLine, ContributionType::Material, 400, ContributionStatus::Approved, false);
        $this->contribution($this->ownerParticipant, $this->tritaniumLine, ContributionType::JobInstall, 3, ContributionStatus::Approved, true);

        self::assertFalse($declared->duplicatesDerived());
    }

    private function lineOf(int $typeId, string $typeName): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity(1_000)
            ->setIsJob(false);
        $this->project->addBomItem($line);

        return $line;
    }

    private function contribution(
        IndustryProjectParticipant $member,
        IndustryProjectBomLine $line,
        ContributionType $type,
        int $quantity,
        ContributionStatus $status,
        bool $isAutoDetected,
    ): IndustryProjectContribution {
        $contribution = (new IndustryProjectContribution())
            ->setProject($this->project)
            ->setMember($member)
            ->setBomItem($line)
            ->setType($type)
            ->setQuantity($quantity)
            ->setStatus($status)
            ->setIsAutoDetected($isAutoDetected);
        $this->project->addContribution($contribution);

        return $contribution;
    }
}
