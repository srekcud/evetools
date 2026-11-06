<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Delete;
use App\ApiResource\Industry\Project\IndustryProjectResource;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectRepository;
use App\State\Provider\Industry\Project\ProjectDeleteProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectDeleteProvider::class)]
class ProjectDeleteProviderTest extends TestCase
{
    private User $owner;
    private IndustryProject $project;
    private IndustryProjectParticipant $contributor;
    private IndustryProjectBomLine $tritanium;

    protected function setUp(): void
    {
        $this->owner = $this->createStub(User::class);

        $this->project = (new IndustryProject());
        $this->project->setOwner($this->owner);
        $this->assignId($this->project);

        $this->contributor = new IndustryProjectParticipant();
        $this->contributor->setUser($this->createStub(User::class));
        $this->contributor->setRole(ParticipantRole::Member);
        $this->contributor->setStatus(ParticipantStatus::Accepted);
        $this->assignId($this->contributor);
        $this->project->addMember($this->contributor);

        $this->tritanium = new IndustryProjectBomLine();
        $this->tritanium->setTypeId(34);
        $this->tritanium->setTypeName('Tritanium');
        $this->tritanium->setRequiredQuantity(12000);
        $this->assignId($this->tritanium);
        $this->project->addBomItem($this->tritanium);
    }

    public function testOwnerCanDeleteAPublishedProjectWithoutContributionNorSale(): void
    {
        $resource = $this->provideFor($this->owner);

        self::assertSame($this->project->getId()->toString(), $resource->id);
    }

    public function testOwnerCannotDeleteAnInProgressProjectWithAnApprovedContribution(): void
    {
        $this->project->setStatus(ProjectStatus::InProgress);
        $this->addContribution(ContributionStatus::Approved, 800);

        $this->expectException(BadRequestHttpException::class);

        $this->provideFor($this->owner);
    }

    public function testOwnerCanDeleteAnInProgressProjectWhoseContributionsArePendingOrRejected(): void
    {
        $this->project->setStatus(ProjectStatus::InProgress);
        $this->addContribution(ContributionStatus::Pending, 450);
        $this->addContribution(ContributionStatus::Rejected, 300);

        $resource = $this->provideFor($this->owner);

        self::assertSame($this->project->getId()->toString(), $resource->id);
    }

    public function testOwnerCannotDeleteASellingProjectWithASale(): void
    {
        $this->project->setStatus(ProjectStatus::Selling);
        $this->addSale();

        $this->expectException(BadRequestHttpException::class);

        $this->provideFor($this->owner);
    }

    public function testOwnerCannotDeleteACompletedProjectWithoutContributionNorSale(): void
    {
        $this->project->setStatus(ProjectStatus::Completed);

        $this->expectException(BadRequestHttpException::class);

        $this->provideFor($this->owner);
    }

    public function testAdminWhoIsNotTheOwnerCannotDeleteAPublishedProject(): void
    {
        $admin = $this->createStub(User::class);
        $adminMember = new IndustryProjectParticipant();
        $adminMember->setUser($admin);
        $adminMember->setRole(ParticipantRole::Admin);
        $adminMember->setStatus(ParticipantStatus::Accepted);
        $this->assignId($adminMember);
        $this->project->addMember($adminMember);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Only the project owner can delete this project');

        $this->provideFor($admin);
    }

    public function testDeletionWithoutUserIsUnauthorized(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);

        $this->expectException(UnauthorizedHttpException::class);

        (new ProjectDeleteProvider($security, $projectRepository))
            ->provide(new Delete(), ['id' => $this->project->getId()->toRfc4122()]);
    }

    public function testDeletionOfAnUnknownProjectIsNotFound(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->owner);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        (new ProjectDeleteProvider($security, $projectRepository))
            ->provide(new Delete(), ['id' => Uuid::v4()->toRfc4122()]);
    }

    private function addContribution(ContributionStatus $status, int $quantity): void
    {
        $contribution = new IndustryProjectContribution();
        $contribution->setMember($this->contributor);
        $contribution->setBomItem($this->tritanium);
        $contribution->setType(ContributionType::Material);
        $contribution->setQuantity($quantity);
        $contribution->setEstimatedValue($quantity * 4.5);
        $contribution->setStatus($status);
        $contribution->setIsAutoDetected(false);
        $contribution->setIsVerified(false);
        $this->assignId($contribution);
        $this->project->addContribution($contribution);
    }

    private function addSale(): void
    {
        $sale = new IndustryProjectSale();
        $sale->setTypeId(28606);
        $sale->setTypeName('Orca');
        $sale->setQuantity(2);
        $sale->setUnitPrice(1450000.0);
        $sale->setTotalPrice(2900000.0);
        $sale->setSoldAt(new \DateTimeImmutable('2026-10-21T09:15:00+00:00'));
        $sale->setRecordedBy($this->owner);
        $this->assignId($sale);
        $this->project->addSale($sale);
    }

    private function provideFor(User $user): IndustryProjectResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);

        $provider = new ProjectDeleteProvider($security, $projectRepository);

        return $provider->provide(new Delete(), ['id' => $this->project->getId()->toRfc4122()]);
    }

    private function assignId(object $entity): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, Uuid::v4());
    }
}
