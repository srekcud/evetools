<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoonTax;

use App\Service\Sync\MoonTaxSyncService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(MoonTaxSyncService::class)]
#[Group('issue-273')]
final class MoonObserverLedgerSyncTest extends MoonTaxIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->persistSetting(self::DEEP_CORE_ID, false);
        $this->respondWithObservers(self::CORPORATION, [self::ROCK_HOLLOW_ID, self::DEEP_CORE_ID, self::MARKET_KEEPSTAR_ID]);
        $this->respondWithLedger(self::CORPORATION, self::ROCK_HOLLOW_ID, $this->rockHollowLedger());
        $this->respondWithJournal(self::CORPORATION, 1, []);
    }

    public function testLedgerOfTheOpenRefineryIsStoredWithTheRecordedCorporationOfEachLine(): void
    {
        $this->ilyra();

        $this->runSync();

        self::assertSame(
            [
                [self::ROCK_HOLLOW_ID, self::DAX_ID, self::ZEOLITES, '2026-11-04', 5_000, self::CORPORATION],
                [self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-03', 20_000, self::CORPORATION],
                [self::ROCK_HOLLOW_ID, self::MIRA_ID, self::EUXENITE, '2026-11-04', 10_000, self::NEIGHBOR_CORPORATION],
            ],
            $this->storedRecords(),
        );
    }

    public function testRefineriesNotOpenAreNeverReadAndTheLowestCharacterIdReadsTheOpenOne(): void
    {
        $this->aurel();
        $this->ilyra();

        $this->runSync();

        self::assertSame(
            [[], [], [self::ILYRA_ID]],
            [
                $this->esiCallsTo(self::observerLedgerEndpoint(self::CORPORATION, self::DEEP_CORE_ID)),
                $this->esiCallsTo(self::observerLedgerEndpoint(self::CORPORATION, self::MARKET_KEEPSTAR_ID)),
                $this->charactersReading(self::observerLedgerEndpoint(self::CORPORATION, self::ROCK_HOLLOW_ID)),
            ],
        );
    }

    public function testSuccessfulSyncRecordsTheDateOfTheLastSuccessWithoutFailure(): void
    {
        $this->ilyra();

        $this->runSync();

        self::assertSame(
            ['observersSyncedAt' => self::at(self::NOW), 'walletSyncedAt' => self::at(self::NOW), 'observersFailure' => null, 'walletFailure' => null],
            $this->syncStateSummary(),
        );
    }

    public function testSecondSyncReplacesTheQuantityOfTheSameLineAddsTheNewOnesAndKeepsTheLinesMissingFromTheAnswer(): void
    {
        $this->ilyra();
        $this->runSync();
        $this->respondWithLedger(self::CORPORATION, self::ROCK_HOLLOW_ID, [
            self::ledgerEntry(self::DAX_ID, self::CINNABAR, 26_000, '2026-11-03', self::CORPORATION),
            self::ledgerEntry(self::DAX_ID, self::CINNABAR, 4_000, '2026-11-05', self::CORPORATION),
            self::ledgerEntry(self::DAX_ID, self::ZEOLITES, 5_000, '2026-11-04', self::CORPORATION),
        ]);

        $this->runSync();

        self::assertSame(
            [
                [self::ROCK_HOLLOW_ID, self::DAX_ID, self::ZEOLITES, '2026-11-04', 5_000, self::CORPORATION],
                [self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-03', 26_000, self::CORPORATION],
                [self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-05', 4_000, self::CORPORATION],
                [self::ROCK_HOLLOW_ID, self::MIRA_ID, self::EUXENITE, '2026-11-04', 10_000, self::NEIGHBOR_CORPORATION],
            ],
            $this->storedRecords(),
        );
    }

    public function testFirstCandidateDeniedByEsiHandsTheReadOverToTheNextOne(): void
    {
        $this->ilyra();
        $this->aurel();
        $this->failEsi(self::observersEndpoint(self::CORPORATION), 403, self::ILYRA_ID);

        $this->runSync();

        self::assertSame(
            [[self::ILYRA_ID, self::AUREL_ID], 3, null],
            [$this->charactersReading(self::observersEndpoint(self::CORPORATION)), count($this->storedRecords()), $this->syncStateSummary()['observersFailure']],
        );
    }

    #[DataProvider('unreadableLedgerEntries')]
    public function testOneUnreadableLedgerEntryMakesTheWholeAnswerAnEsiErrorAndNothingIsWritten(mixed $unreadableEntry): void
    {
        $this->ilyra();
        $this->respondWithLedger(self::CORPORATION, self::ROCK_HOLLOW_ID, [
            self::ledgerEntry(self::DAX_ID, self::CINNABAR, 20_000, '2026-11-03', self::CORPORATION),
            $unreadableEntry,
        ]);

        $this->runSync();

        self::assertSame([[], null, 'esi_error'], [$this->storedRecords(), $this->syncStateSummary()['observersSyncedAt'], $this->syncStateSummary()['observersFailure']]);
    }

    public static function unreadableLedgerEntries(): iterable
    {
        $complete = ['character_id' => self::MIRA_ID, 'last_updated' => '2026-11-04', 'quantity' => 10_000, 'recorded_corporation_id' => self::CORPORATION, 'type_id' => self::EUXENITE];
        foreach (['character_id', 'quantity', 'recorded_corporation_id', 'type_id'] as $field) {
            $without = $complete;
            unset($without[$field]);
            yield $field . ' absent' => [$without];
            yield $field . ' as a string' => [[$field => '12345'] + $complete];
            yield $field . ' as a float' => [[$field => 12_345.5] + $complete];
            yield $field . ' as null' => [[$field => null] + $complete];
        }
        $withoutDate = $complete;
        unset($withoutDate['last_updated']);
        yield 'last_updated absent' => [$withoutDate];
        yield 'last_updated as an integer' => [['last_updated' => 20_261_104] + $complete];
        yield 'last_updated as a float' => [['last_updated' => 2026.11] + $complete];
        yield 'last_updated as null' => [['last_updated' => null] + $complete];
        yield 'last_updated not a date' => [['last_updated' => 'yesterday'] + $complete];
        yield 'entry not an object' => ['2026-11-04'];
    }

    #[DataProvider('unreadableObservers')]
    public function testUnreadableObserverListMakesTheSyncAnEsiErrorAndNothingIsWritten(array $unreadableObservers): void
    {
        $this->ilyra();
        $this->esiResponses[self::observersEndpoint(self::CORPORATION)] = $unreadableObservers;

        $this->runSync();

        self::assertSame([[], 'esi_error'], [$this->storedRecords(), $this->syncStateSummary()['observersFailure']]);
    }

    public static function unreadableObservers(): iterable
    {
        $valid = ['observer_id' => self::ROCK_HOLLOW_ID, 'observer_type' => 'structure', 'last_updated' => '2026-11-09'];
        yield 'observer_id absent' => [[$valid, ['observer_type' => 'structure', 'last_updated' => '2026-11-09']]];
        yield 'observer_id as a string' => [[$valid, ['observer_id' => '1035000000102'] + $valid]];
        yield 'observer_id as a float' => [[$valid, ['observer_id' => 1_035_000_000_102.5] + $valid]];
        yield 'observer_id as null' => [[$valid, ['observer_id' => null] + $valid]];
        yield 'observer not an object' => [[$valid, 'structure']];
    }

    public function testEmptyLedgerOfAnOpenRefineryIsASuccessThatKeepsTheHistory(): void
    {
        $this->ilyra();
        $this->runSync();
        $this->respondWithLedger(self::CORPORATION, self::ROCK_HOLLOW_ID, []);

        $this->runSync();

        self::assertSame([3, null], [count($this->storedRecords()), $this->syncStateSummary()['observersFailure']]);
    }

    private function rockHollowLedger(): array
    {
        return [
            self::ledgerEntry(self::DAX_ID, self::CINNABAR, 20_000, '2026-11-03', self::CORPORATION),
            self::ledgerEntry(self::MIRA_ID, self::EUXENITE, 10_000, '2026-11-04', self::NEIGHBOR_CORPORATION),
            self::ledgerEntry(self::DAX_ID, self::ZEOLITES, 5_000, '2026-11-04', self::CORPORATION),
        ];
    }
}
