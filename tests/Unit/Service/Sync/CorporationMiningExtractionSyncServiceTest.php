<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Entity\Character;
use App\Entity\CorporationMiningSyncState;
use App\Enum\CorporationMiningSyncFailure;
use App\Service\Sync\CorporationMiningExtractionSyncService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CorporationMiningExtractionSyncService::class)]
#[Group('issue-274')]
final class CorporationMiningExtractionSyncServiceTest extends TestCase
{
    use CorporationMiningExtractionHarness;

    private const int ILYRA_ID = 2_112_274_001;
    private const int TOVAN_ID = 2_112_274_002;
    private const int AUREL_ID = 2_112_274_003;
    private const int SORA_ID = 2_112_274_004;

    private const string EARLIER_SUCCESS = '2026-11-05T17:00:00+00:00';
    private const string EARLIER_ATTEMPT = '2026-11-05T18:30:00+00:00';
    private const string NOW_ATOM = '2026-11-05T19:00:00+00:00';
    private const string KEPT_EXTRACTION_ID = '0195f3a0-2740-7000-8000-000000000274';

    private Character $ilyra;
    private Character $aurel;

    protected function setUp(): void
    {
        $this->setUpCorporationMiningHarness();
        $this->ilyra = $this->stationManager($this->userA, 'Ilyra Castell', self::ILYRA_ID, self::CORPORATION_A);
        $this->stationManager($this->userA, 'Tovan Reske', self::TOVAN_ID, self::CORPORATION_B);
        $this->aurel = $this->stationManager($this->userB, 'Aurel Keth', self::AUREL_ID, self::CORPORATION_A);
        $this->stationManager($this->userC, 'Sora Vantel', self::SORA_ID, self::CORPORATION_C);
        $this->respondWithCorporationMoons(self::CORPORATION_A);
        $this->esiResponses[self::extractionsEndpoint(self::CORPORATION_A)] = [self::rockHollowExtraction(), self::deepCoreExtraction()];
    }

    public function testASuccessfulSyncStoresEachExtractionWithItsRefineryMoonAndSystemNames(): void
    {
        $this->sync(self::CORPORATION_A);

        self::assertSame([self::deepCoreRow(self::CORPORATION_A), self::rockHollowRow(self::CORPORATION_A)], $this->storedExtractionRows());
        self::assertSame(
            ['lastSuccessAt' => self::NOW_ATOM, 'lastAttemptAt' => self::NOW_ATOM, 'lastFailure' => null],
            $this->syncStateSummaryOf(self::CORPORATION_A),
        );
    }

    public function testTheSyncReturnsTheStoredSyncStateOfTheCorporation(): void
    {
        $returnedState = $this->sync(self::CORPORATION_A);

        self::assertSame($this->storedSyncStateOf(self::CORPORATION_A), $returnedState);
        self::assertSame(self::CORPORATION_A, $returnedState->getCorporationId());
    }

    public function testTheExtractionsAndTheRefineryNamesAreReadWithTheTokenOfTheLowestCharacterIdNotTheFirstByName(): void
    {
        $this->sync(self::CORPORATION_A);

        self::assertSame([self::ILYRA_ID], $this->charactersReading(self::extractionsEndpoint(self::CORPORATION_A)));
        self::assertSame([self::ILYRA_ID], $this->charactersReading(self::structuresEndpoint(self::CORPORATION_A)));
    }

    public function testAFirstStationManagerDeniedByEsiHandsTheReadOverToTheNextOne(): void
    {
        $this->failEsi(self::extractionsEndpoint(self::CORPORATION_A), 403, $this->ilyra);

        $this->sync(self::CORPORATION_A);

        self::assertSame([self::ILYRA_ID, self::AUREL_ID], $this->charactersReading(self::extractionsEndpoint(self::CORPORATION_A)));
        self::assertSame([self::AUREL_ID], $this->charactersReading(self::structuresEndpoint(self::CORPORATION_A)));
        self::assertSame([self::deepCoreRow(self::CORPORATION_A), self::rockHollowRow(self::CORPORATION_A)], $this->storedExtractionRows());
        self::assertSame(
            ['lastSuccessAt' => self::NOW_ATOM, 'lastAttemptAt' => self::NOW_ATOM, 'lastFailure' => null],
            $this->syncStateSummaryOf(self::CORPORATION_A),
        );
    }

