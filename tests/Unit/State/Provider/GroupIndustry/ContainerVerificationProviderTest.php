<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\GroupIndustry\GroupContainerVerificationResource;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\Service\GroupIndustry\GroupIndustryContainerService;
use App\State\Provider\GroupIndustry\ContainerVerificationProvider;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ContainerVerificationProvider::class)]
class ContainerVerificationProviderTest extends TestCase
{
    private Security&Stub $security;
    private GroupIndustryProjectRepository&Stub $projectRepository;
    private GroupIndustryProjectMemberRepository&Stub $memberRepository;
    private GroupIndustryContainerService&Stub $containerService;
    private GroupProjectAccessChecker $accessChecker;
    private ContainerVerificationProvider $provider;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->projectRepository = $this->createStub(GroupIndustryProjectRepository::class);
        $this->memberRepository = $this->createStub(GroupIndustryProjectMemberRepository::class);
        $this->containerService = $this->createStub(GroupIndustryContainerService::class);
        $this->accessChecker = new GroupProjectAccessChecker($this->memberRepository);

        $this->provider = new ContainerVerificationProvider(
            $this->security,
            $this->projectRepository,
            $this->containerService,
            $this->accessChecker,
        );
    }

    // ===========================================
    // Auth
    // ===========================================

    public function testThrowsUnauthorizedWhenNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);

        $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);
    }

    public function testThrowsNotFoundWhenProjectDoesNotExist(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);
        $this->projectRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);

        $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);
    }

    public function testThrowsAccessDeniedWhenUserIsRegularMember(): void
    {
        $owner = $this->createUserStub();
        $user = $this->createUserStub();

        $this->security->method('getUser')->willReturn($user);

        $project = new GroupIndustryProject();
        $project->setOwner($owner);
        $this->projectRepository->method('find')->willReturn($project);
        $this->memberRepository->method('findOneBy')->willReturn(null);

        $this->expectException(AccessDeniedHttpException::class);

        $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);
    }

    // ===========================================
    // Access grants
    // ===========================================

    public function testOwnerCanVerifyContainer(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $project = new GroupIndustryProject();
        $project->setOwner($user);
        $this->projectRepository->method('find')->willReturn($project);
        $this->containerService->method('verifyContainer')->willReturn([]);

        $result = $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);

        $this->assertSame([], $result);
    }

    public function testAdminCanVerifyContainer(): void
    {
        $owner = $this->createUserStub();
        $user = $this->createUserStub();

        $this->security->method('getUser')->willReturn($user);

        $project = new GroupIndustryProject();
        $project->setOwner($owner);
        $this->projectRepository->method('find')->willReturn($project);

        $member = new GroupIndustryProjectMember();
        $member->setUser($user);
        $member->setProject($project);
        $member->setRole(GroupMemberRole::Admin);
        $member->setStatus(GroupMemberStatus::Accepted);
        $this->memberRepository->method('findOneBy')->willReturn($member);
        $this->containerService->method('verifyContainer')->willReturn([]);

        $result = $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);

        $this->assertSame([], $result);
    }

    // ===========================================
    // Delegation to service
    // ===========================================

    public function testDelegatesToContainerService(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $project = new GroupIndustryProject();
        $project->setOwner($user);
        $this->projectRepository->method('find')->willReturn($project);

        $resource = new GroupContainerVerificationResource();
        $resource->bomItemId = 'test-id';
        $resource->typeId = 34;
        $resource->typeName = 'Tritanium';
        $resource->requiredQuantity = 1000;
        $resource->containerQuantity = 500;
        $resource->status = 'partial';

        $this->containerService->method('verifyContainer')->willReturn([$resource]);

        $result = $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);

        $this->assertCount(1, $result);
        $this->assertSame('partial', $result[0]->status);
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createUserStub(): User&Stub
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());

        return $user;
    }
}
