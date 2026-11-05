<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Industry\Project\ProjectContainerVerificationResource;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ProjectContainerService;
use App\State\Provider\Industry\Project\ContainerVerificationProvider;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
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
    private IndustryProjectRepository&Stub $projectRepository;
    private IndustryProjectParticipantRepository&Stub $memberRepository;
    private ProjectContainerService&Stub $containerService;
    private ProjectAccessChecker $accessChecker;
    private ContainerVerificationProvider $provider;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->projectRepository = $this->createStub(IndustryProjectRepository::class);
        $this->memberRepository = $this->createStub(IndustryProjectParticipantRepository::class);
        $this->containerService = $this->createStub(ProjectContainerService::class);
        $this->accessChecker = new ProjectAccessChecker($this->memberRepository);

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

        $project = (new IndustryProject());
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

        $project = (new IndustryProject());
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

        $project = (new IndustryProject());
        $project->setOwner($owner);
        $this->projectRepository->method('find')->willReturn($project);

        $member = new IndustryProjectParticipant();
        $member->setUser($user);
        $member->setProject($project);
        $member->setRole(ParticipantRole::Admin);
        $member->setStatus(ParticipantStatus::Accepted);
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

        $project = (new IndustryProject());
        $project->setOwner($user);
        $this->projectRepository->method('find')->willReturn($project);

        $resource = new ProjectContainerVerificationResource();
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
