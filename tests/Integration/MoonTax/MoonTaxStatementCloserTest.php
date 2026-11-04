<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoonTax;

use App\Entity\MoonTaxRefinerySetting;
use App\Entity\MoonTaxStatement;
use App\Entity\MoonTaxSyncState;
use App\Enum\MoonTaxPeriodKind;
use App\Service\MoonTax\MoonTaxReference;
use App\Service\MoonTax\MoonTaxStatementCloser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(MoonTaxStatementCloser::class)]
#[Group('issue-273')]
final class MoonTaxStatementCloserTest extends MoonTaxIntegrationTestCase
{
    private const string LEDGER_READ_AT = '2026-11-10T11:00:00+00:00';
    private const string WEEK_45_REFERENCE_PATTERN = '/^TAX-2026-W45-[A-HJ-NP-Z2-9]{4}$/';
    private const int ABSENT_FROM_SDE = 999_999;

    protected function setUp(): void
    {
        parent::setUp();
        $this->respondWithCorporation(self::CORPORATION, self::ALLIANCE);
        $this->respondWithCorporation(self::NEIGHBOR_CORPORATION, self::ALLIANCE);
        $this->persistSyncState(self::LEDGER_READ_AT);
    }

    public function testStatementIsCreatedOnlyWhenTheLedgerWasReadAtLeastOneHourAfterThePeriodEnd(): void
    {
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-03', 20_000);
        $this->persistSyncState('2026-11-09T00:59:59+00:00');

        $this->runClosure();
        $beforeTheHour = count($this->storedStatements());
        $this->persistSyncState('2026-11-09T01:00:00+00:00');
        $this->runClosure();

        self::assertSame([0, 1], [$beforeTheHour, count($this->storedStatements())]);
    }

    public function testPeriodInProgressIsNeverFrozen(): void
    {
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-10', 20_000);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::EUXENITE, '2026-11-09', 10_000);

        $this->runClosure();

        self::assertSame([], $this->storedStatements());
    }