    public static function otherEsiFailures(): iterable
    {
        yield 'server error' => [500];
        yield 'bad gateway' => [502];
        yield 'network error' => [0];
        yield 'error budget exhausted after the replay' => [420];
        yield 'rate limited after the replay' => [429];
    }

    #[DataProvider('otherEsiFailures')]
    public function testAnyOtherEsiFailureOfTheFirstStationManagerAlsoHandsTheReadOverToTheNextOne(int $statusCode): void
    {
        $this->failEsi(self::extractionsEndpoint(self::CORPORATION_A), $statusCode, $this->ilyra);

        $this->sync(self::CORPORATION_A);

        self::assertSame([self::ILYRA_ID, self::AUREL_ID], $this->charactersReading(self::extractionsEndpoint(self::CORPORATION_A)));
        self::assertSame(
            ['lastSuccessAt' => self::NOW_ATOM, 'lastAttemptAt' => self::NOW_ATOM, 'lastFailure' => null],
            $this->syncStateSummaryOf(self::CORPORATION_A),
        );
    }

    public function testACharacterWithoutTheStationManagerRoleIsNeverUsedToReadTheExtractions(): void
    {
        $this->esiResponses[sprintf('/characters/%d/roles/', self::ILYRA_ID)] = ['roles' => ['Accountant', 'Factory_Manager']];

        $this->sync(self::CORPORATION_A);

        self::assertSame([self::AUREL_ID], $this->charactersReading(self::extractionsEndpoint(self::CORPORATION_A)));
        self::assertSame([self::AUREL_ID], $this->charactersReading(self::structuresEndpoint(self::CORPORATION_A)));
    }

    public static function requiredScopes(): iterable
    {
        yield 'corporation mining' => [self::MINING_SCOPE];
        yield 'corporation structures' => [self::STRUCTURES_SCOPE];
        yield 'corporation roles' => [self::ROLES_SCOPE];
    }

    #[DataProvider('requiredScopes')]
    public function testACharacterWhoseTokenLacksOneOfTheThreeScopesIsNeverAskedAnything(string $missingScope): void
    {
        $this->ilyra->getEveToken()?->setScopes(array_values(array_diff(self::ALL_SCOPES, [$missingScope])));

        $this->sync(self::CORPORATION_A);

        self::assertSame([self::AUREL_ID], $this->charactersCallingEsi());
        self::assertSame([self::deepCoreRow(self::CORPORATION_A), self::rockHollowRow(self::CORPORATION_A)], $this->storedExtractionRows());
    }

    public function testWithoutAnyStationManagerTheFailureIsRecordedAndTheLastSuccessAndTheExtractionsAreKept(): void
    {
        $this->esiResponses[sprintf('/characters/%d/roles/', self::ILYRA_ID)] = ['roles' => ['Accountant']];
        $this->esiResponses[sprintf('/characters/%d/roles/', self::AUREL_ID)] = ['roles' => []];
        $this->storeSyncState(self::CORPORATION_A, self::EARLIER_SUCCESS, self::EARLIER_ATTEMPT, null);
        $this->storeKeptRockHollowExtraction();

        $this->sync(self::CORPORATION_A);

        self::assertSame([], $this->esiCallsTo(self::extractionsEndpoint(self::CORPORATION_A)));
        self::assertSame(
            ['lastSuccessAt' => self::EARLIER_SUCCESS, 'lastAttemptAt' => self::NOW_ATOM, 'lastFailure' => 'no_station_manager_token'],
            $this->syncStateSummaryOf(self::CORPORATION_A),
        );
        self::assertSame([self::keptRockHollowRow()], $this->storedExtractionRows());
        self::assertSame([], $this->removedEntities);
    }

