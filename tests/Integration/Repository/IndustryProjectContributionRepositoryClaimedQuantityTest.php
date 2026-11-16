<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectContributionRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-1006')]
final class IndustryProjectContributionRepositoryClaimedQuantityTest extends IntegrationTestCase
{
    private IndustryProjectContributionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(IndustryProjectContributionRepository::class);
    }

    public function testClaimedQuantityAddsThePendingAndApprovedMaterialContributionsOfEachLine(): void
    {
        $project = $this->createProject();
        $owner = $this->addOwner($project);
        $tritanium = $this->addMaterialLine($project, 34);
        $pyerite = $this->addMaterialLine($project, 35);
        $this->addContribution($owner, $tritanium, ContributionType::Material, 30, ContributionStatus::Approved);
        $this->addContribution($owner, $tritanium, ContributionType::Material, 20, ContributionStatus::Pending);
        $this->addContribution($owner, $pyerite, ContributionType::Material, 7, ContributionStatus::Pending);
        $this->em->flush();

        self::assertSame(
            [$tritanium->getId()->toRfc4122() => 50, $pyerite->getId()->toRfc4122() => 7],
            $this->claimedQuantities($project),
        );
    }

    public function testRejectedAndNonMaterialContributionsAreNotClaimedStock(): void
    {
        $project = $this->createProject();
        $owner = $this->addOwner($project);
        $tritanium = $this->addMaterialLine($project, 34);
        $this->addContribution($owner, $tritanium, ContributionType::Material, 40, ContributionStatus::Rejected);
        $this->addContribution($owner, $tritanium, ContributionType::JobInstall, 5, ContributionStatus::Approved);
        $this->addContribution($owner, $tritanium, ContributionType::Material, 10, ContributionStatus::Approved);
        $this->em->flush();

        self::assertSame([$tritanium->getId()->toRfc4122() => 10], $this->claimedQuantities($project));
    }

    public function testClaimedQuantityIgnoresTheContributionsOfAnotherProject(): void
    {
        $project = $this->createProject();
        $owner = $this->addOwner($project);
        $tritanium = $this->addMaterialLine($project, 34);
        $this->addContribution($owner, $tritanium, ContributionType::Material, 12, ContributionStatus::Approved);
        $other = $this->createProject();
        $otherOwner = $this->addOwner($other);
        $this->addContribution($otherOwner, $this->addMaterialLine($other, 34), ContributionType::Material, 99, ContributionStatus::Approved);
        $this->em->flush();

        self::assertSame([$tritanium->getId()->toRfc4122() => 12], $this->claimedQuantities($project));
    }

    public function testLaunchPriceIsPersistedOnTheLineAndStaysNullByDefault(): void
    {
        $project = $this->createProject();
        $priced = $this->addMaterialLine($project, 34)->setLaunchPrice(5.25);
        $unpriced = $this->addMaterialLine($project, 35);
        $this->em->flush();
        $this->em->clear();

        $lines = self::getContainer()->get(IndustryProjectBomLineRepository::class);

        self::assertSame(5.25, $lines->find($priced->getId())?->getLaunchPrice());
        self::assertNull($lines->find($unpriced->getId())?->getLaunchPrice());
    }

    /**
     * @return array<string, int>
     */
    private function claimedQuantities(IndustryProject $project): array
    {
        return $this->repository->sumClaimedMaterialQuantityByLine($project);
    }

    private function createProject(): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($this->createUser());
        $this->em->persist($project);

        return $project;
    }

    private function addOwner(IndustryProject $project): IndustryProjectParticipant
    {
        $member = (new IndustryProjectParticipant())
            ->setUser($project->getOwner())
            ->setRole(ParticipantRole::Owner)
            ->setStatus(ParticipantStatus::Accepted);
        $project->addMember($member);

        return $member;
    }

    private function addMaterialLine(IndustryProject $project, int $typeId): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName('Material '.$typeId)
            ->setRequiredQuantity(100)
            ->setIsJob(false);
        $project->addBomItem($line);

        return $line;
    }

    private function addContribution(IndustryProjectParticipant $member, IndustryProjectBomLine $line, ContributionType $type, int $quantity, ContributionStatus $status): void
    {
        $member->getProject()->addContribution(
            (new IndustryProjectContribution())
                ->setMember($member)
                ->setBomItem($line)
                ->setType($type)
                ->setQuantity($quantity)
                ->setEstimatedValue(1_000.0)
                ->setStatus($status),
        );
    }
}
