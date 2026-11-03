<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Mining;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\Mining\ReprocessingProfileResource;
use App\Enum\ReprocessingFailure;
use App\Enum\ReprocessingLocation;
use App\Enum\ReprocessingRigTier;
use App\Enum\ReprocessingSecurity;
use App\Exception\EsiApiException;
use App\State\Processor\Mining\RefreshReprocessingProfileProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(RefreshReprocessingProfileProcessor::class)]
#[Group('issue-269')]
final class RefreshReprocessingProfileProcessorTest extends TestCase
{
    use ReprocessingProfileHarness;

    protected function setUp(): void
    {
        $this->setUpReprocessingProfileHarness();
    }

    public function testRereadsTheSkillsAndRecomputesTheYieldWithTheSavedSettings(): void
    {
        $this->storeProfile($this->userA, $this->pilotOne, self::SIMPLE_ORE_PROCESSING, ReprocessingLocation::Athanor, ReprocessingRigTier::T1, ReprocessingSecurity::Highsec, 0, [4, 3, 5], 0.679339584, null);

        $profile = $this->refresh();

        $this->assertSame(
            [true, self::PILOT_ONE_ID, self::SIMPLE_ORE_PROCESSING, 'athanor', 't1', 'highsec', 0, 5, 5, 5, null],
            [$profile->configured, $profile->characterId, $profile->oreProcessingSkillTypeId, $profile->location, $profile->rigTier, $profile->security, $profile->implantPercent, $profile->reprocessingLevel, $profile->reprocessingEfficiencyLevel, $profile->oreProcessingLevel, $profile->failure],
        );
        $this->assertEqualsWithDelta(0.7238583, $profile->reprocessYield, self::YIELD_DELTA);
        $this->assertEquals(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')), $profile->computedAt);
        $this->assertSame(self::ORE_PROCESSING_SKILLS, $profile->oreProcessingSkills);
        $this->assertSame([[self::PILOT_ONE_SKILLS_ENDPOINT, $this->pilotOneToken]], $this->esiCalls);
    }

    public function testStoresTheRecomputedYieldTheReadLevelsAndTheComputationTime(): void
    {
        $stored = $this->storeProfile($this->userA, $this->pilotTwo, self::COHERENT_ORE_PROCESSING, ReprocessingLocation::Tatara, ReprocessingRigTier::T2, ReprocessingSecurity::Nullsec, 1, [3, 3, 1], 0.75, null);

        $this->refresh();

        $this->assertCount(1, $this->storedProfiles);
        $this->assertGreaterThanOrEqual(1, $this->flushCount);
        $this->assertSame(
            [$this->pilotTwo, self::COHERENT_ORE_PROCESSING, ReprocessingLocation::Tatara, ReprocessingRigTier::T2, ReprocessingSecurity::Nullsec, 1, 4, 3, 2, null],
            [$stored->getCharacter(), $stored->getOreProcessingSkillTypeId(), $stored->getLocation(), $stored->getRigTier(), $stored->getSecurity(), $stored->getImplantPercent(), $stored->getReprocessingLevel(), $stored->getReprocessingEfficiencyLevel(), $stored->getOreProcessingLevel(), $stored->getFailure()],
        );
        $this->assertEqualsWithDelta(0.7809530995302404, $stored->getReprocessYield(), self::YIELD_DELTA);
        $this->assertEquals(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')), $stored->getComputedAt());
    }

    public function testAnNpcStationProfileStaysWithoutRigNorSecurity(): void
    {
        $this->storeProfile($this->userA, $this->pilotOne, self::SIMPLE_ORE_PROCESSING, ReprocessingLocation::NpcStation, null, null, 4, [5, 5, 4], 0.710424, null);

        $profile = $this->refresh();

        $this->assertSame(['npc_station', null, null], [$profile->location, $profile->rigTier, $profile->security]);
        $this->assertEqualsWithDelta(0.72358, $profile->reprocessYield, self::YIELD_DELTA);
    }

    public function testAnEsiErrorMakesThePreviouslyKnownYieldUnknown(): void
    {
        $stored = $this->storeProfile($this->userA, $this->pilotOne, self::SIMPLE_ORE_PROCESSING, ReprocessingLocation::NpcStation, null, null, 4, [5, 5, 5], 0.72358, null);
        $this->esiFailuresByEndpoint[self::PILOT_ONE_SKILLS_ENDPOINT] = EsiApiException::fromResponse(502, 'Bad Gateway', self::PILOT_ONE_SKILLS_ENDPOINT);

        $profile = $this->refresh();

        $this->assertSame(
            [true, null, null, null, null, 'esi_error'],
            [$profile->configured, $profile->reprocessingLevel, $profile->reprocessingEfficiencyLevel, $profile->oreProcessingLevel, $profile->reprocessYield, $profile->failure],
        );
        $this->assertSame(
            [null, null, null, null, ReprocessingFailure::EsiError],
            [$stored->getReprocessingLevel(), $stored->getReprocessingEfficiencyLevel(), $stored->getOreProcessingLevel(), $stored->getReprocessYield(), $stored->getFailure()],
        );
        $this->assertEquals(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')), $stored->getComputedAt());
    }

    public function testARevokedScopeMakesThePreviouslyKnownYieldUnknownWithoutCallingEsi(): void
    {
        $this->storeProfile($this->userA, $this->pilotTwo, self::SIMPLE_ORE_PROCESSING, ReprocessingLocation::Tatara, ReprocessingRigTier::None, ReprocessingSecurity::Lowsec, 2, [3, 2, 1], 0.6594600785760002, null);
        $this->pilotTwoToken->setScopes([]);

        $profile = $this->refresh();

        $this->assertSame([null, 'missing_scope'], [$profile->reprocessYield, $profile->failure]);
        $this->assertSame([], $this->esiCalls);
    }

    public function testARefreshThatReadsTheSkillsClearsThePreviousFailure(): void
    {
        $this->storeProfile($this->userA, $this->pilotTwo, self::SIMPLE_ORE_PROCESSING, ReprocessingLocation::NpcStation, null, null, 2, null, null, ReprocessingFailure::EsiError);

        $profile = $this->refresh();

        $this->assertSame([4, 3, 5, null], [$profile->reprocessingLevel, $profile->reprocessingEfficiencyLevel, $profile->oreProcessingLevel, $profile->failure]);
        $this->assertEqualsWithDelta(0.6660192, $profile->reprocessYield, self::YIELD_DELTA);
    }

    public function testRefreshWithoutProfileIsNotFoundAndCallsNoEsi(): void
    {
        $this->expectException(NotFoundHttpException::class);

        try {
            $this->refresh();
        } finally {
            $this->assertSame([], $this->esiCalls);
            $this->assertSame([], $this->storedProfiles);
        }
    }

    public function testRefreshOfAUserWithoutProfileNeverRefreshesTheProfileOfAnotherUser(): void
    {
        $profileOfA = $this->storeProfile($this->userA, $this->pilotOne, self::SIMPLE_ORE_PROCESSING, ReprocessingLocation::NpcStation, null, null, 4, [4, 4, 4], 0.70, null);
        $this->currentUser = $this->userB;

        try {
            $this->refresh();
            $this->fail('A user without profile must get a 404.');
        } catch (NotFoundHttpException) {
        }

        $this->assertSame([4, 0.70], [$profileOfA->getReprocessingLevel(), $profileOfA->getReprocessYield()]);
        $this->assertSame([], $this->esiCalls);
    }

    private function refresh(): ReprocessingProfileResource
    {
        return $this->refreshProcessor()->process(new EmptyInput(), new Post(uriTemplate: '/mining/reprocessing-profile/refresh'));
    }
}
