<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\GroupIndustry;

use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[CoversClass(GroupProjectAccessChecker::class)]
class GroupProjectAccessCheckerTest extends TestCase
{
    private GroupIndustryProject $project;
    private User&Stub $owner;

    /** @var GroupIndustryProjectMember[] membership rows stored for the project */
    private array $memberships = [];

    private GroupProjectAccessChecker $accessChecker;

    protected function setUp(): void
    {
        $this->owner = $this->createStub(User::class);
        $this->project = new GroupIndustryProject();
        $this->project->setOwner($this->owner);

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

        $this->accessChecker = new GroupProjectAccessChecker($memberRepository);
    }

    public function testOwnerHasAccess(): void
    {
        $this->expectNotToPerformAssertions();

        $this->accessChecker->assertAcceptedMember($this->owner, $this->project);
        $this->accessChecker->assertAdminOrOwner($this->owner, $this->project);
    }

    public function testAcceptedMemberHasAccess(): void
    {
        $user = $this->createStub(User::class);
        $this->addMembership($user, GroupMemberRole::Member, GroupMemberStatus::Accepted);

        $this->expectNotToPerformAssertions();

        $this->accessChecker->assertAcceptedMember($user, $this->project);
    }

    public function testPendingMemberHasNoAccess(): void
    {
        $user = $this->createStub(User::class);
        $this->addMembership($user, GroupMemberRole::Member, GroupMemberStatus::Pending);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this project');

        $this->accessChecker->assertAcceptedMember($user, $this->project);
    }

    // --- Issue #10: a removed (kicked) member is treated like a non-member ---

    public function testRemovedMemberHasNoAccess(): void
    {
        $user = $this->createStub(User::class);
        $this->addMembership($user, GroupMemberRole::Member, GroupMemberStatus::Removed);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this project');

        $this->accessChecker->assertAcceptedMember($user, $this->project);
    }

    public function testRemovedAdminCanNoLongerAdministrate(): void
    {
        $user = $this->createStub(User::class);
        $this->addMembership($user, GroupMemberRole::Admin, GroupMemberStatus::Removed);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Only project owner or admin can perform this action');

        $this->accessChecker->assertAdminOrOwner($user, $this->project);
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
