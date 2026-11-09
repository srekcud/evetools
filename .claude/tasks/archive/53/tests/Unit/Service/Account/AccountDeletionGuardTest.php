<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Account;

use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Exception\AccountDeletionBlockedException;
use App\Service\Account\AccountDeletionGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Issue #53: a user cannot delete their account while they own a group project that other
 * players have joined (accepted members), because deleting the owner would delete the project
 * and the contributions of everyone else. There is no ownership transfer: the owner must delete
 * these projects first. The refusal is an HTTP 409 Conflict.
 */
#[CoversClass(AccountDeletionGuard::class)]
final class AccountDeletionGuardTest extends TestCase
{
    private const int HTTP_CONFLICT = 409;

    private User $owner;
    private AccountDeletionGuard $guard;

    protected function setUp(): void
    {
        $this->owner = new User();
        $this->guard = new AccountDeletionGuard();
    }

    public function testOwnerWithoutGroupProjectCanDeleteAccount(): void
    {
        $this->expectNotToPerformAssertions();

        $this->guard->assertCanDelete($this->owner, []);
    }

    public function testOwnerAloneInGroupProjectCanDeleteAccount(): void
    {
        $soloProject = $this->ownedProject('Solo Sabre run');

        $this->expectNotToPerformAssertions();

        $this->guard->assertCanDelete($this->owner, [$soloProject]);
    }

    public function testOwnerWhoseProjectOnlyHasPendingJoinRequestsCanDeleteAccount(): void
    {
        $project = $this->ownedProject('Sabre doctrine');
        $this->addMember($project, new User(), GroupMemberRole::Member, GroupMemberStatus::Pending);

        $this->expectNotToPerformAssertions();

        $this->guard->assertCanDelete($this->owner, [$project]);
    }

    public function testOwnerOfProjectWithAnotherAcceptedMemberIsRefusedWithConflict(): void
    {
        $project = $this->ownedProject('Sabre doctrine');
        $this->addMember($project, new User(), GroupMemberRole::Member, GroupMemberStatus::Accepted);

        $refusal = $this->refusalFor([$project]);

        self::assertSame(self::HTTP_CONFLICT, $refusal->getStatusCode());
        self::assertSame(
            'Cannot delete your account while you own group projects with other members: Sabre doctrine. Delete these projects first.',
            $refusal->getMessage(),
        );
    }

    public function testRefusalListsEveryBlockingProjectAndOnlyThem(): void
    {
        $sabre = $this->ownedProject('Sabre doctrine');
        $this->addMember($sabre, new User(), GroupMemberRole::Admin, GroupMemberStatus::Accepted);
        $solo = $this->ownedProject('Solo Sabre run');
        $ishtar = $this->ownedProject('Ishtar fleet');
        $this->addMember($ishtar, new User(), GroupMemberRole::Member, GroupMemberStatus::Accepted);

        $refusal = $this->refusalFor([$sabre, $solo, $ishtar]);

        self::assertSame(self::HTTP_CONFLICT, $refusal->getStatusCode());
        self::assertSame(
            'Cannot delete your account while you own group projects with other members: Sabre doctrine, Ishtar fleet. Delete these projects first.',
            $refusal->getMessage(),
        );
    }

    private function ownedProject(string $name): GroupIndustryProject
    {
        $project = (new GroupIndustryProject())->setOwner($this->owner)->setName($name);
        $this->addMember($project, $this->owner, GroupMemberRole::Owner, GroupMemberStatus::Accepted);

        return $project;
    }

    private function addMember(GroupIndustryProject $project, User $user, GroupMemberRole $role, GroupMemberStatus $status): void
    {
        $project->addMember(
            (new GroupIndustryProjectMember())->setUser($user)->setRole($role)->setStatus($status),
        );
    }

    /** @param GroupIndustryProject[] $ownedProjects */
    private function refusalFor(array $ownedProjects): AccountDeletionBlockedException
    {
        try {
            $this->guard->assertCanDelete($this->owner, $ownedProjects);
        } catch (AccountDeletionBlockedException $refusal) {
            return $refusal;
        }

        self::fail('Account deletion should have been refused');
    }
}
