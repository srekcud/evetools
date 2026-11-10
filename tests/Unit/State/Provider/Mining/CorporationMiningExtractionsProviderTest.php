<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Mining;

use ApiPlatform\Metadata\Get;
use App\Entity\MoonRefineryFollow;
use App\Entity\User;
use App\Enum\CorporationMiningSyncFailure;
use App\State\Provider\Mining\CorporationMiningExtractionsProvider;
use App\Tests\Unit\Service\Sync\CorporationMiningExtractionHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CorporationMiningExtractionsProvider::class)]
#[Group('issue-274')]
final class CorporationMiningExtractionsProviderTest extends TestCase
{
    use CorporationMiningExtractionHarness;

    private const string ROCK_HOLLOW_UUID = '0195f3a0-2740-7000-8000-000000000101';
    private const string DEEP_CORE_UUID = '0195f3a0-2740-7000-8000-000000000102';
    private const string READY_UUID = '0195f3a0-2740-7000-8000-000000000103';
    private const string THIRTEEN_DAYS_UUID = '0195f3a0-2740-7000-8000-000000000104';
    private const string FIFTEEN_DAYS_UUID = '0195f3a0-2740-7000-8000-000000000105';
    private const string CORPORATION_B_UUID = '0195f3a0-2740-7000-8000-000000000201';
    private const string CORPORATION_C_UUID = '0195f3a0-2740-7000-8000-000000000301';

    private const int GLOW_REFINERY_ID = 1_035_000_000_201;
    private const int GLOW_MOON_ID = 40_401_004;

    protected function setUp(): void
    {
        $this->setUpCorporationMiningHarness();
        $this->stationManager($this->userA, 'Ilyra Castell', 2_112_274_001, self::CORPORATION_A);
        $this->addCharacter($this->userA, 'Tovan Reske', 2_112_274_002, self::CORPORATION_B, [], []);
        $this->addCharacter($this->userA, 'Lysa Moreau', 2_112_274_005, self::CORPORATION_A, [], []);
        $this->stationManager($this->userC, 'Sora Vantel', 2_112_274_004, self::CORPORATION_C);

        $this->storeExtraction(self::CORPORATION_A, self::DEEP_CORE_ID, self::IHAKANA_MOON_ID, self::DEEP_CORE_START, self::DEEP_CORE_ARRIVAL, self::DEEP_CORE_DECAY, self::DEEP_CORE_NAME, self::IHAKANA_SYSTEM_ID, self::IHAKANA_SYSTEM_NAME, self::IHAKANA_MOON_NAME, self::DEEP_CORE_UUID);
        $this->storeExtraction(self::CORPORATION_A, self::ROCK_HOLLOW_ID, self::NOL_M9_MOON_ID, '2026-10-12T19:00:00+00:00', '2026-10-18T19:00:00+00:00', '2026-10-21T19:00:00+00:00', self::ROCK_HOLLOW_NAME, self::NOL_M9_SYSTEM_ID, self::NOL_M9_SYSTEM_NAME, self::NOL_M9_MOON_NAME, self::FIFTEEN_DAYS_UUID);
        $this->storeExtraction(self::CORPORATION_A, self::ROCK_HOLLOW_ID, self::NOL_M9_MOON_ID, self::ROCK_HOLLOW_START, self::ROCK_HOLLOW_ARRIVAL, self::ROCK_HOLLOW_DECAY, self::ROCK_HOLLOW_NAME, self::NOL_M9_SYSTEM_ID, self::NOL_M9_SYSTEM_NAME, self::NOL_M9_MOON_NAME, self::ROCK_HOLLOW_UUID);
        $this->storeExtraction(self::CORPORATION_A, self::ROCK_HOLLOW_ID, self::NOL_M9_MOON_ID, '2026-10-14T19:00:00+00:00', '2026-10-20T19:00:00+00:00', '2026-10-23T19:00:00+00:00', self::ROCK_HOLLOW_NAME, self::NOL_M9_SYSTEM_ID, self::NOL_M9_SYSTEM_NAME, self::NOL_M9_MOON_NAME, self::THIRTEEN_DAYS_UUID);
        $this->storeExtraction(self::CORPORATION_A, self::DEEP_CORE_ID, 40_302_003, '2026-10-29T17:00:00+00:00', '2026-11-05T17:00:00+00:00', '2026-11-05T20:00:00+00:00', self::DEEP_CORE_NAME, self::IHAKANA_SYSTEM_ID, self::IHAKANA_SYSTEM_NAME, null, self::READY_UUID);
        $this->storeExtraction(self::CORPORATION_B, self::GLOW_REFINERY_ID, self::GLOW_MOON_ID, '2026-11-01T08:00:00+00:00', '2026-11-07T08:00:00+00:00', '2026-11-07T11:00:00+00:00', null, null, null, 'Ostingele IV - Moon 2', self::CORPORATION_B_UUID);
        $this->storeExtraction(self::CORPORATION_C, 1_035_000_000_301, 40_501_001, '2026-11-03T00:00:00+00:00', '2026-11-06T00:00:00+00:00', '2026-11-06T03:00:00+00:00', 'Corporation C refinery', 30_002_187, 'Amarr', 'Amarr VIII - Moon 1', self::CORPORATION_C_UUID);

        $this->storeSyncState(self::CORPORATION_A, '2026-11-05T18:30:00+00:00', '2026-11-05T18:30:00+00:00', null);
        $this->storeSyncState(self::CORPORATION_B, '2026-11-04T10:00:00+00:00', '2026-11-05T18:30:00+00:00', CorporationMiningSyncFailure::NoStationManagerToken);
        $this->storeSyncState(self::CORPORATION_C, '2026-11-05T18:00:00+00:00', '2026-11-05T18:00:00+00:00', null);
    }

