<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Mining;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Mining\ReprocessingProfileResource;
use App\Enum\ReprocessingFailure;
use App\Enum\ReprocessingLocation;
use App\Enum\ReprocessingRigTier;
use App\Enum\ReprocessingSecurity;
use App\State\Provider\Mining\ReprocessingProfileProvider;
use App\Tests\Unit\State\Processor\Mining\ReprocessingProfileHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReprocessingProfileProvider::class)]
#[Group('issue-269')]
final class ReprocessingProfileProviderTest extends TestCase
{
    use ReprocessingProfileHarness;

    protected function setUp(): void
    {
        $this->setUpReprocessingProfileHarness();
    }

    public function testWithoutProfileTheResponseIsNotConfiguredAndListsTheOreProcessingSkillsByName(): void
    {
        $profile = $this->provide();

        $this->assertSame(
            [false, null, null, null, null, null, null, null, null, null, null, null, null],
            [$profile->configured, $profile->characterId, $profile->oreProcessingSkillTypeId, $profile->location, $profile->rigTier, $profile->security, $profile->implantPercent, $profile->reprocessingLevel, $profile->reprocessingEfficiencyLevel, $profile->oreProcessingLevel, $profile->reprocessYield, $profile->failure, $profile->computedAt],
        );
        $this->assertSame(self::ORE_PROCESSING_SKILLS, $profile->oreProcessingSkills);
    }

    public function testReturnsTheSavedProfileWithoutCallingEsi(): void
    {
        $this->storeProfile($this->userA, $this->pilotTwo, self::COHERENT_ORE_PROCESSING, ReprocessingLocation::Athanor, ReprocessingRigTier::T1, ReprocessingSecurity::Lowsec, 2, [4, 3, 2], 0.6445970107372803, null);

        $profile = $this->provide();

        $this->assertSame(
            [true, self::PILOT_TWO_ID, self::COHERENT_ORE_PROCESSING, 'athanor', 't1', 'lowsec', 2, 4, 3, 2, 0.6445970107372803, null],
            [$profile->configured, $profile->characterId, $profile->oreProcessingSkillTypeId, $profile->location, $profile->rigTier, $profile->security, $profile->implantPercent, $profile->reprocessingLevel, $profile->reprocessingEfficiencyLevel, $profile->oreProcessingLevel, $profile->reprocessYield, $profile->failure],
        );
        $this->assertEquals(new \DateTimeImmutable(self::YESTERDAY, new \DateTimeZone('UTC')), $profile->computedAt);
        $this->assertSame(self::ORE_PROCESSING_SKILLS, $profile->oreProcessingSkills);
        $this->assertSame([], $this->esiCalls);
    }

    public function testASavedProfileWhoseSkillsWereUnreadableKeepsAnUnknownYieldAndItsFailure(): void
    {
        $this->storeProfile($this->userA, $this->pilotOne, self::ICE_PROCESSING, ReprocessingLocation::NpcStation, null, null, 1, null, null, ReprocessingFailure::MissingScope);

        $profile = $this->provide();

        $this->assertSame(
            [true, self::PILOT_ONE_ID, self::ICE_PROCESSING, 'npc_station', null, null, 1, null, null, null, null, 'missing_scope'],
            [$profile->configured, $profile->characterId, $profile->oreProcessingSkillTypeId, $profile->location, $profile->rigTier, $profile->security, $profile->implantPercent, $profile->reprocessingLevel, $profile->reprocessingEfficiencyLevel, $profile->oreProcessingLevel, $profile->reprocessYield, $profile->failure],
        );
    }

    public function testAnotherUserNeverSeesTheProfileOfTheFirstUser(): void
    {
        $this->storeProfile($this->userA, $this->pilotOne, self::SIMPLE_ORE_PROCESSING, ReprocessingLocation::Tatara, ReprocessingRigTier::T2, ReprocessingSecurity::Nullsec, 4, [5, 5, 5], 0.9062810556800002, null);
        $this->currentUser = $this->userB;

        $profile = $this->provide();

        $this->assertSame(
            [false, null, null, null, null],
            [$profile->configured, $profile->characterId, $profile->location, $profile->reprocessYield, $profile->failure],
        );
        $this->assertSame(self::ORE_PROCESSING_SKILLS, $profile->oreProcessingSkills);
    }

    private function provide(): ReprocessingProfileResource
    {
        return $this->reprocessingProfileProvider()->provide(new Get(uriTemplate: '/mining/reprocessing-profile'));
    }
}
