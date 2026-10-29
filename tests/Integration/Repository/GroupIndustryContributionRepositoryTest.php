<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\GroupIndustryBomItem;
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

    #[Group('issue-257')]
    public function testContributedBomItemIdsAreTheLinesOfTheProjectCarryingAContributionOfAnyStatusEachOnce(): void
    {
        $sabre = $this->createProject('Sabre batch');
        $sabreOwner = $this->addMember($sabre, GroupMemberRole::Owner);
        $sabreAdmin = $this->addMember($sabre, GroupMemberRole::Admin);
        $tritanium = $this->addBomLine($sabre, 34, 'Tritanium', 120_000);
        $pyerite = $this->addBomLine($sabre, 35, 'Pyerite', 45_000);
        $mexallon = $this->addBomLine($sabre, 36, 'Mexallon', 9_600);
        $this->addBomLine($sabre, 37, 'Isogen', 2_700);
        $this->addContributionOn($sabreOwner, $tritanium, ContributionStatus::Approved);
        $this->addContributionOn($sabreOwner, $pyerite, ContributionStatus::Pending);
        $this->addContributionOn($sabreAdmin, $pyerite, ContributionStatus::Rejected);
        $this->addContributionOn($sabreAdmin, $mexallon, ContributionStatus::Rejected);
        $this->addContribution($sabreAdmin, ContributionType::LineRental, 250_000.0, ContributionStatus::Approved);
        $this->em->flush();

        $expected = [$tritanium->getId()->toRfc4122(), $pyerite->getId()->toRfc4122(), $mexallon->getId()->toRfc4122()];
        sort($expected);
        $contributedBomItemIds = $this->repository->findContributedBomItemIds($sabre);
        sort($contributedBomItemIds);

        self::assertSame($expected, $contributedBomItemIds);
    }

    #[Group('issue-257')]
    public function testContributedBomItemIdsAreReadOnlyForTheGivenProject(): void
    {
        $sabre = $this->createProject('Sabre batch');
        $morphite = $this->addBomLine($sabre, 11399, 'Morphite', 150);
        $this->addContributionOn($this->addMember($sabre, GroupMemberRole::Owner), $morphite, ContributionStatus::Pending);
        $jackdaw = $this->createProject('Jackdaw batch');
        $zydrine = $this->addBomLine($jackdaw, 39, 'Zydrine', 820);
        $this->addContributionOn($this->addMember($jackdaw, GroupMemberRole::Owner), $zydrine, ContributionStatus::Approved);
        $this->em->flush();

        self::assertSame([$morphite->getId()->toRfc4122()], $this->repository->findContributedBomItemIds($sabre));
    }

    #[Group('issue-257')]
    public function testProjectWhoseOnlyContributionHasNoBomLineGivesNoContributedBomItemId(): void
    {
        $ishtar = $this->createProject('Ishtar fleet');
        $this->addBomLine($ishtar, 38, 'Nocxium', 1_350);
        $this->addContribution($this->addMember($ishtar, GroupMemberRole::Owner), ContributionType::LineRental, 400_000.0, ContributionStatus::Approved);
        $this->em->flush();

        self::assertSame([], $this->repository->findContributedBomItemIds($ishtar));
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

    private function addBomLine(GroupIndustryProject $project, int $typeId, string $typeName, int $requiredQuantity): GroupIndustryBomItem
    {
        $line = (new GroupIndustryBomItem())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($requiredQuantity)
            ->setIsJob(false);
        $project->addBomItem($line);

        return $line;
    }

    private function addContributionOn(GroupIndustryProjectMember $member, GroupIndustryBomItem $line, ContributionStatus $status): void
    {
        $member->getProject()->addContribution(
            (new GroupIndustryContribution())
                ->setMember($member)
                ->setBomItem($line)
                ->setType(ContributionType::Material)
                ->setQuantity($line->getRequiredQuantity())
                ->setEstimatedValue(0.0)
                ->setStatus($status),
        );
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
