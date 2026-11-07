<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Component\Uid\Uuid;

trait BuildsWorkshopProjects
{
    private function pilot(): User&Stub
    {
        return $this->createStub(User::class);
    }

    private function workshopProject(?string $name, ProjectStatus $status, User $owner): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($owner)->setName($name)->setStatus($status);
        (new \ReflectionProperty(IndustryProject::class, 'id'))->setValue($project, Uuid::v4());

        return $project;
    }

    private function membershipOf(IndustryProject $project, User $user, ParticipantRole $role, ParticipantStatus $status): IndustryProjectParticipant
    {
        $membership = (new IndustryProjectParticipant())->setUser($user)->setRole($role)->setStatus($status);
        (new \ReflectionProperty(IndustryProjectParticipant::class, 'id'))->setValue($membership, Uuid::v4());
        $project->addMember($membership);

        return $membership;
    }

    private function productOf(IndustryProject $project, int $typeId, string $typeName, int $runs): void
    {
        $project->addItem((new IndustryProjectProduct())->setTypeId($typeId)->setTypeName($typeName)->setRuns($runs));
    }

    private function materialOf(IndustryProject $project, int $typeId, string $typeName, int $requiredQuantity, int $fulfilledQuantity): IndustryProjectBomLine
    {
        return $this->bomLineOf($project, $typeId, $typeName, $requiredQuantity, $fulfilledQuantity, false, null, null);
    }

    private function jobOf(IndustryProject $project, int $typeId, string $typeName, string $jobGroup, ?int $runs, int $requiredQuantity, int $fulfilledQuantity): IndustryProjectBomLine
    {
        return $this->bomLineOf($project, $typeId, $typeName, $requiredQuantity, $fulfilledQuantity, true, $jobGroup, $runs);
    }

    private function matchedRunsOn(IndustryProjectBomLine $line, int $runs): void
    {
        $line->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(612_335_000 + $runs)->setRuns($runs)->setStatus('active'));
    }

    private function saleOf(IndustryProject $project, int $typeId, string $typeName, int $quantity): void
    {
        $project->addSale((new IndustryProjectSale())->setTypeId($typeId)->setTypeName($typeName)->setQuantity($quantity)->setUnitPrice(1_000_000.0));
    }

    private function contributionOf(IndustryProject $project, IndustryProjectParticipant $contributor, int $quantity, ContributionStatus $status): void
    {
        $project->addContribution(
            (new IndustryProjectContribution())
                ->setMember($contributor)
                ->setType(ContributionType::Material)
                ->setQuantity($quantity)
                ->setEstimatedValue($quantity * 7.5)
                ->setStatus($status),
        );
    }

    private function bomLineOf(IndustryProject $project, int $typeId, string $typeName, int $requiredQuantity, int $fulfilledQuantity, bool $isJob, ?string $jobGroup, ?int $runs): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($requiredQuantity)
            ->setFulfilledQuantity($fulfilledQuantity)
            ->setIsJob($isJob)
            ->setJobGroup($jobGroup)
            ->setActivityType($isJob ? 'manufacturing' : null)
            ->setRuns($runs);
        (new \ReflectionProperty(IndustryProjectBomLine::class, 'id'))->setValue($line, Uuid::v4());
        $project->addBomItem($line);

        return $line;
    }
}
