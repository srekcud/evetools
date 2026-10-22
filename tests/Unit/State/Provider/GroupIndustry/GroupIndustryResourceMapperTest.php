<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\GroupIndustry;

use App\ApiResource\GroupIndustry\GroupIndustryMemberResource;
use App\Entity\Character;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
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

    #[Group('issue-53')]
    public function testMemberOfADeletedAccountHasNoCharacterNorCorporation(): void
    {
        $member = $this->anonymizedMember();

        $resource = $this->mapper->memberToResource($member, 1_000_000.0, 1);

        $this->assertNull($resource->characterName);
        $this->assertNull($resource->characterId);
        $this->assertNull($resource->corporationId);
        $this->assertNull($resource->corporationName);
        $this->assertSame('member', $resource->role);
        $this->assertSame('removed', $resource->status);
        $this->assertSame(1_000_000.0, $resource->totalContributionValue);
        $this->assertSame(1, $resource->contributionCount);
    }

    #[Group('issue-53')]
    public function testContributionOfADeletedAccountHasNoMemberCharacterName(): void
    {
        $contribution = (new GroupIndustryContribution())
            ->setMember($this->anonymizedMember())
            ->setType(ContributionType::Material)
            ->setQuantity(12_000)
            ->setEstimatedValue(1_000_000.0)
            ->setStatus(ContributionStatus::Approved);
        (new \ReflectionProperty(GroupIndustryContribution::class, 'id'))->setValue($contribution, Uuid::v4());

        $resource = $this->mapper->contributionToResource($contribution);

        $this->assertNull($resource->memberCharacterName);
        $this->assertSame(1_000_000.0, $resource->estimatedValue);
        $this->assertSame(12_000, $resource->quantity);
    }

    private function anonymizedMember(): GroupIndustryProjectMember
    {
        $mainCharacter = (new Character())->setEveCharacterId(2_112_000_053)->setName('Deleted Pilot')->setCorporationId(98_000_053)->setCorporationName('Deleted Corp');
        $user = new User();
        $user->addCharacter($mainCharacter);
        $user->setMainCharacter($mainCharacter);

        $member = (new GroupIndustryProjectMember())
            ->setUser($user)
            ->setRole(GroupMemberRole::Admin)
            ->setStatus(GroupMemberStatus::Accepted);
        (new \ReflectionProperty(GroupIndustryProjectMember::class, 'id'))->setValue($member, Uuid::v4());
        $member->anonymize();

        return $member;
    }
}