    public function testACorporationWithoutAnyEvetoolsCharacterIsRecordedWithoutStationManagerToken(): void
    {
        $this->sync(98_000_009);

        self::assertSame([], $this->esiCalls);
        self::assertSame(
            ['lastSuccessAt' => null, 'lastAttemptAt' => self::NOW_ATOM, 'lastFailure' => 'no_station_manager_token'],
            $this->syncStateSummaryOf(98_000_009),
        );
        self::assertSame([], $this->storedExtractionRows());
    }

    public function testWhenEveryStationManagerFailsWithAServerErrorTheSyncFailsWithAnEsiErrorAndKeepsTheHistory(): void
    {
        $this->failEsi(self::extractionsEndpoint(self::CORPORATION_A), 500);
        $this->storeSyncState(self::CORPORATION_A, self::EARLIER_SUCCESS, self::EARLIER_ATTEMPT, null);
        $this->storeKeptRockHollowExtraction();

        $this->sync(self::CORPORATION_A);

        self::assertSame([self::ILYRA_ID, self::AUREL_ID], $this->charactersReading(self::extractionsEndpoint(self::CORPORATION_A)));
        self::assertSame(
            ['lastSuccessAt' => self::EARLIER_SUCCESS, 'lastAttemptAt' => self::NOW_ATOM, 'lastFailure' => 'esi_error'],
            $this->syncStateSummaryOf(self::CORPORATION_A),
        );
        self::assertSame([self::keptRockHollowRow()], $this->storedExtractionRows());
    }

    public function testWhenEveryStationManagerIsDeniedTheSyncFailsWithAnEsiError(): void
    {
        $this->failEsi(self::extractionsEndpoint(self::CORPORATION_A), 403);

        $this->sync(self::CORPORATION_A);

        self::assertSame([self::ILYRA_ID, self::AUREL_ID], $this->charactersReading(self::extractionsEndpoint(self::CORPORATION_A)));
        self::assertSame(
            ['lastSuccessAt' => null, 'lastAttemptAt' => self::NOW_ATOM, 'lastFailure' => 'esi_error'],
            $this->syncStateSummaryOf(self::CORPORATION_A),
        );
    }

    public static function unreadableExtractions(): iterable
    {
        $complete = self::deepCoreExtraction();
        foreach (['structure_id', 'moon_id', 'extraction_start_time', 'chunk_arrival_time', 'natural_decay_time'] as $field) {
            $withoutField = $complete;
            unset($withoutField[$field]);
            yield 'without ' . $field => [$withoutField];
        }
        yield 'chunk arrival time not a date' => [['chunk_arrival_time' => 'soon'] + $complete];
        yield 'natural decay time null' => [['natural_decay_time' => null] + $complete];
        yield 'structure id as a string' => [['structure_id' => '1035000000102'] + $complete];
        yield 'moon id as a float' => [['moon_id' => 40302002.5] + $complete];
    }

    #[DataProvider('unreadableExtractions')]
    public function testOneUnreadableExtractionMakesTheWholeResponseAnEsiErrorAndNothingIsWrittenOrChanged(array $unreadableExtraction): void
    {
        $this->esiResponses[self::extractionsEndpoint(self::CORPORATION_A)] = [
            ['chunk_arrival_time' => '2026-11-05T21:00:00Z'] + self::rockHollowExtraction(),
            $unreadableExtraction,
        ];
        $this->storeSyncState(self::CORPORATION_A, self::EARLIER_SUCCESS, self::EARLIER_ATTEMPT, null);
        $this->storeKeptRockHollowExtraction();

        $this->sync(self::CORPORATION_A);

        self::assertSame([self::keptRockHollowRow()], $this->storedExtractionRows());
        self::assertSame(
            ['lastSuccessAt' => self::EARLIER_SUCCESS, 'lastAttemptAt' => self::NOW_ATOM, 'lastFailure' => 'esi_error'],
            $this->syncStateSummaryOf(self::CORPORATION_A),
        );
    }

