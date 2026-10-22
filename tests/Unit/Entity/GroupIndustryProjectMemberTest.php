<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(GroupIndustryProjectMember::class)]
#[Group('issue-53')]
final class GroupIndustryProjectMemberTest extends TestCase
{
    public function testAnonymizedAdminBecomesARemovedMemberWithoutUserAndKeepsItsJoinDate(): void
    {
        $joinedAt = new \DateTimeImmutable('2026-01-03 18:30:00');
        $member = (new GroupIndustryProjectMember())
            ->setUser(new User())
            ->setRole(GroupMemberRole::Admin)
            ->setStatus(GroupMemberStatus::Accepted);
        (new \ReflectionProperty(GroupIndustryProjectMember::class, 'joinedAt'))->setValue($member, $joinedAt);

        $member->anonymize();

        self::assertNull($member->getUser());
        self::assertSame(GroupMemberStatus::Removed, $member->getStatus());
        self::assertSame(GroupMemberRole::Member, $member->getRole());
        self::assertSame($joinedAt, $member->getJoinedAt());
    }

    public function testAnonymizedRemovedMemberStaysRemovedWithoutUser(): void
    {
        $member = (new GroupIndustryProjectMember())
            ->setUser(new User())
            ->setRole(GroupMemberRole::Member)
            ->setStatus(GroupMemberStatus::Removed);

        $member->anonymize();

        self::assertNull($member->getUser());
        self::assertSame(GroupMemberStatus::Removed, $member->getStatus());
        self::assertSame(GroupMemberRole::Member, $member->getRole());
    }
}
