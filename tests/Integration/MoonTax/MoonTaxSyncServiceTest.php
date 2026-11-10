<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoonTax;

use App\Service\Sync\MoonTaxSyncService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(MoonTaxSyncService::class)]
#[Group('issue-273')]
final class MoonTaxSyncServiceTest extends MoonTaxIntegrationTestCase
{
    private const string FIRST_SYNC_AT = '2026-11-10T11:00:00+00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->respondWithObservers(self::CORPORATION, [self::ROCK_HOLLOW_ID]);
        $this->respondWithLedger(self::CORPORATION, self::ROCK_HOLLOW_ID, [
            self::ledgerEntry(self::DAX_ID, self::CINNABAR, 20_000, '2026-11-03', self::CORPORATION),
            self::ledgerEntry(self::MIRA_ID, self::EUXENITE, 10_000, '2026-11-04', self::CORPORATION),
            self::ledgerEntry(self::DAX_ID, self::ZEOLITES, 5_000, '2026-11-04', self::CORPORATION),
        ]);
        $this->respondWithJournal(self::CORPORATION, 1, []);
    }

    #[DataProvider('charactersWhoCannotRead')]
    public function testFailureTellsWhyNoCharacterOfTheCorporationCouldReadEachFlow(array $characters, array $expectedFailures): void
    {
        foreach ($characters as [$eveCharacterId, $roles, $scopes, $authIsInvalid]) {
            $character = $this->persistCharacterOfCorporation('Pilot ' . $eveCharacterId, $eveCharacterId, self::CORPORATION, $roles, $scopes);
            if ($authIsInvalid) {
                $character->markAuthInvalid();
            }
        }

        $this->runSync();

        $state = $this->syncStateSummary();
        self::assertSame($expectedFailures, [$state['observersFailure'], $state['walletFailure']]);
    }

    public static function charactersWhoCannotRead(): iterable
    {
        yield 'no character in the corporation' => [[], ['no_accountant_token', 'no_accountant_token']];
        yield 'no Accountant' => [[[self::ILYRA_ID, ['Auditor'], self::ALL_SCOPES, false]], ['no_accountant_token', 'no_accountant_token']];
        yield 'Junior_Accountant reads the wallet only' => [[[self::ILYRA_ID, ['Junior_Accountant'], self::ALL_SCOPES, false]], ['no_accountant_token', null]];
        yield 'invalid authentication' => [[[self::ILYRA_ID, ['Accountant'], self::ALL_SCOPES, true]], ['reconnect_required', 'reconnect_required']];
        yield 'mining scope missing' => [[[self::ILYRA_ID, ['Accountant'], [self::WALLET_SCOPE, self::ROLES_SCOPE], false]], ['reconnect_required', null]];
        yield 'wallet scope missing' => [[[self::ILYRA_ID, ['Accountant'], [self::MINING_SCOPE, self::ROLES_SCOPE], false]], [null, 'reconnect_required']];
        yield 'roles scope missing' => [[[self::ILYRA_ID, ['Accountant'], [self::MINING_SCOPE, self::WALLET_SCOPE], false]], ['reconnect_required', 'reconnect_required']];
        yield 'invalid authentication next to a character without Accountant' => [
            [[self::ILYRA_ID, ['Accountant'], self::ALL_SCOPES, true], [self::AUREL_ID, ['Auditor'], self::ALL_SCOPES, false]],
            ['reconnect_required', 'reconnect_required'],
        ];
    }

    public function testEveryCandidateDeniedByTheObserversEndpointIsADeniedAccessAndKeepsTheLastSuccessTheRecordsAndTheStatements(): void
    {
        $this->ilyra();
        $this->aurel();
        self::mockTime(new \DateTimeImmutable(self::FIRST_SYNC_AT));
        $this->runSync();
        $recordsBefore = $this->storedRecords();
        $statementsBefore = count($this->storedStatements());
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $this->failEsi(self::observersEndpoint(self::CORPORATION), 403);

        $this->runSync();

        $state = $this->syncStateSummary();
        self::assertSame(
            [3, 2, $recordsBefore, self::at(self::FIRST_SYNC_AT), 'no_accountant_token'],
            [count($recordsBefore), $statementsBefore, $this->storedRecords(), $state['observersSyncedAt'], $state['observersFailure']],
        );
    }

    public function testEveryCandidateFailingWithAServerErrorIsAnEsiErrorThatKeepsTheLastSuccessAndTheRecords(): void
    {
        $this->ilyra();
        $this->aurel();
        self::mockTime(new \DateTimeImmutable(self::FIRST_SYNC_AT));
        $this->runSync();
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $this->failEsi(self::observersEndpoint(self::CORPORATION), 500);

        $this->runSync();

        $state = $this->syncStateSummary();
        self::assertSame([3, self::at(self::FIRST_SYNC_AT), 'esi_error'], [count($this->storedRecords()), $state['observersSyncedAt'], $state['observersFailure']]);
    }

    public function testSuccessfulSyncAfterAFailureClearsTheFailureAndMovesTheDate(): void
    {
        $this->ilyra();
        $this->failEsi(self::observersEndpoint(self::CORPORATION), 500);
        self::mockTime(new \DateTimeImmutable(self::FIRST_SYNC_AT));
        $this->runSync();
        $this->esiFailures = [];
        self::mockTime(new \DateTimeImmutable(self::NOW));

        $this->runSync();

        self::assertSame(
            ['observersSyncedAt' => self::at(self::NOW), 'walletSyncedAt' => self::at(self::NOW), 'observersFailure' => null, 'walletFailure' => null],
            $this->syncStateSummary(),
        );
    }

    public function testFinishedPeriodsAreFrozenAtTheEndOfASuccessfulLedgerSync(): void
    {
        $this->ilyra();

        $this->runSync();

        self::assertSame(
            [
                [self::DAX_ID, self::at('2026-11-02 00:00:00'), self::at('2026-11-09 00:00:00'), 6_015_000.0, []],
                [self::MIRA_ID, self::at('2026-11-02 00:00:00'), self::at('2026-11-09 00:00:00'), 640_000.0, []],
            ],
            array_map(
                static fn (\App\Entity\MoonTaxStatement $statement): array => [$statement->getCharacterId(), $statement->getPeriodStart()->setTimezone(new \DateTimeZone('UTC'))->format(self::ISO_FORMAT), $statement->getPeriodEnd()->setTimezone(new \DateTimeZone('UTC'))->format(self::ISO_FORMAT), $statement->getTax(), $statement->getUnknownTypeIds()],
                $this->storedStatements(),
            ),
        );
    }

    public function testSuccessfulSyncIsPublishedAsStartedThenCompletedToEveryEvetoolsUserOfTheCorporationOnly(): void
    {
        $this->ilyra();
        $this->aurel();
        $this->persistCharacterOfCorporation('Brann Holt', self::BRANN_ID, self::OTHER_OWNER_CORPORATION, ['Accountant']);

        $this->runSync();

        $topicOfIlyra = sprintf('/user/%s/sync/moon-tax', $this->usersByEveCharacterId[self::ILYRA_ID]->getId());
        $topicOfAurel = sprintf('/user/%s/sync/moon-tax', $this->usersByEveCharacterId[self::AUREL_ID]->getId());
        $expected = [[$topicOfIlyra, 'started'], [$topicOfIlyra, 'completed'], [$topicOfAurel, 'started'], [$topicOfAurel, 'completed']];
        sort($expected);
        self::assertSame($expected, $this->publishedSyncEvents());
    }

    public function testFailedSyncIsPublishedAsStartedThenErrorToEveryEvetoolsUserOfTheCorporation(): void
    {
        $this->ilyra(['Auditor']);

        $this->runSync();

        $topicOfIlyra = sprintf('/user/%s/sync/moon-tax', $this->usersByEveCharacterId[self::ILYRA_ID]->getId());
        self::assertSame([[$topicOfIlyra, 'error'], [$topicOfIlyra, 'started']], $this->publishedSyncEvents());
    }
}
