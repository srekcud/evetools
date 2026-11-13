<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoonChunk;

use App\Entity\CorporationMiningExtraction;
use App\Entity\MoonComposition;
use App\Entity\User;
use App\Service\MoonChunk\MoonCompositionImporter;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[CoversClass(MoonCompositionImporter::class)]
#[Group('issue-853')]
#[Group('issue-859')]
final class MoonCompositionImporterTest extends IntegrationTestCase
{
    use ClockSensitiveTrait;
    use SeedsMoonChunkMarket;

    private const int CORPORATION_A = 98_262_101;
    private const int CORPORATION_B = 98_262_102;
    private const int FIRST_MOON = 40_301_001;
    private const int SECOND_MOON = 40_301_002;
    private const int UNKNOWN_ORE_MOON = 40_301_003;
    private const int NOT_AN_ORE_MOON = 40_301_004;
    private const int ALREADY_SCANNED_MOON = 40_302_001;
    private const int OVERFULL_MOON = 40_302_004;
    private const int MOON_ABSENT_FROM_SHEET = 40_399_999;
    private const int MOON_WITHOUT_NAME = 40_399_998;
    private const int HYPHENATED_SYSTEM_MOON = 40_303_001;
    private const string IMPORT_AT = '2026-11-13T20:00:00+00:00';
    private const string SCAN_AT = '2026-11-05T20:00:00+00:00';
    private const string FIXTURE = __DIR__ . '/../../Fixtures/MoonCompositionSheet/community-sheet.csv';

