<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\GroupIndustry;

use App\ApiResource\GroupIndustry\GroupIndustryMemberResource;
use App\Entity\Character;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GroupIndustryResourceMapper::class)]
class GroupIndustryResourceMapperTest extends TestCase
{
    private GroupIndustryResourceMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new GroupIndustryResourceMapper();
    }

    // ===========================================
    // memberToResource()
    // ===========================================

    public function testMemberToResourceMapsCharacterIdFromEveCharacterId(): void
    {
        $character = $this->createStub(Character::class);
        $character->method('getName')->willReturn('Test Pilot');
        $character->method('getEveCharacterId')->willReturn(2119843655);

        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($character);
        $user->method('getCorporationId')->willReturn(98000001);

        $member = new GroupIndustryProjectMember();
        $member->setUser($user);
        $member->setRole(GroupMemberRole::Member);
        $member->setStatus(GroupMemberStatus::Accepted);

        $reflection = new \ReflectionProperty(GroupIndustryProjectMember::class, 'id');
        $reflection->setValue($member, Uuid::v4());

        $resource = $this->mapper->memberToResource($member, 1500000.0, 5);

        $this->assertInstanceOf(GroupIndustryMemberResource::class, $resource);
        $this->assertSame(2119843655, $resource->characterId);
        $this->assertSame('Test Pilot', $resource->characterName);
        $this->assertSame(98000001, $resource->corporationId);
        $this->assertSame('member', $resource->role);
        $this->assertSame('accepted', $resource->status);
        $this->assertSame(1500000.0, $resource->totalContributionValue);
        $this->assertSame(5, $resource->contributionCount);
    }

    public function testMemberToResourceHandlesNullMainCharacter(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn(null);
        $user->method('getCorporationId')->willReturn(null);

        $member = new GroupIndustryProjectMember();
        $member->setUser($user);
        $member->setRole(GroupMemberRole::Owner);
        $member->setStatus(GroupMemberStatus::Accepted);

        $reflection = new \ReflectionProperty(GroupIndustryProjectMember::class, 'id');
        $reflection->setValue($member, Uuid::v4());

        $resource = $this->mapper->memberToResource($member);

        $this->assertSame('Unknown', $resource->characterName);
        $this->assertSame(0, $resource->characterId);
        $this->assertSame(0.0, $resource->totalContributionValue);
        $this->assertSame(0, $resource->contributionCount);
    }
}
