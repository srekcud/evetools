<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use App\ApiResource\Industry\Project\ProjectParticipantResource;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\State\Provider\Industry\Project\ProjectResourceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectResourceMapper::class)]
class ProjectResourceMapperTest extends TestCase
{
    private ProjectResourceMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ProjectResourceMapper();
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

        $member = new IndustryProjectParticipant();
        $member->setUser($user);
        $member->setRole(ParticipantRole::Member);
        $member->setStatus(ParticipantStatus::Accepted);

        $reflection = new \ReflectionProperty(IndustryProjectParticipant::class, 'id');
        $reflection->setValue($member, Uuid::v4());

        $resource = $this->mapper->memberToResource($member, 1500000.0, 5);

        $this->assertInstanceOf(ProjectParticipantResource::class, $resource);
        $this->assertSame(2119843655, $resource->characterId);
        $this->assertSame('Test Pilot', $resource->characterName);
        $this->assertSame(98000001, $resource->corporationId);
        $this->assertSame('member', $resource->role);
        $this->assertSame('accepted', $resource->status);
        $this->assertSame(1500000.0, $resource->totalContributionValue);
        $this->assertSame(5, $resource->contributionCount);
    }

    public function testMemberWithoutMainCharacterHasNoCharacterIdAndAnUnknownName(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn(null);
        $user->method('getCorporationId')->willReturn(null);

        $member = new IndustryProjectParticipant();
        $member->setUser($user);
        $member->setRole(ParticipantRole::Owner);
        $member->setStatus(ParticipantStatus::Accepted);

        $reflection = new \ReflectionProperty(IndustryProjectParticipant::class, 'id');
        $reflection->setValue($member, Uuid::v4());

        $resource = $this->mapper->memberToResource($member);

        $this->assertSame('Unknown', $resource->characterName);
        $this->assertNull($resource->characterId);
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
        $contribution = (new IndustryProjectContribution())
            ->setProject(new IndustryProject())
            ->setMember($this->anonymizedMember())
            ->setType(ContributionType::Material)
            ->setQuantity(12_000)
            ->setEstimatedValue(1_000_000.0)
            ->setStatus(ContributionStatus::Approved);
        (new \ReflectionProperty(IndustryProjectContribution::class, 'id'))->setValue($contribution, Uuid::v4());

        $resource = $this->mapper->contributionToResource($contribution);

        $this->assertNull($resource->memberCharacterName);
        $this->assertSame(1_000_000.0, $resource->estimatedValue);
        $this->assertSame(12_000, $resource->quantity);
    }

    #[Group('issue-363')]
    public function testContributionWithAnUnknownValueIsMappedWithANullValueNotZero(): void
    {
        $contribution = (new IndustryProjectContribution())
            ->setProject(new IndustryProject())
            ->setMember($this->anonymizedMember())
            ->setType(ContributionType::JobInstall)
            ->setQuantity(5)
            ->setEstimatedValue(null)
            ->setStatus(ContributionStatus::Approved);
        (new \ReflectionProperty(IndustryProjectContribution::class, 'id'))->setValue($contribution, Uuid::v4());

        $resource = $this->mapper->contributionToResource($contribution);

        $this->assertNull($resource->estimatedValue);
        $this->assertSame(5, $resource->quantity);
    }

    #[Group('issue-363')]
    public function testMemberCarryingAnUnknownContributionValueIsMappedWithANullTotalAndKeepsItsCount(): void
    {
        $resource = $this->mapper->memberToResource($this->anonymizedMember(), null, 2);

        $this->assertNull($resource->totalContributionValue);
        $this->assertSame(2, $resource->contributionCount);
    }

    #[Group('issue-538')]
    public function testMaterialContributionWithAValueCarriesThePercentileSourceOfTheDeposit(): void
    {
        $resource = $this->mapper->contributionToResource($this->contributionOf(ContributionType::Material, 500.0)->setValuedAtJitaPrice(true));

        $this->assertSame(500.0, $resource->estimatedValue);
        $this->assertSame(
            ['market' => 'jita', 'side' => 'sell', 'method' => 'percentile', 'coveragePercent' => null, 'percentile' => 5.0],
            $resource->estimatedValueSource,
        );
    }

    #[Group('issue-538')]
    public function testMaterialContributionWithAnUnknownValueHasNoValueSource(): void
    {
        $this->assertNull($this->mapper->contributionToResource($this->contributionOf(ContributionType::Material, null))->estimatedValueSource);
    }

    #[Group('issue-538')]
    public function testContributionOfAnotherTypeThanMaterialHasNoValueSourceEvenWithAValue(): void
    {
        foreach ([ContributionType::JobInstall, ContributionType::Bpc, ContributionType::LineRental, ContributionType::Courier] as $type) {
            $this->assertNull($this->mapper->contributionToResource($this->contributionOf($type, 500.0))->estimatedValueSource, $type->value);
        }
    }

    #[Group('issue-726')]
    public function testProjectWhoseOwnerIsTheOnlyAcceptedParticipantIsExposedAsSolo(): void
    {
        $owner = new User();
        $project = $this->projectOf($owner);
        $this->addParticipant($project, $owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->addParticipant($project, new User(), ParticipantRole::Member, ParticipantStatus::Pending);

        self::assertTrue($this->mapper->projectToResource($project, null, 1, 0.0, true)->solo);
    }

    #[Group('issue-726')]
    public function testProjectWithAnotherAcceptedParticipantIsNotExposedAsSolo(): void
    {
        $owner = new User();
        $project = $this->projectOf($owner);
        $this->addParticipant($project, $owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->addParticipant($project, new User(), ParticipantRole::Member, ParticipantStatus::Accepted);

        self::assertFalse($this->mapper->projectToResource($project, null, 2, 0.0, true)->solo);
    }

    private function projectOf(User $owner): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($owner);
        (new \ReflectionProperty(IndustryProject::class, 'id'))->setValue($project, Uuid::v4());

        return $project;
    }

    private function addParticipant(IndustryProject $project, User $user, ParticipantRole $role, ParticipantStatus $status): void
    {
        $project->addMember((new IndustryProjectParticipant())->setUser($user)->setRole($role)->setStatus($status));
    }

    private function contributionOf(ContributionType $type, ?float $estimatedValue): IndustryProjectContribution
    {
        $contribution = (new IndustryProjectContribution())
            ->setProject(new IndustryProject())
            ->setMember($this->anonymizedMember())
            ->setType($type)
            ->setQuantity(5)
            ->setEstimatedValue($estimatedValue)
            ->setStatus(ContributionStatus::Approved);
        (new \ReflectionProperty(IndustryProjectContribution::class, 'id'))->setValue($contribution, Uuid::v4());

        return $contribution;
    }

    private function anonymizedMember(): IndustryProjectParticipant
    {
        $mainCharacter = (new Character())->setEveCharacterId(2_112_000_053)->setName('Deleted Pilot')->setCorporationId(98_000_053)->setCorporationName('Deleted Corp');
        $user = new User();
        $user->addCharacter($mainCharacter);
        $user->setMainCharacter($mainCharacter);

        $member = (new IndustryProjectParticipant())
            ->setUser($user)
            ->setRole(ParticipantRole::Admin)
            ->setStatus(ParticipantStatus::Accepted);
        (new \ReflectionProperty(IndustryProjectParticipant::class, 'id'))->setValue($member, Uuid::v4());
        $member->anonymize();

        return $member;
    }
}