    public function testASecondSyncWithTheSameExtractionsKeepsOneRowEachAndDoesNotReadTheMoonsAgain(): void
    {
        $this->sync(self::CORPORATION_A);
        $this->clock->modify('+30 minutes');
        $this->sync(self::CORPORATION_A);

        self::assertSame([self::deepCoreRow(self::CORPORATION_A), self::rockHollowRow(self::CORPORATION_A)], $this->storedExtractionRows());
        self::assertCount(1, $this->esiCallsTo(self::moonEndpoint(self::NOL_M9_MOON_ID)));
        self::assertCount(1, $this->esiCallsTo(self::moonEndpoint(self::IHAKANA_MOON_ID)));
        self::assertSame(
            ['lastSuccessAt' => '2026-11-05T19:30:00+00:00', 'lastAttemptAt' => '2026-11-05T19:30:00+00:00', 'lastFailure' => null],
            $this->syncStateSummaryOf(self::CORPORATION_A),
        );
    }

    public function testAnAlreadyNamedMoonIsNotReadAgainForALaterExtraction(): void
    {
        $this->storeKeptRockHollowExtraction();
        $this->esiResponses[self::extractionsEndpoint(self::CORPORATION_A)] = [[
            'structure_id' => self::ROCK_HOLLOW_ID,
            'moon_id' => self::NOL_M9_MOON_ID,
            'extraction_start_time' => '2026-11-06T02:00:00Z',
            'chunk_arrival_time' => '2026-11-12T02:00:00Z',
            'natural_decay_time' => '2026-11-12T05:00:00Z',
        ]];

        $this->sync(self::CORPORATION_A);

        self::assertSame([], $this->esiCallsTo(self::moonEndpoint(self::NOL_M9_MOON_ID)));
        self::assertSame([
            self::keptRockHollowRow(),
            [
                'corporationId' => self::CORPORATION_A,
                'structureId' => self::ROCK_HOLLOW_ID,
                'structureName' => self::ROCK_HOLLOW_NAME,
                'solarSystemId' => self::NOL_M9_SYSTEM_ID,
                'solarSystemName' => self::NOL_M9_SYSTEM_NAME,
                'moonId' => self::NOL_M9_MOON_ID,
                'moonName' => 'NOL-M9 VII - Moon 3 (stored)',
                'extractionStartTime' => '2026-11-06T02:00:00+00:00',
                'chunkArrivalTime' => '2026-11-12T02:00:00+00:00',
                'naturalDecayTime' => '2026-11-12T05:00:00+00:00',
            ],
        ], $this->storedExtractionRows());
    }

    public function testTwoSuccessiveExtractionsOnTheSameMoonAreTwoRowsAndTheMoonIsReadOnce(): void
    {
        $this->esiResponses[self::extractionsEndpoint(self::CORPORATION_A)] = [
            self::rockHollowExtraction(),
            [
                'structure_id' => self::ROCK_HOLLOW_ID,
                'moon_id' => self::NOL_M9_MOON_ID,
                'extraction_start_time' => '2026-11-06T02:00:00Z',
                'chunk_arrival_time' => '2026-11-12T02:00:00Z',
                'natural_decay_time' => '2026-11-12T05:00:00Z',
            ],
        ];

        $this->sync(self::CORPORATION_A);

        self::assertSame(
            ['2026-11-02T18:00:00+00:00', '2026-11-06T02:00:00+00:00'],
            array_column($this->storedExtractionRows(), 'extractionStartTime'),
        );
        self::assertSame([self::NOL_M9_MOON_NAME, self::NOL_M9_MOON_NAME], array_column($this->storedExtractionRows(), 'moonName'));
        self::assertCount(1, $this->esiCallsTo(self::moonEndpoint(self::NOL_M9_MOON_ID)));
    }

    public function testAnExtractionMissingFromTheNextResponseIsKept(): void
    {
        $this->sync(self::CORPORATION_A);
        $this->esiResponses[self::extractionsEndpoint(self::CORPORATION_A)] = [self::deepCoreExtraction()];
        $this->clock->modify('+30 minutes');

        $this->sync(self::CORPORATION_A);

        self::assertSame([self::deepCoreRow(self::CORPORATION_A), self::rockHollowRow(self::CORPORATION_A)], $this->storedExtractionRows());
        self::assertSame([], $this->removedEntities);
    }

