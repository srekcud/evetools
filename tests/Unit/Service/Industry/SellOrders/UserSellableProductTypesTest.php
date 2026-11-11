<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\SellOrders;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectParticipantRepository;
use App\Service\Industry\SellOrders\ProjectProductTypes;
use App\Service\Industry\SellOrders\UserSellableProductTypes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(UserSellableProductTypes::class)]
#[CoversClass(ProjectProductTypes::class)]
#[Group('issue-350')]
final class UserSellableProductTypesTest extends TestCase
{
    private const int HAMMERHEAD_TYPE_ID = 2185;
    private const int SABRE_TYPE_ID = 22456;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int GILA_TYPE_ID = 17715;

    public function testProductsOfSellableProjectsAreListedOnceWithoutMaterialsAndWithoutIntermediateJobs(): void
    {
        $selling = $this->project(ProjectStatus::Selling, false);
        $this->line($selling, self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 'final');
        $this->line($selling, self::SABRE_TYPE_ID, 'Sabre', 'final');
        $this->line($selling, self::TRITANIUM_TYPE_ID, 'Tritanium', null, false);
        $this->line($selling, 11_399, 'Morphite', 'intermediate');
        $inProgress = $this->project(ProjectStatus::InProgress, false);
        $this->line($inProgress, self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 'final');

        $types = $this->typesOf($selling, $inProgress);

        self::assertSame([self::HAMMERHEAD_TYPE_ID, self::SABRE_TYPE_ID], $types);
    }

    public function testCompletedAndPersonalUseProjectsAreIgnored(): void
    {
        $completed = $this->project(ProjectStatus::Completed, false);
        $this->line($completed, self::GILA_TYPE_ID, 'Gila', 'final');
        $personal = $this->project(ProjectStatus::Selling, true);
        $this->line($personal, self::SABRE_TYPE_ID, 'Sabre', 'final');

        self::assertSame([], $this->typesOf($completed, $personal));
    }

    public function testProjectNamesTheTypesOfItsFinalJobs(): void
    {
        $project = $this->project(ProjectStatus::Selling, false);
        $this->line($project, self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 'final');
        $this->line($project, self::TRITANIUM_TYPE_ID, 'Tritanium', null, false);

        self::assertSame([self::HAMMERHEAD_TYPE_ID => 'Hammerhead II'], (new ProjectProductTypes())->of($project));
    }

    private function typesOf(IndustryProject ...$projects): array
    {
        $user = new User();
        $repository = $this->createStub(IndustryProjectParticipantRepository::class);
        $repository->method('findAcceptedWithProjectsByUser')->willReturn(array_map(
            fn (IndustryProject $project): IndustryProjectParticipant => (new IndustryProjectParticipant())
                ->setProject($project)
                ->setUser($user)
                ->setRole(ParticipantRole::Owner)
                ->setStatus(ParticipantStatus::Accepted),
            $projects,
        ));

        return (new UserSellableProductTypes($repository, new ProjectProductTypes()))->of($user);
    }

    private function project(ProjectStatus $status, bool $personalUse): IndustryProject
    {
        $project = (new IndustryProject())->setStatus($status)->setPersonalUse($personalUse);
        (new \ReflectionProperty(IndustryProject::class, 'id'))->setValue($project, Uuid::v7());

        return $project;
    }

    private function line(IndustryProject $project, int $typeId, string $name, ?string $jobGroup, bool $isJob = true): void
    {
        $project->addBomItem(
            (new IndustryProjectBomLine())
                ->setTypeId($typeId)
                ->setTypeName($name)
                ->setIsJob($isJob)
                ->setJobGroup($jobGroup)
                ->setRequiredQuantity(5),
        );
    }
}
