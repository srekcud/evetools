<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\Delete;
use App\ApiResource\GroupIndustry\GroupIndustryProjectResource;
use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\GroupIndustrySale;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use App\Repository\GroupIndustryProjectRepository;
use App\State\Provider\GroupIndustry\GroupProjectDeleteProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GroupProjectDeleteProvider::class)]
class GroupProjectDeleteProviderTest extends TestCase
{
    private User $owner;
    private GroupIndustryProject $project;
    private GroupIndustryProjectMember $contributor;
    private GroupIndustryBomItem $tritanium;

    protected function setUp(): void
    {
        $this->owner = $this->createStub(User::class);

        $this->project = new GroupIndustryProject();
        $this->project->setOwner($this->owner);
        $this->assignId($this->project);

        $this->contributor = new GroupIndustryProjectMember();
        $this->contributor->setUser($this->createStub(User::class));
        $this->contributor->setRole(GroupMemberRole::Member);
        $this->contributor->setStatus(GroupMemberStatus::Accepted);
        $this->assignId($this->contributor);
        $this->project->addMember($this->contributor);

        $this->tritanium = new GroupIndustryBomItem();
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
        $this->project->setStatus(GroupProjectStatus::InProgress);
        $this->addContribution(ContributionStatus::Approved, 800);

        $this->expectException(BadRequestHttpException::class);

        $this->provideFor($this->owner);
    }

    public function testOwnerCanDeleteAnInProgressProjectWhoseContributionsArePendingOrRejected(): void
    {
        $this->project->setStatus(GroupProjectStatus::InProgress);
        $this->addContribution(ContributionStatus::Pending, 450);
        $this->addContribution(ContributionStatus::Rejected, 300);

        $resource = $this->provideFor($this->owner);

        self::assertSame($this->project->getId()->toString(), $resource->id);
    }

    public function testOwnerCannotDeleteASellingProjectWithASale(): void
    {
        $this->project->setStatus(GroupProjectStatus::Selling);
        $this->addSale();

        $this->expectException(BadRequestHttpException::class);

        $this->provideFor($this->owner);
    }

    public function testOwnerCannotDeleteACompletedProjectWithoutContributionNorSale(): void
    {
        $this->project->setStatus(GroupProjectStatus::Completed);

        $this->expectException(BadRequestHttpException::class);

        $this->provideFor($this->owner);
    }

    public function testAdminWhoIsNotTheOwnerCannotDeleteAPublishedProject(): void
    {
        $admin = $this->createStub(User::class);
        $adminMember = new GroupIndustryProjectMember();
        $adminMember->setUser($admin);
        $adminMember->setRole(GroupMemberRole::Admin);
        $adminMember->setStatus(GroupMemberStatus::Accepted);
        $this->assignId($adminMember);
        $this->project->addMember($adminMember);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Only the project owner can delete this project');

        $this->provideFor($admin);
    }

    private function addContribution(ContributionStatus $status, int $quantity): void
    {
        $contribution = new GroupIndustryContribution();
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
        $sale = new GroupIndustrySale();
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

    private function provideFor(User $user): GroupIndustryProjectResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $projectRepository = $this->createStub(GroupIndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);

        $provider = new GroupProjectDeleteProvider($security, $projectRepository);

        return $provider->provide(new Delete(), ['id' => $this->project->getId()->toRfc4122()]);
    }

    private function assignId(object $entity): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, Uuid::v4());
    }
}