    public function testAnEmptyExtractionListIsASuccessfulReadThatKeepsTheHistory(): void
    {
        $this->storeSyncState(self::CORPORATION_A, self::EARLIER_SUCCESS, self::EARLIER_ATTEMPT, CorporationMiningSyncFailure::EsiError);
        $this->storeKeptRockHollowExtraction();
        $this->esiResponses[self::extractionsEndpoint(self::CORPORATION_A)] = [];

        $this->sync(self::CORPORATION_A);

        self::assertSame(
            ['lastSuccessAt' => self::NOW_ATOM, 'lastAttemptAt' => self::NOW_ATOM, 'lastFailure' => null],
            $this->syncStateSummaryOf(self::CORPORATION_A),
        );
        self::assertSame([self::keptRockHollowRow()], $this->storedExtractionRows());
    }

    public function testAnUnreadableMoonLeavesItsNameUnknownAndTheSyncSucceeds(): void
    {
        $this->failEsi(self::moonEndpoint(self::IHAKANA_MOON_ID), 500);

        $this->sync(self::CORPORATION_A);

        self::assertSame(
            [array_replace(self::deepCoreRow(self::CORPORATION_A), ['moonName' => null]), self::rockHollowRow(self::CORPORATION_A)],
            $this->storedExtractionRows(),
        );
        self::assertSame(
            ['lastSuccessAt' => self::NOW_ATOM, 'lastAttemptAt' => self::NOW_ATOM, 'lastFailure' => null],
            $this->syncStateSummaryOf(self::CORPORATION_A),
        );
    }

    public function testAMoonAnswerWithoutANameLeavesItsNameUnknown(): void
    {
        $this->esiResponses[self::moonEndpoint(self::IHAKANA_MOON_ID)] = ['moon_id' => self::IHAKANA_MOON_ID, 'system_id' => self::IHAKANA_SYSTEM_ID];

        $this->sync(self::CORPORATION_A);

        self::assertSame(
            [array_replace(self::deepCoreRow(self::CORPORATION_A), ['moonName' => null]), self::rockHollowRow(self::CORPORATION_A)],
            $this->storedExtractionRows(),
        );
    }

    public function testAMoonLeftUnnamedIsReadAgainOnTheNextSync(): void
    {
        $this->failEsi(self::moonEndpoint(self::IHAKANA_MOON_ID), 503);
        $this->sync(self::CORPORATION_A);
        unset($this->esiFailures[self::moonEndpoint(self::IHAKANA_MOON_ID)]);
        $this->clock->modify('+30 minutes');

        $this->sync(self::CORPORATION_A);

        self::assertCount(2, $this->esiCallsTo(self::moonEndpoint(self::IHAKANA_MOON_ID)));
        self::assertSame([self::deepCoreRow(self::CORPORATION_A), self::rockHollowRow(self::CORPORATION_A)], $this->storedExtractionRows());
    }

    public function testDeniedCorporationStructuresLeaveTheRefineryNamesUnknownAndTheSyncSucceeds(): void
    {
        $this->failEsi(self::structuresEndpoint(self::CORPORATION_A), 403);

        $this->sync(self::CORPORATION_A);

        self::assertSame(
            [
                array_replace(self::deepCoreRow(self::CORPORATION_A), ['structureName' => null]),
                array_replace(self::rockHollowRow(self::CORPORATION_A), ['structureName' => null]),
            ],
            $this->storedExtractionRows(),
        );
        self::assertSame(
            ['lastSuccessAt' => self::NOW_ATOM, 'lastAttemptAt' => self::NOW_ATOM, 'lastFailure' => null],
            $this->syncStateSummaryOf(self::CORPORATION_A),
        );
    }

