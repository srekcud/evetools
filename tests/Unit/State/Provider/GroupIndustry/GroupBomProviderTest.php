<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\GroupIndustry\GroupIndustryBomResource;
use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\GroupIndustryBomItemRepository;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\State\Provider\GroupIndustry\GroupBomProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GroupBomProvider::class)]
class GroupBomProviderTest extends TestCase
{
    private Security&Stub $security;
    private GroupIndustryProjectRepository&Stub $projectRepository;
    private GroupIndustryBomItemRepository&Stub $bomItemRepository;
    private GroupIndustryProjectMemberRepository&Stub $memberRepository;
    private GroupBomProvider $provider;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->projectRepository = $this->createStub(GroupIndustryProjectRepository::class);
        $this->bomItemRepository = $this->createStub(GroupIndustryBomItemRepository::class);
        $this->memberRepository = $this->createStub(GroupIndustryProjectMemberRepository::class);

        $this->provider = new GroupBomProvider(
            $this->security,
            $this->projectRepository,
            $this->bomItemRepository,
            $this->memberRepository,
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

    public function testThrowsAccessDeniedWhenUserIsNotMemberNorSameCorp(): void
    {
        $owner = $this->createUserStub(98000001);
        $user = $this->createUserStub(98000099); // Different corp

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

    public function testOwnerCanAccessBom(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $project = new GroupIndustryProject();
        $project->setOwner($user);
        $this->projectRepository->method('find')->willReturn($project);
        $this->bomItemRepository->method('findBy')->willReturn([]);

        $result = $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);

        $this->assertSame([], $result);
    }

    public function testAcceptedMemberCanAccessBom(): void
    {
        $owner = $this->createUserStub(98000001);
        $user = $this->createUserStub(98000099);

        $this->security->method('getUser')->willReturn($user);

        $project = new GroupIndustryProject();
        $project->setOwner($owner);
        $this->projectRepository->method('find')->willReturn($project);

        $member = new GroupIndustryProjectMember();
        $member->setUser($user);
        $member->setProject($project);
        $member->setRole(GroupMemberRole::Member);
        $member->setStatus(GroupMemberStatus::Accepted);
        $this->memberRepository->method('findOneBy')->willReturn($member);
        $this->bomItemRepository->method('findBy')->willReturn([]);

        $result = $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);

        $this->assertSame([], $result);
    }

    public function testSameCorpUserCanAccessBom(): void
    {
        $owner = $this->createUserStub(98000001);
        $user = $this->createUserStub(98000001); // Same corp

        $this->security->method('getUser')->willReturn($user);

        $project = new GroupIndustryProject();
        $project->setOwner($owner);
        $this->projectRepository->method('find')->willReturn($project);
        $this->memberRepository->method('findOneBy')->willReturn(null);
        $this->bomItemRepository->method('findBy')->willReturn([]);

        $result = $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);

        $this->assertSame([], $result);
    }

    // ===========================================
    // BOM mapping
    // ===========================================

    public function testMapsBomItemToResource(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $project = new GroupIndustryProject();
        $project->setOwner($user);
        $this->projectRepository->method('find')->willReturn($project);

        $bomItem = $this->createBomItem(34, 'Tritanium', 1000, 500, 5.5, false, null, null, null, null, null, null);
        $this->bomItemRepository->method('findBy')->willReturn([$bomItem]);

        $result = $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);

        $this->assertCount(1, $result);
        $resource = $result[0];
        $this->assertInstanceOf(GroupIndustryBomResource::class, $resource);
        $this->assertSame(34, $resource->typeId);
        $this->assertSame('Tritanium', $resource->typeName);
        $this->assertSame(1000, $resource->requiredQuantity);
        $this->assertSame(500, $resource->fulfilledQuantity);
        $this->assertSame(500, $resource->remainingQuantity);
        $this->assertSame(50.0, $resource->fulfillmentPercent);
        $this->assertSame(5.5, $resource->estimatedPrice);
        $this->assertSame(5500.0, $resource->estimatedTotal); // 5.5 * 1000
        $this->assertFalse($resource->isJob);
        $this->assertNull($resource->jobGroup);
        $this->assertFalse($resource->isFulfilled);
    }

    public function testMapsJobBomItemToResource(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $project = new GroupIndustryProject();
        $project->setOwner($user);
        $this->projectRepository->method('find')->willReturn($project);

        $bomItem = $this->createBomItem(
            11399, 'Ferox', 100, 0, null,
            true, 'final', 'manufacturing', 34, 10, 20, 100,
        );
        $this->bomItemRepository->method('findBy')->willReturn([$bomItem]);

        $result = $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);

        $resource = $result[0];
        $this->assertTrue($resource->isJob);
        $this->assertSame('final', $resource->jobGroup);
        $this->assertSame('manufacturing', $resource->activityType);
        $this->assertSame(34, $resource->parentTypeId);
        $this->assertSame(10, $resource->meLevel);
        $this->assertSame(20, $resource->teLevel);
        $this->assertSame(100, $resource->runs);
        $this->assertNull($resource->estimatedTotal); // No price for jobs
    }

    public function testEstimatedTotalIsNullWhenNoPriceSet(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $project = new GroupIndustryProject();
        $project->setOwner($user);
        $this->projectRepository->method('find')->willReturn($project);

        $bomItem = $this->createBomItem(34, 'Tritanium', 1000, 0, null, false, null, null, null, null, null, null);
        $this->bomItemRepository->method('findBy')->willReturn([$bomItem]);

        $result = $this->provider->provide(new GetCollection(), ['projectId' => Uuid::v4()->toString()]);

        $this->assertNull($result[0]->estimatedTotal);
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createUserStub(?int $corporationId = null): User&Stub
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());
        $user->method('getCorporationId')->willReturn($corporationId);

        return $user;
    }

    private function createBomItem(
        int $typeId,
        string $typeName,
        int $requiredQuantity,
        int $fulfilledQuantity,
        ?float $estimatedPrice,
        bool $isJob,
        ?string $jobGroup,
        ?string $activityType,
        ?int $parentTypeId,
        ?int $meLevel,
        ?int $teLevel,
        ?int $runs,
    ): GroupIndustryBomItem {
        $item = new GroupIndustryBomItem();
        $item->setTypeId($typeId);
        $item->setTypeName($typeName);
        $item->setRequiredQuantity($requiredQuantity);
        $item->setFulfilledQuantity($fulfilledQuantity);
        $item->setEstimatedPrice($estimatedPrice);
        $item->setIsJob($isJob);
        $item->setJobGroup($jobGroup);
        $item->setActivityType($activityType);
        $item->setParentTypeId($parentTypeId);
        $item->setMeLevel($meLevel);
        $item->setTeLevel($teLevel);
        $item->setRuns($runs);

        $reflection = new \ReflectionProperty(GroupIndustryBomItem::class, 'id');
        $reflection->setValue($item, Uuid::v4());

        return $item;
    }
}