    public function testAUserSeesOneEntryPerCorporationOfHisCharactersEachWithItsOwnState(): void
    {
        self::assertSame(
            [
                [self::CORPORATION_A, 'ok', '2026-11-05T18:30:00+00:00'],
                [self::CORPORATION_B, 'denied', '2026-11-04T10:00:00+00:00'],
            ],
            array_map(
                static fn (array $corporation): array => [$corporation['corporationId'], $corporation['access'], $corporation['lastSuccessAt']],
                $this->provide(),
            ),
        );
    }

    public function testACharacterInANpcCorporationAddsNoEntry(): void
    {
        $this->addCharacter($this->userA, 'Mara Venn', 2_112_582_001, self::NPC_CORPORATION, [], []);

        self::assertSame(
            [self::CORPORATION_A, self::CORPORATION_B],
            array_column($this->provide(), 'corporationId'),
        );
    }

    public function testACorporationAtTheFirstPlayerCorporationIdIsStillListed(): void
    {
        $this->addCharacter($this->userA, 'Mara Venn', 2_112_582_001, self::FIRST_PLAYER_CORPORATION, [], []);

        self::assertSame(
            [self::FIRST_PLAYER_CORPORATION, self::CORPORATION_A, self::CORPORATION_B],
            array_column($this->provide(), 'corporationId'),
        );
    }

    public function testEachExtractionIsExposedWithItsNamesTimesAndStatusSortedByChunkArrival(): void
    {
        self::assertSame(
            [
                self::extractionView(self::THIRTEEN_DAYS_UUID, self::ROCK_HOLLOW_ID, self::ROCK_HOLLOW_NAME, self::NOL_M9_SYSTEM_ID, self::NOL_M9_SYSTEM_NAME, self::NOL_M9_MOON_ID, self::NOL_M9_MOON_NAME, '2026-10-14T19:00:00+00:00', '2026-10-20T19:00:00+00:00', '2026-10-23T19:00:00+00:00', 'exploded'),
                self::extractionView(self::READY_UUID, self::DEEP_CORE_ID, self::DEEP_CORE_NAME, self::IHAKANA_SYSTEM_ID, self::IHAKANA_SYSTEM_NAME, 40_302_003, null, '2026-10-29T17:00:00+00:00', '2026-11-05T17:00:00+00:00', '2026-11-05T20:00:00+00:00', 'ready'),
                self::extractionView(self::ROCK_HOLLOW_UUID, self::ROCK_HOLLOW_ID, self::ROCK_HOLLOW_NAME, self::NOL_M9_SYSTEM_ID, self::NOL_M9_SYSTEM_NAME, self::NOL_M9_MOON_ID, self::NOL_M9_MOON_NAME, self::ROCK_HOLLOW_START, self::ROCK_HOLLOW_ARRIVAL, self::ROCK_HOLLOW_DECAY, 'extracting'),
                self::extractionView(self::DEEP_CORE_UUID, self::DEEP_CORE_ID, self::DEEP_CORE_NAME, self::IHAKANA_SYSTEM_ID, self::IHAKANA_SYSTEM_NAME, self::IHAKANA_MOON_ID, self::IHAKANA_MOON_NAME, self::DEEP_CORE_START, self::DEEP_CORE_ARRIVAL, self::DEEP_CORE_DECAY, 'extracting'),
            ],
            $this->corporationEntry(self::CORPORATION_A)['extractions'],
        );
    }

