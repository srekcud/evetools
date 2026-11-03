<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Mining;

use ApiPlatform\Metadata\Put;
use App\ApiResource\Input\Mining\ReprocessingProfileInput;
use App\ApiResource\Mining\ReprocessingProfileResource;
use App\Enum\ReprocessingFailure;
use App\Enum\ReprocessingLocation;
use App\Enum\ReprocessingRigTier;
use App\Enum\ReprocessingSecurity;
use App\Exception\EsiApiException;
use App\State\Processor\Mining\SaveReprocessingProfileProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(SaveReprocessingProfileProcessor::class)]
#[Group('issue-269')]
final class SaveReprocessingProfileProcessorTest extends TestCase
{
    use ReprocessingProfileHarness;

    protected function setUp(): void
    {
        $this->setUpReprocessingProfileHarness();
    }

    public function testSavesAnNpcStationProfileWithTheYieldComputedFromTheCharacterSkills(): void
    {
        $profile = $this->save($this->input(self::PILOT_ONE_ID, self::SIMPLE_ORE_PROCESSING, 'npc_station', null, null, 4));

        $this->assertSame(
            [true, self::PILOT_ONE_ID, self::SIMPLE_ORE_PROCESSING, 'npc_station', null, null, 4, 5, 5, 5, null],
            [$profile->configured, $profile->characterId, $profile->oreProcessingSkillTypeId, $profile->location, $profile->rigTier, $profile->security, $profile->implantPercent, $profile->reprocessingLevel, $profile->reprocessingEfficiencyLevel, $profile->oreProcessingLevel, $profile->failure],
        );
        $this->assertEqualsWithDelta(0.72358, $profile->reprocessYield, self::YIELD_DELTA);
        $this->assertEquals(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')), $profile->computedAt);
        $this->assertSame(self::ORE_PROCESSING_SKILLS, $profile->oreProcessingSkills);
    }

    public function testStoresTheProfileOfTheUserWithTheReadLevelsAndTheComputedYield(): void
    {
        $this->save($this->input(self::PILOT_ONE_ID, self::SIMPLE_ORE_PROCESSING, 'npc_station', null, null, 4));

        $stored = $this->storedProfileOf($this->userA);
        $this->assertNotNull($stored);
        $this->assertCount(1, $this->storedProfiles);
        $this->assertGreaterThanOrEqual(1, $this->flushCount);
        $this->assertSame(
            [$this->pilotOne, self::SIMPLE_ORE_PROCESSING, ReprocessingLocation::NpcStation, null, null, 4, 5, 5, 5, null],
            [$stored->getCharacter(), $stored->getOreProcessingSkillTypeId(), $stored->getLocation(), $stored->getRigTier(), $stored->getSecurity(), $stored->getImplantPercent(), $stored->getReprocessingLevel(), $stored->getReprocessingEfficiencyLevel(), $stored->getOreProcessingLevel(), $stored->getFailure()],
        );
        $this->assertEqualsWithDelta(0.72358, $stored->getReprocessYield(), self::YIELD_DELTA);
        $this->assertEquals(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')), $stored->getComputedAt());
    }

    public function testATataraProfileUsesTheChosenCharacterAndTheLevelOfTheChosenOreProcessingSkill(): void
    {
        $profile = $this->save($this->input(self::PILOT_TWO_ID, self::COHERENT_ORE_PROCESSING, 'tatara', 't2', 'nullsec', 1));

        $this->assertSame(
            [self::PILOT_TWO_ID, self::COHERENT_ORE_PROCESSING, 'tatara', 't2', 'nullsec', 1, 4, 3, 2, null],
            [$profile->characterId, $profile->oreProcessingSkillTypeId, $profile->location, $profile->rigTier, $profile->security, $profile->implantPercent, $profile->reprocessingLevel, $profile->reprocessingEfficiencyLevel, $profile->oreProcessingLevel, $profile->failure],
        );
        $this->assertEqualsWithDelta(0.7809530995302404, $profile->reprocessYield, self::YIELD_DELTA);
        $this->assertSame([[self::PILOT_TWO_SKILLS_ENDPOINT, $this->pilotTwoToken]], $this->esiCalls);
    }

    public function testAnNpcStationProfileStoresNoRigNorSecurityAndIgnoresThemInTheYield(): void
    {
        $profile = $this->save($this->input(self::PILOT_ONE_ID, self::SIMPLE_ORE_PROCESSING, 'npc_station', 't2', 'nullsec', 4));

        $this->assertSame([null, null], [$profile->rigTier, $profile->security]);
        $this->assertEqualsWithDelta(0.72358, $profile->reprocessYield, self::YIELD_DELTA);
        $stored = $this->storedProfileOf($this->userA);
        $this->assertNotNull($stored);
        $this->assertSame([null, null], [$stored->getRigTier(), $stored->getSecurity()]);
    }

    public function testReadsTheSkillsWithASingleEsiCallWithTheCharacterToken(): void
    {
        $this->save($this->input(self::PILOT_ONE_ID, self::SIMPLE_ORE_PROCESSING, 'athanor', 't1', 'highsec', 0));

        $this->assertSame([[self::PILOT_ONE_SKILLS_ENDPOINT, $this->pilotOneToken]], $this->esiCalls);
    }

    public function testACharacterWithoutTokenSavesTheProfileWithAnUnknownYieldAndAMissingTokenFailure(): void
    {
        $this->pilotOne->setEveToken(null);

        $profile = $this->save($this->input(self::PILOT_ONE_ID, self::SIMPLE_ORE_PROCESSING, 'athanor', 't1', 'lowsec', 2));

        $this->assertSame(
            [true, 'athanor', 't1', 'lowsec', 2, null, null, null, null, 'missing_token'],
            [$profile->configured, $profile->location, $profile->rigTier, $profile->security, $profile->implantPercent, $profile->reprocessingLevel, $profile->reprocessingEfficiencyLevel, $profile->oreProcessingLevel, $profile->reprocessYield, $profile->failure],
        );
        $stored = $this->storedProfileOf($this->userA);
        $this->assertNotNull($stored);
        $this->assertSame(
            [ReprocessingLocation::Athanor, null, null, null, null, ReprocessingFailure::MissingToken],
            [$stored->getLocation(), $stored->getReprocessingLevel(), $stored->getReprocessingEfficiencyLevel(), $stored->getOreProcessingLevel(), $stored->getReprocessYield(), $stored->getFailure()],
        );
        $this->assertGreaterThanOrEqual(1, $this->flushCount);
        $this->assertSame([], $this->esiCalls);
    }

    public function testATokenWithoutTheSkillsScopeSavesTheProfileWithAnUnknownYieldAndAMissingScopeFailure(): void
    {
        $this->pilotOneToken->setScopes([self::MINING_SCOPE]);

        $profile = $this->save($this->input(self::PILOT_ONE_ID, self::SIMPLE_ORE_PROCESSING, 'npc_station', null, null, 4));

        $this->assertSame(
            [null, null, null, null, 'missing_scope'],
            [$profile->reprocessingLevel, $profile->reprocessingEfficiencyLevel, $profile->oreProcessingLevel, $profile->reprocessYield, $profile->failure],
        );
        $this->assertSame(ReprocessingFailure::MissingScope, $this->storedProfileOf($this->userA)?->getFailure());
        $this->assertSame([], $this->esiCalls);
    }

    #[DataProvider('esiFailures')]
    public function testAnEsiFailureSavesTheProfileWithAnUnknownYieldAndAnEsiErrorFailure(?\Throwable $failure, ?array $response): void
    {
        if ($failure !== null) {
            $this->esiFailuresByEndpoint[self::PILOT_ONE_SKILLS_ENDPOINT] = $failure;
        }
        if ($response !== null) {
            $this->esiResponsesByEndpoint[self::PILOT_ONE_SKILLS_ENDPOINT] = $response;
        }

        $profile = $this->save($this->input(self::PILOT_ONE_ID, self::SIMPLE_ORE_PROCESSING, 'tatara', 't2', 'nullsec', 4));

        $this->assertSame(
            [true, null, null, null, null, 'esi_error'],
            [$profile->configured, $profile->reprocessingLevel, $profile->reprocessingEfficiencyLevel, $profile->oreProcessingLevel, $profile->reprocessYield, $profile->failure],
        );
        $this->assertSame(ReprocessingFailure::EsiError, $this->storedProfileOf($this->userA)?->getFailure());
        $this->assertNull($this->storedProfileOf($this->userA)?->getReprocessYield());
    }

    public static function esiFailures(): iterable
    {
        yield 'ESI unavailable' => [EsiApiException::fromResponse(503, 'Service Unavailable', self::PILOT_ONE_SKILLS_ENDPOINT), null];
        yield 'ESI rate limited' => [EsiApiException::rateLimited(endpoint: self::PILOT_ONE_SKILLS_ENDPOINT), null];
        yield 'response without skills list' => [null, ['total_sp' => 48_000_000]];
    }

    #[DataProvider('charactersOutsideTheUser')]
    public function testACharacterThatDoesNotBelongToTheUserIsNotFoundAndNothingIsSaved(int $characterId): void
    {
        $this->expectException(NotFoundHttpException::class);

        try {
            $this->save($this->input($characterId, self::SIMPLE_ORE_PROCESSING, 'npc_station', null, null, 4));
        } finally {
            $this->assertSame([], $this->storedProfiles);
            $this->assertSame(0, $this->flushCount);
            $this->assertSame([], $this->esiCalls);
        }
    }

    public static function charactersOutsideTheUser(): iterable
    {
        yield 'character of another user' => [self::OTHER_USER_PILOT_ID];
        yield 'unknown character' => [self::UNKNOWN_PILOT_ID];
    }

    #[DataProvider('invalidInputs')]
    public function testAnInvalidInputIsABadRequestAndNothingIsSaved(?int $oreProcessingSkillTypeId, ?string $location, ?string $rigTier, ?string $security, ?int $implantPercent): void
    {
        $this->expectException(BadRequestHttpException::class);

        try {
            $this->save($this->input(self::PILOT_ONE_ID, $oreProcessingSkillTypeId, $location, $rigTier, $security, $implantPercent));
        } finally {
            $this->assertSame([], $this->storedProfiles);
            $this->assertSame(0, $this->flushCount);
            $this->assertSame([], $this->esiCalls);
        }
    }

    public static function invalidInputs(): iterable
    {
        yield 'skill that processes no ore of the table' => [self::MINING, 'npc_station', null, null, 4];
        yield 'no ore processing skill' => [null, 'npc_station', null, null, 4];
        yield 'unknown location' => [self::SIMPLE_ORE_PROCESSING, 'foo', null, null, 4];
        yield 'no location' => [self::SIMPLE_ORE_PROCESSING, null, null, null, 4];
        yield 'implant of 3 %' => [self::SIMPLE_ORE_PROCESSING, 'npc_station', null, null, 3];
        yield 'implant of 5 %' => [self::SIMPLE_ORE_PROCESSING, 'npc_station', null, null, 5];
        yield 'no implant' => [self::SIMPLE_ORE_PROCESSING, 'npc_station', null, null, null];
        yield 'Tatara without rig tier' => [self::SIMPLE_ORE_PROCESSING, 'tatara', null, 'nullsec', 4];
        yield 'Athanor without security' => [self::SIMPLE_ORE_PROCESSING, 'athanor', 't1', null, 4];
        yield 'unknown rig tier' => [self::SIMPLE_ORE_PROCESSING, 'athanor', 't3', 'highsec', 4];
        yield 'unknown security' => [self::SIMPLE_ORE_PROCESSING, 'tatara', 't1', 'wormhole', 4];
    }

    public function testASecondSaveOfTheSameUserUpdatesItsSingleProfile(): void
    {
        $this->save($this->input(self::PILOT_ONE_ID, self::SIMPLE_ORE_PROCESSING, 'npc_station', null, null, 4));
        $firstProfile = $this->storedProfileOf($this->userA);

        $profile = $this->save($this->input(self::PILOT_TWO_ID, self::COHERENT_ORE_PROCESSING, 'tatara', 't2', 'nullsec', 1));

        $this->assertCount(1, $this->storedProfiles);
        $this->assertSame($firstProfile, $this->storedProfileOf($this->userA));
        $this->assertSame(
            [$this->pilotTwo, self::COHERENT_ORE_PROCESSING, ReprocessingLocation::Tatara, ReprocessingRigTier::T2, ReprocessingSecurity::Nullsec, 1, 4, 3, 2],
            [$firstProfile?->getCharacter(), $firstProfile?->getOreProcessingSkillTypeId(), $firstProfile?->getLocation(), $firstProfile?->getRigTier(), $firstProfile?->getSecurity(), $firstProfile?->getImplantPercent(), $firstProfile?->getReprocessingLevel(), $firstProfile?->getReprocessingEfficiencyLevel(), $firstProfile?->getOreProcessingLevel()],
        );
        $this->assertEqualsWithDelta(0.7809530995302404, $profile->reprocessYield, self::YIELD_DELTA);
    }

    public function testASaveThatReadsTheSkillsClearsThePreviousFailure(): void
    {
        $this->storeProfile($this->userA, $this->pilotOne, self::SIMPLE_ORE_PROCESSING, ReprocessingLocation::NpcStation, null, null, 4, null, null, ReprocessingFailure::MissingToken);

        $profile = $this->save($this->input(self::PILOT_ONE_ID, self::SIMPLE_ORE_PROCESSING, 'npc_station', null, null, 4));

        $this->assertNull($profile->failure);
        $this->assertEqualsWithDelta(0.72358, $profile->reprocessYield, self::YIELD_DELTA);
        $this->assertNull($this->storedProfileOf($this->userA)?->getFailure());
        $this->assertCount(1, $this->storedProfiles);
    }

    public function testASaveOfAnotherUserLeavesTheFirstUserProfileUntouched(): void
    {
        $profileOfA = $this->storeProfile($this->userA, $this->pilotTwo, self::COHERENT_ORE_PROCESSING, ReprocessingLocation::Athanor, ReprocessingRigTier::T1, ReprocessingSecurity::Lowsec, 2, [4, 3, 2], 0.6445970107372803, null);
        $this->currentUser = $this->userB;

        $profileOfB = $this->save($this->input(self::OTHER_USER_PILOT_ID, self::SIMPLE_ORE_PROCESSING, 'npc_station', null, null, 0));

        $this->assertCount(2, $this->storedProfiles);
        $this->assertSame($profileOfA, $this->storedProfileOf($this->userA));
        $this->assertSame(
            [ReprocessingLocation::Athanor, 2, 0.6445970107372803],
            [$profileOfA->getLocation(), $profileOfA->getImplantPercent(), $profileOfA->getReprocessYield()],
        );
        $this->assertSame([self::OTHER_USER_PILOT_ID, 2, 1, 3], [$profileOfB->characterId, $profileOfB->reprocessingLevel, $profileOfB->reprocessingEfficiencyLevel, $profileOfB->oreProcessingLevel]);
        $this->assertEqualsWithDelta(0.573036, $profileOfB->reprocessYield, self::YIELD_DELTA);
    }

    private function save(ReprocessingProfileInput $input): ReprocessingProfileResource
    {
        return $this->saveProcessor()->process($input, new Put(uriTemplate: '/mining/reprocessing-profile'));
    }
}
