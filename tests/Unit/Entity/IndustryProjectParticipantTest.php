<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProjectParticipant::class)]
#[Group('issue-53')]
final class IndustryProjectParticipantTest extends TestCase
{
    public function testAnonymizedAdminBecomesARemovedMemberWithoutUserAndKeepsItsJoinDate(): void
    {
        $joinedAt = new \DateTimeImmutable('2026-01-03 18:30:00');
        $member = (new IndustryProjectParticipant())
            ->setUser(new User())
            ->setRole(ParticipantRole::Admin)
            ->setStatus(ParticipantStatus::Accepted);
        (new \ReflectionProperty(IndustryProjectParticipant::class, 'joinedAt'))->setValue($member, $joinedAt);

        $member->anonymize();

        self::assertNull($member->getUser());
        self::assertSame(ParticipantStatus::Removed, $member->getStatus());
        self::assertSame(ParticipantRole::Member, $member->getRole());
        self::assertSame($joinedAt, $member->getJoinedAt());
    }

    public function testAnonymizedRemovedMemberStaysRemovedWithoutUser(): void
    {
        $member = (new IndustryProjectParticipant())
            ->setUser(new User())
            ->setRole(ParticipantRole::Member)
            ->setStatus(ParticipantStatus::Removed);

        $member->anonymize();

        self::assertNull($member->getUser());
        self::assertSame(ParticipantStatus::Removed, $member->getStatus());
        self::assertSame(ParticipantRole::Member, $member->getRole());
    }
}