    public function testAnExtractionExplodedFifteenDaysAgoIsHiddenAndOneExplodedThirteenDaysAgoIsShown(): void
    {
        $ids = array_column($this->corporationEntry(self::CORPORATION_A)['extractions'], 'id');

        self::assertNotContains(self::FIFTEEN_DAYS_UUID, $ids);
        self::assertContains(self::THIRTEEN_DAYS_UUID, $ids);
    }

    public function testTheFourteenDayWindowIncludesItsExactBoundAndExcludesTheSecondBefore(): void
    {
        $this->storeExtraction(self::CORPORATION_B, self::GLOW_REFINERY_ID, self::GLOW_MOON_ID, '2026-10-13T19:00:00+00:00', '2026-10-19T19:00:00+00:00', '2026-10-22T19:00:00+00:00', null, null, null, null, '0195f3a0-2740-7000-8000-000000000202');
        $this->storeExtraction(self::CORPORATION_B, self::GLOW_REFINERY_ID, self::GLOW_MOON_ID, '2026-10-13T18:59:59+00:00', '2026-10-19T18:59:59+00:00', '2026-10-22T18:59:59+00:00', null, null, null, null, '0195f3a0-2740-7000-8000-000000000203');

        self::assertSame(
            ['0195f3a0-2740-7000-8000-000000000202', self::CORPORATION_B_UUID],
            array_column($this->corporationEntry(self::CORPORATION_B)['extractions'], 'id'),
        );
    }

    public function testADeniedCorporationStillExposesItsRecordedExtractions(): void
    {
        self::assertSame(
            [self::extractionView(self::CORPORATION_B_UUID, self::GLOW_REFINERY_ID, null, null, null, self::GLOW_MOON_ID, 'Ostingele IV - Moon 2', '2026-11-01T08:00:00+00:00', '2026-11-07T08:00:00+00:00', '2026-11-07T11:00:00+00:00', 'extracting')],
            $this->corporationEntry(self::CORPORATION_B)['extractions'],
        );
    }

    public function testTheStatusFollowsTheClockOfTheRead(): void
    {
        $this->clock->modify('2026-11-05 20:30:00');

        self::assertSame(
            ['exploded', 'exploded', 'ready', 'extracting'],
            array_column($this->corporationEntry(self::CORPORATION_A)['extractions'], 'status'),
        );
    }

    public function testEachCorporationListsTheRefineriesTheUserFollowsThere(): void
    {
        $this->storeFollow($this->userA, self::CORPORATION_A, self::ROCK_HOLLOW_ID, '2026-11-04T12:00:00+00:00');
        $this->storeFollow($this->userB, self::CORPORATION_A, self::DEEP_CORE_ID, '2026-11-04T12:00:00+00:00');

        self::assertSame(
            [[self::CORPORATION_A, [self::ROCK_HOLLOW_ID]], [self::CORPORATION_B, []]],
            array_map(
                static fn (array $corporation): array => [$corporation['corporationId'], $corporation['followedStructureIds']],
                $this->provide(),
            ),
        );
    }