    private MoonCompositionImporter $importer;
    private User $aria;
    private string $sheet;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::IMPORT_AT));
        parent::setUp();
        $this->seedMoonOreSde($this->em);
        $this->aria = $this->createUser();
        $this->em->persist(
            (new MoonComposition())
                ->setCorporationId(self::CORPORATION_A)
                ->setMoonId(self::ALREADY_SCANNED_MOON)
                ->setRecordedBy($this->aria)
                ->setRecordedAt(new \DateTimeImmutable(self::SCAN_AT))
                ->setLines([['typeId' => self::ZEOLITES, 'share' => 1.0]]),
        );
        $this->persistExtraction(self::CORPORATION_A, self::FIRST_MOON, 'Veyra II - Moon 1', '2026-11-01');
        $this->persistExtraction(self::CORPORATION_A, self::FIRST_MOON, 'Veyra II - Moon 1', '2026-11-08');
        $this->persistExtraction(self::CORPORATION_A, self::SECOND_MOON, 'Veyra II - Moon 2', '2026-11-01');
        $this->persistExtraction(self::CORPORATION_B, self::SECOND_MOON, 'Veyra II - Moon 2', '2026-11-01');
        $this->persistExtraction(self::CORPORATION_A, self::UNKNOWN_ORE_MOON, 'Veyra II - Moon 3', '2026-11-01');
        $this->persistExtraction(self::CORPORATION_A, self::NOT_AN_ORE_MOON, 'Veyra II - Moon 4', '2026-11-01');
        $this->persistExtraction(self::CORPORATION_A, self::ALREADY_SCANNED_MOON, 'Veyra III - Moon 1', '2026-11-01');
        $this->persistExtraction(self::CORPORATION_A, self::OVERFULL_MOON, 'Veyra III - Moon 4', '2026-11-01');
        $this->persistExtraction(self::CORPORATION_A, self::MOON_ABSENT_FROM_SHEET, 'Unlisted I - Moon 1', '2026-11-01');
        $this->persistExtraction(self::CORPORATION_A, self::MOON_WITHOUT_NAME, null, '2026-11-01');
        $this->em->flush();
        $this->sheet = file_get_contents(self::FIXTURE) ?: self::fail('Fixture sheet is unreadable');
        $this->importer = self::getContainer()->get(MoonCompositionImporter::class);
    }

    public function testTheSummaryCountsEveryMoonOfTheDatabaseOnceByCorporationAndMoon(): void
    {
        $report = $this->importer->import($this->sheet);

        self::assertSame(
            [9, 7, 3, 1, 2, 3],
            [$report->moonsInDatabase, $report->matched, $report->imported, $report->skippedAlreadyScanned, $report->notFoundInSheet, count($report->rejected)],
        );
    }

    public function testACompositionIsImportedAsItIsWithoutAnAuthorEvenWhenTheSharesDoNotAddUpToOne(): void
    {
        $this->importer->import($this->sheet);

        self::assertSame(
            [
                [self::CORPORATION_A, self::FIRST_MOON, null, '2026-11-13 20:00:00', [['typeId' => self::BITUMENS, 'share' => 0.5], ['typeId' => self::CHROMITE, 'share' => 0.3]]],
                [self::CORPORATION_A, self::SECOND_MOON, null, '2026-11-13 20:00:00', [['typeId' => self::ZEOLITES, 'share' => 0.6], ['typeId' => self::SYLVITE, 'share' => 0.4]]],
                [self::CORPORATION_B, self::SECOND_MOON, null, '2026-11-13 20:00:00', [['typeId' => self::ZEOLITES, 'share' => 0.6], ['typeId' => self::SYLVITE, 'share' => 0.4]]],
            ],
            $this->importedCompositions(),
        );
    }

    public function testAMoonThatAlreadyHasACompositionIsLeftUntouched(): void
    {
        $this->importer->import($this->sheet);

        self::assertSame(
            [[self::CORPORATION_A, self::ALREADY_SCANNED_MOON, (string) $this->aria->getId(), '2026-11-05 20:00:00', [['typeId' => self::ZEOLITES, 'share' => 1.0]]]],
            $this->scannedCompositions(),
        );
    }

    public function testMoonsOfTheSheetThatNoCorporationOwnsGetNoComposition(): void
    {
        $this->importer->import($this->sheet);

        self::assertCount(4, $this->allCompositions());
    }

    public function testARowWithAnUnknownOreAnUnpublishedOreOrSharesAboveOneIsRejectedAndReportedWhileTheRunGoesOn(): void
    {
        $report = $this->importer->import($this->sheet);

        $reasonsByMoonName = array_column($report->rejected, 'reason', 'moonName');
        self::assertSame(['Veyra II - Moon 3', 'Veyra II - Moon 4', 'Veyra III - Moon 4'], array_keys($reasonsByMoonName));
        self::assertStringContainsString('Cinnabar', $reasonsByMoonName['Veyra II - Moon 3']);
        self::assertStringContainsString('Tritanium', $reasonsByMoonName['Veyra II - Moon 4']);
        self::assertStringContainsString('120', $reasonsByMoonName['Veyra III - Moon 4']);
        self::assertSame(3, $report->imported);
    }

    public function testARejectedRowStoresNothingEvenForItsValidOres(): void
    {
        $this->importer->import($this->sheet);

        $storedMoonIds = array_map(static fn (array $row): int => $row[1], $this->importedCompositions());
        self::assertNotContains(self::UNKNOWN_ORE_MOON, $storedMoonIds);
        self::assertNotContains(self::NOT_AN_ORE_MOON, $storedMoonIds);
        self::assertNotContains(self::OVERFULL_MOON, $storedMoonIds);
    }

    public function testAMoonOfASystemWhoseNameContainsAHyphenIsMatchedOnTheSpacedSeparatorOfTheSheet(): void
    {
        $this->persistExtraction(self::CORPORATION_A, self::HYPHENATED_SYSTEM_MOON, 'Veyra-4K II - Moon 1', '2026-11-01');
        $this->em->flush();
        $sheet = "Moon Name,Region,Ore Composition,Max Value\nVeyra-4K - II - Moon 1,Northreach,Sylvite: 0.7;Zeolites: 0.2,\"1,000.0\"\n";

        $report = $this->importer->import($sheet);

        self::assertSame([1, 1, 0], [$report->matched, $report->imported, count($report->rejected)]);
        self::assertSame(
            [[self::CORPORATION_A, self::HYPHENATED_SYSTEM_MOON, null, '2026-11-13 20:00:00', [['typeId' => self::SYLVITE, 'share' => 0.7], ['typeId' => self::ZEOLITES, 'share' => 0.2]]]],
            $this->importedCompositions(),
        );
    }

    public function testRunningTheImportAgainChangesNothing(): void
    {
        $this->importer->import($this->sheet);
        $afterFirstRun = $this->allCompositions();
        self::mockTime(new \DateTimeImmutable('2026-11-14T08:00:00+00:00'));

        $secondReport = $this->importer->import($this->sheet);

        self::assertSame([0, 4, 3], [$secondReport->imported, $secondReport->skippedAlreadyScanned, count($secondReport->rejected)]);
        self::assertSame($afterFirstRun, $this->allCompositions());
    }

    private function persistExtraction(int $corporationId, int $moonId, ?string $moonName, string $startDate): void
    {
        $start = new \DateTimeImmutable($startDate . 'T18:00:00+00:00');
        $this->em->persist(
            (new CorporationMiningExtraction())
                ->setCorporationId($corporationId)
                ->setStructureId(1_030_000_000_001)
                ->setMoonId($moonId)
                ->setMoonName($moonName)
                ->setExtractionStartTime($start)
                ->setChunkArrivalTime($start->modify('+3 days'))
                ->setNaturalDecayTime($start->modify('+3 days 3 hours')),
        );
    }

    /**
     * @return list<array{0: int, 1: int, 2: ?string, 3: string, 4: array}>
     */
    private function importedCompositions(): array
    {
        return array_values(array_filter($this->allCompositions(), static fn (array $row): bool => $row[2] === null));
    }

    /**
     * @return list<array{0: int, 1: int, 2: ?string, 3: string, 4: array}>
     */
    private function scannedCompositions(): array
    {
        return array_values(array_filter($this->allCompositions(), static fn (array $row): bool => $row[2] !== null));
    }

    /**
     * @return list<array{0: int, 1: int, 2: ?string, 3: string, 4: array}>
     */
    private function allCompositions(): array
    {
        $this->em->clear();

        return array_map(
            static fn (array $row): array => [(int) $row['corporation_id'], (int) $row['moon_id'], $row['recorded_by_id'], $row['recorded_at'], json_decode($row['lines'], true, flags: JSON_THROW_ON_ERROR)],
            $this->em->getConnection()->fetchAllAssociative('SELECT corporation_id, moon_id, recorded_by_id, recorded_at, lines FROM moon_compositions ORDER BY moon_id, corporation_id'),
        );
    }
}