    public function testAnExtractionOfAnotherCorporationOnTheSameMoonIsNeitherReusedNorChanged(): void
    {
        $this->storeExtraction(
            self::CORPORATION_B,
            self::ROCK_HOLLOW_ID,
            self::NOL_M9_MOON_ID,
            self::ROCK_HOLLOW_START,
            '2026-11-05T21:00:00+00:00',
            '2026-11-06T00:00:00+00:00',
            'Corporation B refinery',
            self::NOL_M9_SYSTEM_ID,
            self::NOL_M9_SYSTEM_NAME,
            'NOL-M9 VII - Moon 3 (corporation B)',
            '0195f3a0-2740-7000-8000-000000000275',
        );

        $this->sync(self::CORPORATION_A);

        $rows = $this->storedExtractionRows();
        self::assertSame([self::deepCoreRow(self::CORPORATION_A), self::rockHollowRow(self::CORPORATION_A)], array_slice($rows, 0, 2));
        self::assertSame(
            ['structureName' => 'Corporation B refinery', 'moonName' => 'NOL-M9 VII - Moon 3 (corporation B)', 'chunkArrivalTime' => '2026-11-05T21:00:00+00:00'],
            array_intersect_key($rows[2], ['structureName' => true, 'moonName' => true, 'chunkArrivalTime' => true]),
        );
        self::assertSame(self::CORPORATION_B, $rows[2]['corporationId']);
    }

    public function testASuccessfulSyncIsPublishedToEveryEvetoolsUserOfTheCorporationOnly(): void
    {
        $this->sync(self::CORPORATION_A);

        self::assertSame([
            ['/user/' . self::USER_A_ID . '/sync/corporation-mining-extractions', 'completed'],
            ['/user/' . self::USER_A_ID . '/sync/corporation-mining-extractions', 'started'],
            ['/user/' . self::USER_B_ID . '/sync/corporation-mining-extractions', 'completed'],
            ['/user/' . self::USER_B_ID . '/sync/corporation-mining-extractions', 'started'],
        ], $this->publishedSyncEvents());
    }

    public function testAFailedSyncIsPublishedAsAnErrorToEveryEvetoolsUserOfTheCorporation(): void
    {
        $this->failEsi(self::extractionsEndpoint(self::CORPORATION_A), 500);

        $this->sync(self::CORPORATION_A);

        self::assertSame([
            ['/user/' . self::USER_A_ID . '/sync/corporation-mining-extractions', 'error'],
            ['/user/' . self::USER_A_ID . '/sync/corporation-mining-extractions', 'started'],
            ['/user/' . self::USER_B_ID . '/sync/corporation-mining-extractions', 'error'],
            ['/user/' . self::USER_B_ID . '/sync/corporation-mining-extractions', 'started'],
        ], $this->publishedSyncEvents());
    }

    public function testASyncWithoutStationManagerIsPublishedAsAnError(): void
    {
        $this->esiResponses[sprintf('/characters/%d/roles/', self::ILYRA_ID)] = ['roles' => []];
        $this->esiResponses[sprintf('/characters/%d/roles/', self::AUREL_ID)] = ['roles' => []];

        $this->sync(self::CORPORATION_A);

        self::assertSame([
            ['/user/' . self::USER_A_ID . '/sync/corporation-mining-extractions', 'error'],
            ['/user/' . self::USER_A_ID . '/sync/corporation-mining-extractions', 'started'],
            ['/user/' . self::USER_B_ID . '/sync/corporation-mining-extractions', 'error'],
            ['/user/' . self::USER_B_ID . '/sync/corporation-mining-extractions', 'started'],
        ], $this->publishedSyncEvents());
    }

    private function sync(int $corporationId): CorporationMiningSyncState
    {
        return $this->build(CorporationMiningExtractionSyncService::class)->sync($corporationId);
    }

    private function storeKeptRockHollowExtraction(): void
    {
        $this->storeExtraction(
            self::CORPORATION_A,
            self::ROCK_HOLLOW_ID,
            self::NOL_M9_MOON_ID,
            self::ROCK_HOLLOW_START,
            self::ROCK_HOLLOW_ARRIVAL,
            self::ROCK_HOLLOW_DECAY,
            'NOL-M9 - Rock Hollow (stored)',
            self::NOL_M9_SYSTEM_ID,
            self::NOL_M9_SYSTEM_NAME,
            'NOL-M9 VII - Moon 3 (stored)',
            self::KEPT_EXTRACTION_ID,
        );
    }

    private static function keptRockHollowRow(): array
    {
        return array_replace(self::rockHollowRow(self::CORPORATION_A), [
            'structureName' => 'NOL-M9 - Rock Hollow (stored)',
            'moonName' => 'NOL-M9 VII - Moon 3 (stored)',
        ]);
    }
}
