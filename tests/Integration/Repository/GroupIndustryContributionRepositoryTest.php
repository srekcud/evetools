<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\GroupIndustryContributionRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-156')]
final class GroupIndustryContributionRepositoryTest extends IntegrationTestCase
{
    private GroupIndustryContributionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(GroupIndustryContributionRepository::class);
    }

    public function testApprovedValueOfEachProjectAddsOnlyItsApprovedContributionsOfEveryMemberAndType(): void
    {
        $sabre = $this->createProject('Sabre batch');
        $sabreOwner = $this->addMember($sabre, GroupMemberRole::Owner);
        $sabreAdmin = $this->addMember($sabre, GroupMemberRole::Admin);
        $this->addContribution($sabreOwner, ContributionType::Material, 1_000_000.0, ContributionStatus::Approved);
        $this->addContribution($sabreAdmin, ContributionType::LineRental, 250_000.0, ContributionStatus::Approved);
        $this->addContribution($sabreAdmin, ContributionType::Material, 500_000.0, ContributionStatus::Pending);
        $this->addContribution($sabreOwner, ContributionType::JobInstall, 100_000.0, ContributionStatus::Rejected);

        $jackdaw = $this->createProject('Jackdaw batch');
        $jackdawOwner = $this->addMember($jackdaw, GroupMemberRole::Owner);
        $this->addContribution($jackdawOwner, ContributionType::Bpc, 820_000.0, ContributionStatus::Approved);
        $this->addContribution($jackdawOwner, ContributionType::JobInstall, 37_500.0, ContributionStatus::Approved);

        $this->em->flush();

        $expected = [(string) $sabre->getId() => 1_250_000.0, (string) $jackdaw->getId() => 857_500.0];
        ksort($expected);
        $approvedValues = $this->repository->sumApprovedValueByProject([$sabre, $jackdaw]);
        ksort($approvedValues);

        self::assertSame($expected, $approvedValues);
    }

    public function testProjectWithoutApprovedContributionHasAnApprovedValueOfZero(): void
    {
        $ishtar = $this->createProject('Ishtar fleet');
        $ishtarOwner = $this->addMember($ishtar, GroupMemberRole::Owner);
        $this->addContribution($ishtarOwner, ContributionType::Material, 500_000.0, ContributionStatus::Pending);
        $this->addContribution($ishtarOwner, ContributionType::Material, 100_000.0, ContributionStatus::Rejected);
        $hurricane = $this->createProject('Hurricane line');
        $this->addMember($hurricane, GroupMemberRole::Owner);
        $this->em->flush();

        $expected = [(string) $ishtar->getId() => 0.0, (string) $hurricane->getId() => 0.0];
        ksort($expected);
        $approvedValues = $this->repository->sumApprovedValueByProject([$ishtar, $hurricane]);
        ksort($approvedValues);

        self::assertSame($expected, $approvedValues);
    }

    public function testApprovedValueIsReadOnlyForTheGivenProjects(): void
    {
        $sabre = $this->createProject('Sabre batch');
        $this->addContribution($this->addMember($sabre, GroupMemberRole::Owner), ContributionType::Material, 2_400_000.0, ContributionStatus::Approved);
        $otherProject = $this->createProject('Other project');
        $this->addContribution($this->addMember($otherProject, GroupMemberRole::Owner), ContributionType::Material, 9_900_000.0, ContributionStatus::Approved);
        $this->em->flush();

        self::assertSame(
            [(string) $sabre->getId() => 2_400_000.0],
            $this->repository->sumApprovedValueByProject([$sabre]),
        );
    }

    public function testNoProjectGivesNoApprovedValue(): void
    {
        self::assertSame([], $this->repository->sumApprovedValueByProject([]));
    }

    private function createProject(string $name): GroupIndustryProject
    {
        $project = (new GroupIndustryProject())->setOwner($this->createUser())->setName($name);
        $this->em->persist($project);

        return $project;
    }

    private function addMember(GroupIndustryProject $project, GroupMemberRole $role): GroupIndustryProjectMember
    {
        $member = (new GroupIndustryProjectMember())
            ->setUser($role === GroupMemberRole::Owner ? $project->getOwner() : $this->createUser())
            ->setRole($role)
            ->setStatus(GroupMemberStatus::Accepted);
        $project->addMember($member);

        return $member;
    }

    private function addContribution(GroupIndustryProjectMember $member, ContributionType $type, float $estimatedValue, ContributionStatus $status): void
    {
        $member->getProject()->addContribution(
            (new GroupIndustryContribution())
                ->setMember($member)
                ->setType($type)
                ->setQuantity(1)
                ->setEstimatedValue($estimatedValue)
                ->setStatus($status),
        );
    }
}