    public function testStatementFreezesTheSummedLinesTheTaxAndTheKeysOfTheCharacterForTheFinishedPeriod(): void
    {
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-03', 12_000);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-05', 8_000);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::EUXENITE, '2026-11-04', 10_000);

        $this->runClosure();

        $statements = $this->storedStatements();
        self::assertCount(1, $statements);
        $lines = $statements[0]->getLines();
        usort($lines, static fn (array $left, array $right): int => $left['typeId'] <=> $right['typeId']);
        self::assertSame(
            [
                self::DAX_ID,
                self::ROCK_HOLLOW_ID,
                self::at('2026-11-02 00:00:00'),
                self::at('2026-11-09 00:00:00'),
                6_640_000.0,
                [
                    ['typeId' => self::EUXENITE, 'quantity' => 10_000, 'unitPrice' => 800.0, 'ratePercent' => 8.0, 'tax' => 640_000.0],
                    ['typeId' => self::CINNABAR, 'quantity' => 20_000, 'unitPrice' => 3_000.0, 'ratePercent' => 10.0, 'tax' => 6_000_000.0],
                ],
                [],
                self::CORPORATION,
                false,
                1,
            ],
            [
                $statements[0]->getCharacterId(),
                $statements[0]->getStructureId(),
                $statements[0]->getPeriodStart()->setTimezone(new \DateTimeZone('UTC'))->format(self::ISO_FORMAT),
                $statements[0]->getPeriodEnd()->setTimezone(new \DateTimeZone('UTC'))->format(self::ISO_FORMAT),
                $statements[0]->getTax(),
                $lines,
                $statements[0]->getUnknownTypeIds(),
                $statements[0]->getRecordedCorporationId(),
                $statements[0]->isOutsideAlliance(),
                preg_match(self::WEEK_45_REFERENCE_PATTERN, $statements[0]->getReference()),
            ],
        );
    }

    public function testStatementWithATypeWithoutCompressedPriceHasAnUnknownTaxAndListsEveryUnknownType(): void
    {
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-03', 20_000);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::XENOTIME, '2026-11-03', 4_000);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::LOPARITE, '2026-11-03', 3_000);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::VELDSPAR, '2026-11-03', 30_000);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::ABSENT_FROM_SDE, '2026-11-03', 1_000);

        $this->runClosure();

        self::assertSame(
            [[null, [self::VELDSPAR, self::XENOTIME, self::LOPARITE, self::ABSENT_FROM_SDE]]],
            array_map(static fn (MoonTaxStatement $statement): array => [$statement->getTax(), $statement->getUnknownTypeIds()], $this->storedStatements()),
        );
    }

    public function testFrozenStatementIsNotChangedByALaterRateAPriceChangeOrALateLedgerLineAndIsNeverDuplicated(): void
    {
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-03', 20_000);
        $this->runClosure();
        $frozen = $this->summaryOfStatements();

        $setting = $this->em->getRepository(MoonTaxRefinerySetting::class)->findOneBy(['structureId' => self::ROCK_HOLLOW_ID]);
        $setting->setRateR64(12.0);
        $this->jitaBuyPrices[self::COMPRESSED_CINNABAR] = 3_500.0;
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::EUXENITE, '2026-11-06', 10_000);
        $this->runClosure();

        self::assertCount(1, $frozen);
        self::assertSame([$frozen, [6_000_000.0]], [$this->summaryOfStatements(), array_map(static fn (MoonTaxStatement $statement): ?float => $statement->getTax(), $this->storedStatements())]);
    }

    public function testStatementAlreadyStoredForTheSameKeyIsLeftAloneWhileTheOtherCharactersAreStillClosed(): void
    {
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-03', 20_000);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::MIRA_ID, self::EUXENITE, '2026-11-04', 10_000);
        $this->persistStatement('TAX-2026-W45-ZZZZ', self::DAX_ID, '2026-11-02', 1.0);

        $this->runClosure();

        self::assertSame(
            [[self::DAX_ID, 'TAX-2026-W45-ZZZZ', 1.0], [self::MIRA_ID, null, 640_000.0]],
            array_map(
                static fn (MoonTaxStatement $statement): array => [$statement->getCharacterId(), $statement->getCharacterId() === self::DAX_ID ? $statement->getReference() : null, $statement->getTax()],
                $this->storedStatements(),
            ),
        );
    }

    public function testReferenceCollisionBetweenTwoStatementsIsRetriedWithAnotherDraw(): void
    {
        $draws = 0;
        self::getContainer()->set(MoonTaxReference::class, new MoonTaxReference(static function (int $min, int $max) use (&$draws): int {
            return intdiv($draws++, 8);
        }));
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-03', 20_000);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::MIRA_ID, self::EUXENITE, '2026-11-04', 10_000);

        $this->runClosure();

        $references = array_map(static fn (MoonTaxStatement $statement): string => $statement->getReference(), $this->storedStatements());
        sort($references);
        self::assertSame(['TAX-2026-W45-AAAA', 'TAX-2026-W45-BBBB'], $references);
    }

    public function testRefineryNotOpenGetsNoStatementAndItsFrozenStatementsAreKept(): void
    {
        $this->persistSetting(self::DEEP_CORE_ID, false);
        $this->persistObserverRecord(self::DEEP_CORE_ID, self::DAX_ID, self::CINNABAR, '2026-11-03', 20_000);
        $this->persistObserverRecord(self::MARKET_KEEPSTAR_ID, self::DAX_ID, self::CINNABAR, '2026-11-03', 20_000);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-03', 20_000);
        $this->persistStatement('TAX-2026-W44-OLDD', self::DAX_ID, '2026-10-26', 5_000_000.0, self::DEEP_CORE_ID);

        $this->runClosure();

        self::assertSame(
            [[self::DEEP_CORE_ID, 5_000_000.0], [self::ROCK_HOLLOW_ID, 6_000_000.0]],
            array_map(static fn (MoonTaxStatement $statement): array => [$statement->getStructureId(), $statement->getTax()], $this->storedStatements()),
        );
    }

    public function testCharacterWhoMinedAtTwoOpenRefineriesGetsOneStatementPerRefinery(): void
    {
        $this->persistSetting(self::DEEP_CORE_ID, true, MoonTaxPeriodKind::Weekly, 2);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-03', 20_000);
        $this->persistObserverRecord(self::DEEP_CORE_ID, self::DAX_ID, self::ZEOLITES, '2026-11-04', 5_000);

        $this->runClosure();

        self::assertSame(
            [[self::ROCK_HOLLOW_ID, 6_000_000.0], [self::DEEP_CORE_ID, 15_000.0]],
            array_map(static fn (MoonTaxStatement $statement): array => [$statement->getStructureId(), $statement->getTax()], $this->storedStatements()),
        );
    }

    public function testRecordedCorporationIsTheOneOfTheMostRecentLineOfThePeriodForTheCharacterAndTheRefinery(): void
    {
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-03', 20_000, self::NEIGHBOR_CORPORATION);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::EUXENITE, '2026-11-06', 10_000, self::STRANGER_CORPORATION);
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::ZEOLITES, '2026-11-04', 5_000, self::NEIGHBOR_CORPORATION);

        $this->runClosure();

        self::assertSame([self::STRANGER_CORPORATION], array_map(static fn (MoonTaxStatement $statement): ?int => $statement->getRecordedCorporationId(), $this->storedStatements()));
    }

    public function testMonthlyRefineryIsFrozenAtTheEndOfTheMonthWithAMonthlyReference(): void
    {
        $this->persistSetting(self::DEEP_CORE_ID, true, MoonTaxPeriodKind::Monthly, 2);
        $this->persistObserverRecord(self::DEEP_CORE_ID, self::DAX_ID, self::CINNABAR, '2026-10-20', 20_000);
        $this->persistObserverRecord(self::DEEP_CORE_ID, self::DAX_ID, self::ZEOLITES, '2026-10-28', 5_000);

        $this->runClosure();

        self::assertSame(
            [[self::at('2026-10-01 00:00:00'), self::at('2026-11-01 00:00:00'), 6_015_000.0, 1]],
            array_map(
                static fn (MoonTaxStatement $statement): array => [
                    $statement->getPeriodStart()->setTimezone(new \DateTimeZone('UTC'))->format(self::ISO_FORMAT),
                    $statement->getPeriodEnd()->setTimezone(new \DateTimeZone('UTC'))->format(self::ISO_FORMAT),
                    $statement->getTax(),
                    preg_match('/^TAX-2026-M10-[A-HJ-NP-Z2-9]{4}$/', $statement->getReference()),
                ],
                $this->storedStatements(),
            ),
        );
    }

    public function testChangingThePeriodOfARefineryLeavesTheFrozenStatementsUntouched(): void
    {
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-03', 20_000);
        $this->runClosure();
        $frozen = $this->summaryOfStatements();
        self::assertCount(1, $frozen);

        $this->em->getRepository(MoonTaxRefinerySetting::class)->findOneBy(['structureId' => self::ROCK_HOLLOW_ID])->setPeriod(MoonTaxPeriodKind::Monthly);
        $this->runClosure();

        self::assertSame($frozen, $this->summaryOfStatements());
    }

    #[DataProvider('ownerAndRecordedAlliances')]
    public function testOutsideAllianceComparesTheAllianceOfTheRecordedCorporationWithTheOneOfTheOwner(?int $ownerAlliance, bool $ownerUnreadable, int $recordedCorporationId, ?int $recordedAlliance, bool $recordedUnreadable, ?bool $expected): void
    {
        $this->respondWithCorporation(self::CORPORATION, $ownerAlliance);
        $this->respondWithCorporation(self::STRANGER_CORPORATION, self::OTHER_ALLIANCE);
        $this->respondWithCorporation(self::LONER_CORPORATION, null);
        $this->respondWithCorporation(self::NEIGHBOR_CORPORATION, self::ALLIANCE);
        if ($recordedCorporationId !== self::CORPORATION) {
            $this->respondWithCorporation($recordedCorporationId, $recordedAlliance);
        }
        if ($ownerUnreadable) {
            $this->failEsi(self::corporationEndpoint(self::CORPORATION), 500);
        }
        if ($recordedUnreadable) {
            $this->failEsi(self::corporationEndpoint($recordedCorporationId), 500);
        }
        $this->persistObserverRecord(self::ROCK_HOLLOW_ID, self::DAX_ID, self::CINNABAR, '2026-11-03', 20_000, $recordedCorporationId);

        $this->runClosure();

        self::assertSame([$expected], array_map(static fn (MoonTaxStatement $statement): ?bool => $statement->isOutsideAlliance(), $this->storedStatements()));
    }

    public static function ownerAndRecordedAlliances(): iterable
    {
        yield 'neighbor corporation of the same alliance' => [self::ALLIANCE, false, self::NEIGHBOR_CORPORATION, self::ALLIANCE, false, false];
        yield 'corporation of another alliance' => [self::ALLIANCE, false, self::STRANGER_CORPORATION, self::OTHER_ALLIANCE, false, true];
        yield 'corporation without alliance' => [self::ALLIANCE, false, self::LONER_CORPORATION, null, false, true];
        yield 'owner without alliance and recorded corporation in an alliance' => [null, false, self::NEIGHBOR_CORPORATION, self::ALLIANCE, false, true];
        yield 'recorded for the owner itself' => [self::ALLIANCE, false, self::CORPORATION, self::ALLIANCE, false, false];
        yield 'recorded for the owner itself without alliance' => [null, false, self::CORPORATION, null, false, false];
        yield 'recorded corporation unreadable' => [self::ALLIANCE, false, self::STRANGER_CORPORATION, null, true, null];
        yield 'owner unreadable' => [self::ALLIANCE, true, self::STRANGER_CORPORATION, self::OTHER_ALLIANCE, false, null];
    }

    private function persistSyncState(string $observersSyncedAt): MoonTaxSyncState
    {
        $state = $this->em->getRepository(MoonTaxSyncState::class)->findOneBy(['corporationId' => self::CORPORATION]) ?? (new MoonTaxSyncState())->setCorporationId(self::CORPORATION);
        $state->setObserversSyncedAt(new \DateTimeImmutable($observersSyncedAt));
        $this->em->persist($state);
        $this->em->flush();

        return $state;
    }

    private function summaryOfStatements(): array
    {
        return array_map(
            static fn (MoonTaxStatement $statement): array => [$statement->getReference(), $statement->getCharacterId(), $statement->getTax(), $statement->getLines(), $statement->getPeriodEnd()->getTimestamp() - $statement->getPeriodStart()->getTimestamp()],
            $this->storedStatements(),
        );
    }
}
