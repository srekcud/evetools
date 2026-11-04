<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoonTax;

use App\Service\Sync\MoonTaxSyncService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(MoonTaxSyncService::class)]
#[Group('issue-273')]
final class CorporationWalletJournalSyncTest extends MoonTaxIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->respondWithObservers(self::CORPORATION, [self::ROCK_HOLLOW_ID]);
        $this->respondWithLedger(self::CORPORATION, self::ROCK_HOLLOW_ID, []);
    }

    public function testOnlyPlayerDonationsToTheCorporationWithAPositiveAmountAreStored(): void
    {
        $this->ilyra();
        $this->respondWithJournal(self::CORPORATION, 1, [
            self::journalDonation(5_001, self::DAX_ID, self::CORPORATION, 4_000_000.0, '2026-11-03T10:15:00Z', 'TAX-2026-W44-ABCD'),
            ['amount' => 2_500_000] + self::journalDonation(5_002, self::MIRA_ID, self::CORPORATION, 0.0, '2026-11-04T09:00:00Z'),
            self::journalDonation(5_003, self::CORPORATION, self::DAX_ID, -1_000_000.0, '2026-11-04T10:00:00Z'),
            self::journalDonation(5_004, self::DAX_ID, self::CORPORATION, 0.0, '2026-11-04T11:00:00Z'),
            self::journalDonation(5_005, self::DAX_ID, self::NEIGHBOR_CORPORATION, 3_000_000.0, '2026-11-04T12:00:00Z'),
            ['id' => 5_006, 'date' => '2026-11-04T13:00:00Z', 'ref_type' => 'market_transaction'],
            ['id' => 5_007, 'date' => '2026-11-04T14:00:00Z', 'ref_type' => 'bounty_prizes', 'amount' => 800_000.0, 'first_party_id' => self::DAX_ID, 'second_party_id' => self::CORPORATION],
        ]);

        $this->runSync();

        self::assertSame(
            [
                [5_001, self::DAX_ID, 4_000_000.0, '2026-11-03T10:15:00+00:00', 'TAX-2026-W44-ABCD', null],
                [5_002, self::MIRA_ID, 2_500_000.0, '2026-11-04T09:00:00+00:00', null, null],
            ],
            $this->storedDonations(),
        );
    }

    public function testEachWalletDivisionOfAnOpenRefineryIsReadOnceAndAClosedRefineryDivisionIsNeverRead(): void
    {
        $this->ilyra();
        $this->persistSetting(self::DEEP_CORE_ID, true, \App\Enum\MoonTaxPeriodKind::Monthly, 3);
        $this->persistSetting(self::MARKET_KEEPSTAR_ID, true, \App\Enum\MoonTaxPeriodKind::Weekly, 1);
        $this->persistSetting(self::MARKET_KEEPSTAR_ID + 1, false, \App\Enum\MoonTaxPeriodKind::Weekly, 5);
        $this->respondWithJournal(self::CORPORATION, 1, []);
        $this->respondWithJournal(self::CORPORATION, 3, []);

        $this->runSync();

        self::assertSame(
            [1, 1, 0],
            [
                count($this->esiCallsTo(self::journalEndpoint(self::CORPORATION, 1))),
                count($this->esiCallsTo(self::journalEndpoint(self::CORPORATION, 3))),
                count($this->esiCallsTo(self::journalEndpoint(self::CORPORATION, 5))),
            ],
        );
    }

    public function testSecondSyncKeepsOneRowPerJournalEntryAndTheAttributionDoneByHand(): void
    {
        $this->ilyra();
        $this->respondWithJournal(self::CORPORATION, 1, [
            self::journalDonation(5_001, self::DAX_ID, self::CORPORATION, 4_000_000.0, '2026-11-03T10:15:00Z'),
            self::journalDonation(5_002, self::MIRA_ID, self::CORPORATION, 2_500_000.0, '2026-11-04T09:00:00Z'),
        ]);
        $this->runSync();
        $donation = $this->em->getRepository(\App\Entity\CorporationWalletDonation::class)->findOneBy(['journalId' => 5_001]);
        self::assertNotNull($donation);
        $donation->setAttributedCharacterId(self::TOLA_ID);
        $this->em->flush();

        $this->runSync();

        self::assertSame(
            [
                [5_001, self::DAX_ID, 4_000_000.0, '2026-11-03T10:15:00+00:00', null, self::TOLA_ID],
                [5_002, self::MIRA_ID, 2_500_000.0, '2026-11-04T09:00:00+00:00', null, null],
            ],
            $this->storedDonations(),
        );
    }

    public function testFirstCandidateDeniedByEsiHandsTheJournalOverToTheNextOne(): void
    {
        $this->ilyra();
        $this->aurel();
        $this->respondWithJournal(self::CORPORATION, 1, [self::journalDonation(5_001, self::DAX_ID, self::CORPORATION, 4_000_000.0, '2026-11-03T10:15:00Z')]);
        $this->failEsi(self::journalEndpoint(self::CORPORATION, 1), 403, self::ILYRA_ID);

        $this->runSync();

        self::assertSame(
            [[self::ILYRA_ID, self::AUREL_ID], 1, null],
            [$this->charactersReading(self::journalEndpoint(self::CORPORATION, 1)), count($this->storedDonations()), $this->syncStateSummary()['walletFailure']],
        );
    }

    #[DataProvider('unreadableJournalEntries')]
    public function testOneUnreadableDonationMakesTheWholeJournalAnEsiErrorAndNothingIsWrittenWhileTheLedgerSyncStillSucceeds(mixed $unreadableEntry): void
    {
        $this->ilyra();
        $this->respondWithJournal(self::CORPORATION, 1, [
            self::journalDonation(5_001, self::DAX_ID, self::CORPORATION, 4_000_000.0, '2026-11-03T10:15:00Z'),
            $unreadableEntry,
        ]);

        $this->runSync();

        $state = $this->syncStateSummary();
        self::assertSame([[], null, 'esi_error', self::at(self::NOW), null], [$this->storedDonations(), $state['walletSyncedAt'], $state['walletFailure'], $state['observersSyncedAt'], $state['observersFailure']]);
    }

    public static function unreadableJournalEntries(): iterable
    {
        $complete = ['id' => 5_002, 'date' => '2026-11-04T09:00:00Z', 'ref_type' => 'player_donation', 'amount' => 2_500_000.0, 'first_party_id' => self::MIRA_ID, 'second_party_id' => self::CORPORATION, 'reason' => 'thanks'];
        foreach (['id', 'first_party_id', 'second_party_id'] as $field) {
            $without = $complete;
            unset($without[$field]);
            yield $field . ' absent' => [$without];
            yield $field . ' as a string' => [[$field => '12345'] + $complete];
            yield $field . ' as a float' => [[$field => 12_345.5] + $complete];
            yield $field . ' as null' => [[$field => null] + $complete];
        }
        $withoutAmount = $complete;
        unset($withoutAmount['amount']);
        yield 'amount absent' => [$withoutAmount];
        yield 'amount as a string' => [['amount' => '2500000.0'] + $complete];
        yield 'amount as null' => [['amount' => null] + $complete];
        $withoutRefType = $complete;
        unset($withoutRefType['ref_type']);
        yield 'ref_type absent' => [$withoutRefType];
        yield 'ref_type as an integer' => [['ref_type' => 7] + $complete];
        yield 'ref_type as a float' => [['ref_type' => 7.5] + $complete];
        yield 'ref_type as null' => [['ref_type' => null] + $complete];
        $withoutDate = $complete;
        unset($withoutDate['date']);
        yield 'date absent' => [$withoutDate];
        yield 'date as an integer' => [['date' => 20_261_104] + $complete];
        yield 'date as null' => [['date' => null] + $complete];
        yield 'date not a date' => [['date' => 'yesterday'] + $complete];
        yield 'reason as an integer' => [['reason' => 42] + $complete];
        yield 'entry not an object' => ['player_donation'];
    }

    public function testEmptyJournalIsASuccessThatKeepsTheStoredDonations(): void
    {
        $this->ilyra();
        $this->respondWithJournal(self::CORPORATION, 1, [self::journalDonation(5_001, self::DAX_ID, self::CORPORATION, 4_000_000.0, '2026-11-03T10:15:00Z')]);
        $this->runSync();
        $this->respondWithJournal(self::CORPORATION, 1, []);

        $this->runSync();

        self::assertSame([1, null], [count($this->storedDonations()), $this->syncStateSummary()['walletFailure']]);
    }
}
