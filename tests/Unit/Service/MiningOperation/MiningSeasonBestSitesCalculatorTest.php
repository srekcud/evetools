<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Service\MiningOperation\MiningSeasonCalculator;
use App\Service\MiningOperation\MiningSeasonOperation;
use App\Service\MiningOperation\MiningSeasonSite;
use App\Service\MiningOperation\MiningSeasonSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningSeasonCalculator::class)]
#[CoversClass(MiningSeasonSummary::class)]
#[CoversClass(MiningSeasonSite::class)]
#[Group('issue-317')]
final class MiningSeasonBestSitesCalculatorTest extends TestCase
{
    private const int ALDRANETTE = 30_003_504;
    private const int AMARR = 30_002_187;
    private const int JITA = 30_000_142;
    private const int RENS = 30_002_510;
    private const int ONE_HOUR = 3_600;

    private MiningSeasonCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new MiningSeasonCalculator();
    }

    public function testBestSiteOfASystemIsTheOneWithTheHighestIskPerHourAndTheOtherSiteIsOnlyCounted(): void
    {
        $summary = $this->summaryOf([
            $this->measured(self::ALDRANETTE, 'Belt Aldranette', self::ONE_HOUR, 1_800_000.0),
            $this->measured(self::ALDRANETTE, 'Belt Couronne', 1_800, 450_000.0),
        ]);

        self::assertSame([[self::ALDRANETTE, 2, ['Belt Aldranette', 1_800_000, 3_600], []]], $this->rowsOf($summary));
    }

    public function testSiteWithTwoScansAnHourApartAndAnExtractedValueIsItsExtractedValuePerHour(): void
    {
        $summary = $this->summaryOf([$this->measured(self::ALDRANETTE, 'Belt Aldranette', self::ONE_HOUR, 3_000_000.0 - 1_200_000.0)]);

        self::assertSame([[self::ALDRANETTE, 1, ['Belt Aldranette', 1_800_000, 3_600], []]], $this->rowsOf($summary));
    }

    public function testSitesWithTheSameIskPerHourGiveTheBestSiteToTheLongestObservation(): void
    {
        $summary = $this->summaryOf([
            $this->measured(self::ALDRANETTE, 'Belt Aldranette', self::ONE_HOUR, 1_800_000.0),
            $this->measured(self::ALDRANETTE, 'Belt Dorsale', 5_400, 2_700_000.0),
        ]);

        self::assertSame([[self::ALDRANETTE, 2, ['Belt Dorsale', 1_800_000, 5_400], []]], $this->rowsOf($summary));
    }

    public function testSitesWithTheSameIskPerHourAndTheSameDurationGiveTheBestSiteToTheFirstLabelInAlphabeticalOrder(): void
    {
        $summary = $this->summaryOf([
            $this->measured(self::ALDRANETTE, 'Belt Beta', self::ONE_HOUR, 1_800_000.0),
            $this->measured(self::ALDRANETTE, 'Belt Alpha', self::ONE_HOUR, 1_800_000.0),
        ]);

        self::assertSame([[self::ALDRANETTE, 2, ['Belt Alpha', 1_800_000, 3_600], []]], $this->rowsOf($summary));
    }

    public function testSiteOfThirtyMinutesWithOneMillionExtractedIsTwoMillionIskPerHour(): void
    {
        $summary = $this->summaryOf([$this->measured(self::ALDRANETTE, 'Belt Aldranette', 1_800, 1_000_000.0)]);

        self::assertSame([[self::ALDRANETTE, 1, ['Belt Aldranette', 2_000_000, 1_800], []]], $this->rowsOf($summary));
    }

    public function testIskPerHourOfASiteIsRoundedToTheNearestIskAndAHalfIskRoundsUp(): void
    {
        $summary = $this->summaryOf([
            $this->measured(self::ALDRANETTE, 'Belt Aldranette', 7, 1_000_000.0),
            $this->measured(self::AMARR, 'Belt Amarr', 2_400, 5.0),
        ]);

        self::assertSame(
            [
                [self::ALDRANETTE, 1, ['Belt Aldranette', 514_285_714, 7], []],
                [self::AMARR, 1, ['Belt Amarr', 8, 2_400], []],
            ],
            $this->rowsOf($summary),
        );
    }

    public function testSiteWithASingleScanIsUnknownBecauseOfFewScansAndNeverZeroIskPerHour(): void
    {
        $summary = $this->summaryOf([new MiningSeasonSite(self::AMARR, 'Belt Amarr', 1, 0, null)]);

        self::assertSame([[self::AMARR, 1, null, [['Belt Amarr', 'fewScans']]]], $this->rowsOf($summary));
    }

    public function testSiteWithoutAnyVisibleScanIsUnknownBecauseOfFewScans(): void
    {
        $summary = $this->summaryOf([new MiningSeasonSite(self::AMARR, 'Belt Amarr', 0, 0, null)]);

        self::assertSame([[self::AMARR, 1, null, [['Belt Amarr', 'fewScans']]]], $this->rowsOf($summary));
    }

    public function testSiteWhoseLastScanHasNoPriceIsUnknownBecauseOfNoPrice(): void
    {
        $summary = $this->summaryOf([new MiningSeasonSite(self::AMARR, 'Belt Amarr', 2, 2_700, null)]);

        self::assertSame([[self::AMARR, 1, null, [['Belt Amarr', 'noPrice']]]], $this->rowsOf($summary));
    }

    public function testSiteWithTwoScansAtTheSameInstantIsUnknownBecauseOfNoPriceInsteadOfDividingByZero(): void
    {
        $summary = $this->summaryOf([new MiningSeasonSite(self::AMARR, 'Belt Amarr', 2, 0, 750_000.0)]);

        self::assertSame([[self::AMARR, 1, null, [['Belt Amarr', 'noPrice']]]], $this->rowsOf($summary));
    }

    public function testUnknownSiteOfASystemWithABestSiteIsListedAndTheBestSiteIsNot(): void
    {
        $summary = $this->summaryOf([
            $this->measured(self::ALDRANETTE, 'Belt Aldranette', self::ONE_HOUR, 1_800_000.0),
            new MiningSeasonSite(self::ALDRANETTE, 'Belt Sans Prix', 2, 1_200, null),
            new MiningSeasonSite(self::ALDRANETTE, 'Belt Solitaire', 1, 0, null),
        ]);

        self::assertSame(
            [[self::ALDRANETTE, 3, ['Belt Aldranette', 1_800_000, 3_600], [['Belt Sans Prix', 'noPrice'], ['Belt Solitaire', 'fewScans']]]],
            $this->rowsOf($summary),
        );
    }

    public function testSiteWithoutSolarSystemIsLeftOutOfTheTableAndCounted(): void
    {
        $summary = $this->summaryOf([
            $this->measured(self::ALDRANETTE, 'Belt Aldranette', self::ONE_HOUR, 1_800_000.0),
            $this->measured(null, 'Belt Perdue', 2_400, 900_000.0),
        ]);

        self::assertSame([[[self::ALDRANETTE, 1, ['Belt Aldranette', 1_800_000, 3_600], []]], 1], [$this->rowsOf($summary), $summary->sitesWithoutSystemCount]);
    }

    public function testSystemsAreSortedByTheIskPerHourOfTheirBestSiteHighestFirst(): void
    {
        $summary = $this->summaryOf([
            $this->measured(self::ALDRANETTE, 'Belt Aldranette', self::ONE_HOUR, 1_800_000.0),
            $this->measured(self::JITA, 'Belt Jita', 1_800, 1_200_000.0),
        ]);

        self::assertSame([self::JITA, self::ALDRANETTE], array_column($summary->sitesBySystem, 'solarSystemId'));
    }

    public function testSystemsWithoutABestSiteComeLastAndSystemsWithTheSameIskPerHourAreSortedBySolarSystemId(): void
    {
        $summary = $this->summaryOf([
            new MiningSeasonSite(self::AMARR, 'Belt Amarr', 1, 0, null),
            $this->measured(self::ALDRANETTE, 'Belt Aldranette', self::ONE_HOUR, 1_000_000.0),
            new MiningSeasonSite(self::RENS, 'Belt Rens', 2, 600, null),
            $this->measured(self::JITA, 'Belt Jita', 1_800, 500_000.0),
        ]);

        self::assertSame([self::JITA, self::ALDRANETTE, self::AMARR, self::RENS], array_column($summary->sitesBySystem, 'solarSystemId'));
    }

    public function testNegativeExtractedValueStaysAKnownIskPerHourThatAPositiveSiteOfTheSameSystemBeats(): void
    {
        $summary = $this->summaryOf([
            $this->measured(self::ALDRANETTE, 'Belt Recul', self::ONE_HOUR, -300_000.0),
            $this->measured(self::ALDRANETTE, 'Belt Gain', 1_800, 450_000.0),
            $this->measured(self::JITA, 'Belt Jita', 7_200, -1_200_000.0),
            new MiningSeasonSite(self::AMARR, 'Belt Amarr', 1, 0, null),
        ]);

        self::assertSame(
            [
                [self::ALDRANETTE, 2, ['Belt Gain', 900_000, 1_800], []],
                [self::JITA, 1, ['Belt Jita', -600_000, 7_200], []],
                [self::AMARR, 1, null, [['Belt Amarr', 'fewScans']]],
            ],
            $this->rowsOf($summary),
        );
    }

    public function testSitesOfSeveralOperationsAreGroupedBySystemAndThoseOfAnUnknownValueOperationCount(): void
    {
        $summary = $this->calculator->summarise([
            new MiningSeasonOperation(120, 40_000_000, [], [], [$this->measured(self::ALDRANETTE, 'Belt Aldranette', self::ONE_HOUR, 1_800_000.0)]),
            new MiningSeasonOperation(90, null, [], [], [$this->measured(self::ALDRANETTE, 'Belt Dorsale', 2_700, 2_700_000.0)]),
        ]);

        self::assertSame([[self::ALDRANETTE, 2, ['Belt Dorsale', 3_600_000, 2_700], []]], $this->rowsOf($summary));
    }

    public function testNoOperationGivesNoSystemAndNoSiteWithoutSystem(): void
    {
        $summary = $this->calculator->summarise([]);

        self::assertSame([[], 0], [$summary->sitesBySystem, $summary->sitesWithoutSystemCount]);
    }

    private function measured(?int $solarSystemId, string $label, int $observedSeconds, float $extractedValue): MiningSeasonSite
    {
        return new MiningSeasonSite($solarSystemId, $label, 2, $observedSeconds, $extractedValue);
    }

    private function summaryOf(array $sites): MiningSeasonSummary
    {
        return $this->calculator->summarise([new MiningSeasonOperation(120, 10_000_000, [], [], $sites)]);
    }

    private function rowsOf(MiningSeasonSummary $summary): array
    {
        return array_map(
            static fn (array $row): array => [
                $row['solarSystemId'],
                $row['sitesCount'],
                $row['bestSite'] === null ? null : [$row['bestSite']['label'], $row['bestSite']['iskPerHour'], $row['bestSite']['observedSeconds']],
                array_map(static fn (array $unknown): array => [$unknown['label'], $unknown['reason']], $row['unknownSites']),
            ],
            $summary->sitesBySystem,
        );
    }
}
