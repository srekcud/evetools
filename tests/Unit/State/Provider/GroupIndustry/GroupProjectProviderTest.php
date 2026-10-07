<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\Get;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use App\State\Provider\GroupIndustry\GroupProjectProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GroupProjectProvider::class)]
class GroupProjectProviderTest extends TestCase
{
    private const OWNER_CORPORATION_ID = 98000001;
    private const OTHER_CORPORATION_ID = 98000002;

    private Security&Stub $security;
    private GroupIndustryProject $project;

    /** @var GroupIndustryProjectMember[] */
    private array $memberships = [];

    private GroupProjectProvider $provider;

    protected function setUp(): void
    {
        $owner = $this->createStub(User::class);
        $owner->method('getCorporationId')->willReturn(self::OWNER_CORPORATION_ID);

        $this->project = new GroupIndustryProject();
        $this->project->setOwner($owner);
        $this->project->setName('Sabre batch');
        (new \ReflectionProperty(GroupIndustryProject::class, 'id'))->setValue($this->project, Uuid::v4());

        $this->security = $this->createStub(Security::class);

        $projectRepository = $this->createStub(GroupIndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);

        // In-memory repository: findOneBy matches every criterion against the stored rows
        $memberRepository = $this->createStub(GroupIndustryProjectMemberRepository::class);
        $memberRepository->method('findOneBy')->willReturnCallback(
            function (array $criteria): ?GroupIndustryProjectMember {
                foreach ($this->memberships as $membership) {
                    if ($this->membershipMatches($membership, $criteria)) {
                        return $membership;
                    }
                }

                return null;
            },
        );

        $this->provider = new GroupProjectProvider(
            $this->security,
            $projectRepository,
            $memberRepository,
            new GroupIndustryResourceMapper(),
        );
    }

    public function testAcceptedMemberFromAnotherCorporationSeesProjectWithHisRole(): void
    {
        $user = $this->userOfCorporation(self::OTHER_CORPORATION_ID);
        $this->addMembership($user, GroupMemberRole::Admin, GroupMemberStatus::Accepted);

        $resource = $this->provider->provide(new Get(), ['id' => $this->project->getId()->toRfc4122()]);

        self::assertSame('Sabre batch', $resource->name);
        self::assertSame('admin', $resource->myRole);
    }

    // --- Issue #10: a removed (kicked) member is treated like a non-member ---

    public function testRemovedMemberFromAnotherCorporationIsDenied(): void
    {
        $user = $this->userOfCorporation(self::OTHER_CORPORATION_ID);
        $this->addMembership($user, GroupMemberRole::Member, GroupMemberStatus::Removed);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this project');

        $this->provider->provide(new Get(), ['id' => $this->project->getId()->toRfc4122()]);
    }

    public function testRemovedMemberFromOwnerCorporationSeesProjectWithoutRole(): void
    {
        $user = $this->userOfCorporation(self::OWNER_CORPORATION_ID);
        $this->addMembership($user, GroupMemberRole::Admin, GroupMemberStatus::Removed);

        $resource = $this->provider->provide(new Get(), ['id' => $this->project->getId()->toRfc4122()]);

        self::assertSame('Sabre batch', $resource->name);
        self::assertNull($resource->myRole);
    }

    // --- myStatus: lets the front tell an accepted membership from a pending one ---

    public function testAcceptedMemberSeesAcceptedStatus(): void
    {
        $user = $this->userOfCorporation(self::OTHER_CORPORATION_ID);
        $this->addMembership($user, GroupMemberRole::Member, GroupMemberStatus::Accepted);

        $resource = $this->provider->provide(new Get(), ['id' => $this->project->getId()->toRfc4122()]);

        self::assertTrue(property_exists($resource, 'myStatus'), 'GroupIndustryProjectResource::$myStatus is missing');
        self::assertSame('accepted', $resource->myStatus);
    }

    public function testSameCorporationNonMemberHasNoStatus(): void
    {
        $this->userOfCorporation(self::OWNER_CORPORATION_ID);

        $resource = $this->provider->provide(new Get(), ['id' => $this->project->getId()->toRfc4122()]);

        self::assertTrue(property_exists($resource, 'myStatus'), 'GroupIndustryProjectResource::$myStatus is missing');
        self::assertNull($resource->myStatus);
        self::assertNull($resource->myRole);
    }

    private function userOfCorporation(int $corporationId): User&Stub
    {
        $user = $this->createStub(User::class);
        $user->method('getCorporationId')->willReturn($corporationId);
        $this->security->method('getUser')->willReturn($user);

        return $user;
    }

    private function addMembership(User $user, GroupMemberRole $role, GroupMemberStatus $status): void
    {
        $membership = new GroupIndustryProjectMember();
        $membership->setUser($user);
        $membership->setRole($role);
        $membership->setStatus($status);
        $this->project->addMember($membership);

        $this->memberships[] = $membership;
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function membershipMatches(GroupIndustryProjectMember $membership, array $criteria): bool
    {
        $values = [
            'project' => $membership->getProject(),
            'user' => $membership->getUser(),
            'role' => $membership->getRole(),
            'status' => $membership->getStatus(),
        ];

        foreach ($criteria as $field => $expected) {
            if (!array_key_exists($field, $values)) {
                self::fail("Unexpected criterion '{$field}'");
            }
            $accepted = is_array($expected) ? $expected : [$expected];
            if (!in_array($values[$field], $accepted, true)) {
                return false;
            }
        }

        return true;
    }
}
