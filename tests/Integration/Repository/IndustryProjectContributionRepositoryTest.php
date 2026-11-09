<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectContributionRepository;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-156')]
final class IndustryProjectContributionRepositoryTest extends IntegrationTestCase
{
    private IndustryProjectContributionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(IndustryProjectContributionRepository::class);
    }

    public function testApprovedValueOfEachProjectAddsOnlyItsApprovedContributionsOfEveryMemberAndType(): void
    {
        $sabre = $this->createProject('Sabre batch');
        $sabreOwner = $this->addMember($sabre, ParticipantRole::Owner);
        $sabreAdmin = $this->addMember($sabre, ParticipantRole::Admin);
        $this->addContribution($sabreOwner, ContributionType::Material, 1_000_000.0, ContributionStatus::Approved);
        $this->addContribution($sabreAdmin, ContributionType::LineRental, 250_000.0, ContributionStatus::Approved);
        $this->addContribution($sabreAdmin, ContributionType::Material, 500_000.0, ContributionStatus::Pending);
        $this->addContribution($sabreOwner, ContributionType::JobInstall, 100_000.0, ContributionStatus::Rejected);

        $jackdaw = $this->createProject('Jackdaw batch');
        $jackdawOwner = $this->addMember($jackdaw, ParticipantRole::Owner);
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
        $ishtarOwner = $this->addMember($ishtar, ParticipantRole::Owner);
        $this->addContribution($ishtarOwner, ContributionType::Material, 500_000.0, ContributionStatus::Pending);
        $this->addContribution($ishtarOwner, ContributionType::Material, 100_000.0, ContributionStatus::Rejected);
        $hurricane = $this->createProject('Hurricane line');
        $this->addMember($hurricane, ParticipantRole::Owner);
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
        $this->addContribution($this->addMember($sabre, ParticipantRole::Owner), ContributionType::Material, 2_400_000.0, ContributionStatus::Approved);
        $otherProject = $this->createProject('Other project');
        $this->addContribution($this->addMember($otherProject, ParticipantRole::Owner), ContributionType::Material, 9_900_000.0, ContributionStatus::Approved);
        $this->em->flush();

        self::assertSame(
            [(string) $sabre->getId() => 2_400_000.0],
            $this->repository->sumApprovedValueByProject([$sabre]),
        );
    }

    #[Group('issue-363')]
    public function testApprovedValueIsUnknownForAProjectWhoseApprovedContributionHasAnUnknownValueNeverPartial(): void
    {
        $sabre = $this->createProject('Sabre batch');
        $sabreOwner = $this->addMember($sabre, ParticipantRole::Owner);
        $this->addContribution($sabreOwner, ContributionType::Material, 1_000_000.0, ContributionStatus::Approved);
        $this->addContribution($sabreOwner, ContributionType::JobInstall, null, ContributionStatus::Approved);
        $jackdaw = $this->createProject('Jackdaw batch');
        $jackdawOwner = $this->addMember($jackdaw, ParticipantRole::Owner);
        $this->addContribution($jackdawOwner, ContributionType::Bpc, 857_500.0, ContributionStatus::Approved);
        $this->addContribution($jackdawOwner, ContributionType::JobInstall, null, ContributionStatus::Pending);
        $this->em->flush();

        $expected = [(string) $sabre->getId() => null, (string) $jackdaw->getId() => 857_500.0];
        ksort($expected);
        $approvedValues = $this->repository->sumApprovedValueByProject([$sabre, $jackdaw]);
        ksort($approvedValues);

        self::assertSame($expected, $approvedValues);
    }

    #[Group('issue-510')]
    public function testDuplicateLookupOfAMemberOnALineSeesTheDerivedContribution(): void
    {
        $ishtar = $this->createProject('Ishtar fleet');
        $owner = $this->addMember($ishtar, ParticipantRole::Owner);
        $tritanium = $this->addBomLine($ishtar, 34, 'Tritanium', 1_000);
        $derived = $this->addContributionOn($owner, $tritanium, ContributionStatus::Approved);
        $derived->setIsAutoDetected(true)->setIsVerified(true);
        $this->em->flush();
        $statuses = [ContributionStatus::Pending, ContributionStatus::Approved];

        self::assertSame($derived, $this->repository->findByMemberBomItemAndType($owner, $tritanium, ContributionType::Material, $statuses));
    }

    #[Group('issue-510')]
    public function testDuplicateLookupOfAMemberOnALineStillSeesTheDeclaredContribution(): void
    {
        $ishtar = $this->createProject('Ishtar fleet');
        $owner = $this->addMember($ishtar, ParticipantRole::Owner);
        $tritanium = $this->addBomLine($ishtar, 34, 'Tritanium', 1_000);
        $declared = $this->addContributionOn($owner, $tritanium, ContributionStatus::Pending);
        $this->em->flush();
        $statuses = [ContributionStatus::Pending, ContributionStatus::Approved];

        self::assertSame($declared, $this->repository->findByMemberBomItemAndType($owner, $tritanium, ContributionType::Material, $statuses));
    }

    #[Group('issue-510')]
    public function testDuplicateLookupIgnoresTheDerivedContributionOfAnotherMemberOnTheSameLine(): void
    {
        $ishtar = $this->createProject('Ishtar fleet');
        $owner = $this->addMember($ishtar, ParticipantRole::Owner);
        $admin = $this->addMember($ishtar, ParticipantRole::Admin);
        $tritanium = $this->addBomLine($ishtar, 34, 'Tritanium', 1_000);
        $this->addContributionOn($owner, $tritanium, ContributionStatus::Approved)->setIsAutoDetected(true)->setIsVerified(true);
        $this->em->flush();
        $statuses = [ContributionStatus::Pending, ContributionStatus::Approved];

        self::assertNull($this->repository->findByMemberBomItemAndType($admin, $tritanium, ContributionType::Material, $statuses));
    }

    #[Group('issue-363')]
    public function testASecondDerivedContributionOfTheSameMemberLineAndTypeIsRejectedByTheDatabase(): void
    {
        $ishtar = $this->createProject('Ishtar fleet');
        $owner = $this->addMember($ishtar, ParticipantRole::Owner);
        $tritanium = $this->addBomLine($ishtar, 34, 'Tritanium', 1_000);
        $this->addContributionOn($owner, $tritanium, ContributionStatus::Approved)->setIsAutoDetected(true);
        $this->em->flush();

        $this->addContributionOn($owner, $tritanium, ContributionStatus::Approved)->setIsAutoDetected(true);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testNoProjectGivesNoApprovedValue(): void
    {
        self::assertSame([], $this->repository->sumApprovedValueByProject([]));
    }

    #[Group('issue-257')]
    public function testContributedBomItemIdsAreTheLinesOfTheProjectCarryingAContributionOfAnyStatusEachOnce(): void
    {
        $sabre = $this->createProject('Sabre batch');
        $sabreOwner = $this->addMember($sabre, ParticipantRole::Owner);
        $sabreAdmin = $this->addMember($sabre, ParticipantRole::Admin);
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
        $this->addContributionOn($this->addMember($sabre, ParticipantRole::Owner), $morphite, ContributionStatus::Pending);
        $jackdaw = $this->createProject('Jackdaw batch');
        $zydrine = $this->addBomLine($jackdaw, 39, 'Zydrine', 820);
        $this->addContributionOn($this->addMember($jackdaw, ParticipantRole::Owner), $zydrine, ContributionStatus::Approved);
        $this->em->flush();

        self::assertSame([$morphite->getId()->toRfc4122()], $this->repository->findContributedBomItemIds($sabre));
    }

    #[Group('issue-257')]
    public function testProjectWhoseOnlyContributionHasNoBomLineGivesNoContributedBomItemId(): void
    {
        $ishtar = $this->createProject('Ishtar fleet');
        $this->addBomLine($ishtar, 38, 'Nocxium', 1_350);
        $this->addContribution($this->addMember($ishtar, ParticipantRole::Owner), ContributionType::LineRental, 400_000.0, ContributionStatus::Approved);
        $this->em->flush();

        self::assertSame([], $this->repository->findContributedBomItemIds($ishtar));
    }

    private function createProject(string $name): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($this->createUser())->setName($name);
        $this->em->persist($project);

        return $project;
    }

    private function addMember(IndustryProject $project, ParticipantRole $role): IndustryProjectParticipant
    {
        $member = (new IndustryProjectParticipant())
            ->setUser($role === ParticipantRole::Owner ? $project->getOwner() : $this->createUser())
            ->setRole($role)
            ->setStatus(ParticipantStatus::Accepted);
        $project->addMember($member);

        return $member;
    }

    private function addBomLine(IndustryProject $project, int $typeId, string $typeName, int $requiredQuantity): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($requiredQuantity)
            ->setIsJob(false);
        $project->addBomItem($line);

        return $line;
    }

    private function addContributionOn(IndustryProjectParticipant $member, IndustryProjectBomLine $line, ContributionStatus $status): IndustryProjectContribution
    {
        $contribution = (new IndustryProjectContribution())
            ->setMember($member)
            ->setBomItem($line)
            ->setType(ContributionType::Material)
            ->setQuantity($line->getRequiredQuantity())
            ->setEstimatedValue(0.0)
            ->setStatus($status);
        $member->getProject()->addContribution($contribution);

        return $contribution;
    }

    private function addContribution(IndustryProjectParticipant $member, ContributionType $type, ?float $estimatedValue, ContributionStatus $status): void
    {
        $member->getProject()->addContribution(
            (new IndustryProjectContribution())
                ->setMember($member)
                ->setType($type)
                ->setQuantity(1)
                ->setEstimatedValue($estimatedValue)
                ->setStatus($status),
        );
    }
}