    public function testAUserOfAnotherCorporationSeesNeitherOfTheTwo(): void
    {
        $this->currentUser = $this->userC;

        self::assertSame([self::CORPORATION_C], array_column($this->provide(), 'corporationId'));
        self::assertSame([self::CORPORATION_C_UUID], array_column($this->corporationEntry(self::CORPORATION_C)['extractions'], 'id'));
    }

    public function testAUserWithoutCharacterSeesNoCorporation(): void
    {
        $this->currentUser = $this->userB;

        self::assertSame([], $this->provide());
    }

    public function testACharacterMovedToAnotherCorporationTakesItsUserAwayFromTheOldOne(): void
    {
        foreach ($this->userA->getCharacters() as $character) {
            $character->setCorporationId(self::CORPORATION_C);
        }

        self::assertSame([self::CORPORATION_C], array_column($this->provide(), 'corporationId'));
    }

    public static function syncStates(): iterable
    {
        yield 'no state' => [null, false, 'never_synced'];
        yield 'last failure null' => [null, true, 'ok'];
        yield 'no station manager token' => [CorporationMiningSyncFailure::NoStationManagerToken, true, 'denied'];
        yield 'esi error' => [CorporationMiningSyncFailure::EsiError, true, 'error'];
        yield 'reconnect required' => [CorporationMiningSyncFailure::ReconnectRequired, true, 'reconnect'];
    }

    #[DataProvider('syncStates')]
    public function testTheAccessIsDerivedFromTheSyncState(?CorporationMiningSyncFailure $lastFailure, bool $hasState, string $expectedAccess): void
    {
        $this->storedSyncStates = [];
        if ($hasState) {
            $this->storeSyncState(self::CORPORATION_A, '2026-11-05T16:00:00+00:00', '2026-11-05T18:30:00+00:00', $lastFailure);
        }

        $entry = $this->corporationEntry(self::CORPORATION_A);

        self::assertSame(
            [$expectedAccess, $hasState ? '2026-11-05T16:00:00+00:00' : null, 4],
            [$entry['access'], $entry['lastSuccessAt'], count($entry['extractions'])],
        );
    }

    public function testAFailureWithoutAnyPreviousSuccessHasNoLastSuccess(): void
    {
        $this->storedSyncStates = [];
        $this->storeSyncState(self::CORPORATION_A, null, '2026-11-05T18:30:00+00:00', CorporationMiningSyncFailure::EsiError);

        $entry = $this->corporationEntry(self::CORPORATION_A);

        self::assertSame(['error', null], [$entry['access'], $entry['lastSuccessAt']]);
    }

    private function storeFollow(User $user, int $corporationId, int $structureId, string $createdAt): void
    {
        $this->storedFollows[] = (new MoonRefineryFollow())
            ->setUser($user)
            ->setCorporationId($corporationId)
            ->setStructureId($structureId)
            ->setCreatedAt(new \DateTimeImmutable($createdAt));
    }

    private function provide(): array
    {
        return $this->build(CorporationMiningExtractionsProvider::class)->provide(new Get())->corporations;
    }

    private function corporationEntry(int $corporationId): array
    {
        $entries = array_values(array_filter($this->provide(), static fn (array $entry): bool => $entry['corporationId'] === $corporationId));
        self::assertCount(1, $entries);

        return [...$entries[0], 'extractions' => array_map(static fn (array $extraction): array => array_diff_key($extraction, ['chunkValue' => true, 'linkedOperation' => true, 'linkedOperationCount' => true]), $entries[0]['extractions'])];
    }

    private static function extractionView(
        string $id,
        int $structureId,
        ?string $structureName,
        ?int $solarSystemId,
        ?string $solarSystemName,
        int $moonId,
        ?string $moonName,
        string $start,
        string $arrival,
        string $decay,
        string $status,
    ): array {
        return [
            'id' => $id,
            'structureId' => $structureId,
            'structureName' => $structureName,
            'solarSystemId' => $solarSystemId,
            'solarSystemName' => $solarSystemName,
            'moonId' => $moonId,
            'moonName' => $moonName,
            'extractionStartTime' => $start,
            'chunkArrivalTime' => $arrival,
            'naturalDecayTime' => $decay,
            'status' => $status,
        ];
    }
}
