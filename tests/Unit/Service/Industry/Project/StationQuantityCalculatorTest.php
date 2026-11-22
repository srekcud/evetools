<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Service\Industry\Project\ProjectContainerContent;
use App\Service\Industry\Project\ProjectContainerReading;
use App\Service\Industry\Project\StationQuantity;
use App\Service\Industry\Project\StationQuantityCalculator;
use App\Service\Industry\Project\TypeStationQuantities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StationQuantityCalculator::class)]
#[Group('issue-1294')]
#[Group('issue-1353')]
final class StationQuantityCalculatorTest extends TestCase
{
    use BuildsChainBomLines;

    private const int STATION_A = 100;
    private const int STATION_B = 200;
    private const string STRUCTURE_A = '01970000-0000-7000-8000-00000000a001';
    private const string STRUCTURE_B = '01970000-0000-7000-8000-00000000b002';
    private const int ZYDRINE = 39;
    private const int TRITANIUM = 34;
    private const int COMPONENT_X = 1_294_001;
    private const int ISHTAR = 12_005;
    private const int CAPITAL_HULL = 1_294_002;
    private const int DATACORE = 1_294_003;
    private const int BLUEPRINT_COPY = 1_294_004;
    private const int REACTION_PRODUCT = 1_294_005;

    private int $nextEsiJobId = 1_294_100;
    private IndustryProjectBomLine $zydrineLine;
    private IndustryProjectBomLine $tritaniumLine;
    private IndustryProjectBomLine $componentXJob;
    private IndustryProjectBomLine $finalJob;

    protected function setUp(): void
    {
        $this->zydrineLine = $this->materialLine(self::ZYDRINE, 'Zydrine', 100, 0);
        $this->tritaniumLine = $this->materialLine(self::TRITANIUM, 'Tritanium', 80, 0);
        $this->componentXJob = $this->jobLine(self::COMPONENT_X, 'Composant X', 'component', self::ISHTAR, 8)
            ->setRequiredQuantity(40)
            ->setPlannedStructureId(self::STRUCTURE_A);
        $this->finalJob = $this->jobLine(self::ISHTAR, 'Ishtar', 'final', null, 10)
            ->setPlannedStructureId(self::STRUCTURE_B);
    }

    public function testStationWithNoStockBringsTheWholeNeedOfItsJobLine(): void
    {
        $entries = $this->computeScenario($this->emptyContainers());

        self::assertSame([[self::STATION_B, 100, 0, 0, 0, 0, 100, 0, null]], $this->rowsOf($this->entryOf($entries, self::ZYDRINE)));
    }

    public function testStockInTheProjectContainerOfTheStationIsOnSiteAndTheRestIsToBring(): void
    {
        $entries = $this->computeScenario($this->containersWith(self::STATION_B, [new ProjectContainerContent(self::ZYDRINE, 'Zydrine', 60)]));

        self::assertSame([[self::STATION_B, 100, 0, 0, 60, 0, 40, 0, null]], $this->rowsOf($this->entryOf($entries, self::ZYDRINE)));
    }

    public function testStockBeyondTheNeedOfTheStationIsOutAndNothingIsToBring(): void
    {
        $entries = $this->computeScenario($this->containersWith(self::STATION_B, [new ProjectContainerContent(self::ZYDRINE, 'Zydrine', 130)]));

        self::assertSame([[self::STATION_B, 100, 0, 0, 100, 30, 0, 0, null]], $this->rowsOf($this->entryOf($entries, self::ZYDRINE)));
    }

    public function testStartedRunsAreConsumedAndReduceWhatTheStationStillNeeds(): void
    {
        $this->withMatch($this->finalJob, CachedIndustryJob::STATUS_ACTIVE, 5);

        $entries = $this->computeScenario($this->containersWith(self::STATION_B, [new ProjectContainerContent(self::ZYDRINE, 'Zydrine', 30)]));

        self::assertSame([[self::STATION_B, 100, 50, 0, 30, 0, 20, 0, null]], $this->rowsOf($this->entryOf($entries, self::ZYDRINE)));
        self::assertSame([[self::STATION_B, 40, 20, 0, 0, 0, 20, 0, null]], $this->rowsOf($this->entryOf($entries, self::COMPONENT_X)), 'the started runs consume the Composant X of station B too');
    }

    public function testUnreadableContainerGivesNullOnSiteOutAndToBringWithItsReason(): void
    {
        $readings = [
            ProjectContainerReading::unknown('c-a', 'Ishtar A', self::STATION_A, 'corporation_assets_not_synced', null),
            ProjectContainerReading::known('c-b', 'Ishtar B', self::STATION_B, [], null),
        ];

        $entries = $this->computeScenario($readings);

        $tritanium = $this->entryOf($entries, self::TRITANIUM);
        self::assertNull($tritanium->unknownReason);
        self::assertSame([[self::STATION_A, 80, 0, 0, null, null, null, 0, 'corporation_assets_not_synced']], $this->rowsOf($tritanium));
    }

    public function testActiveJobIsInProductionAtItsStationWhileItsProductIsToBringToTheConsumingStation(): void
    {
        $this->withMatch($this->componentXJob, CachedIndustryJob::STATUS_ACTIVE, 8);

        $entries = $this->computeScenario($this->emptyContainers());

        self::assertSame(
            [
                [self::STATION_A, 0, 0, 40, 0, 0, 0, 0, null],
                [self::STATION_B, 40, 0, 0, 0, 0, 40, 0, null],
            ],
            $this->rowsOf($this->entryOf($entries, self::COMPONENT_X)),
        );
        self::assertSame([[self::STATION_A, 80, 80, 0, 0, 0, 0, 0, null]], $this->rowsOf($this->entryOf($entries, self::TRITANIUM)));
    }

    public function testDeliveredJobIsOutOfTheMachineAndItsProductInTheContainerOfItsStationIsOut(): void
    {
        $this->withMatch($this->componentXJob, CachedIndustryJob::STATUS_DELIVERED, 8);

        $entries = $this->computeScenario($this->containersWith(self::STATION_A, [new ProjectContainerContent(self::COMPONENT_X, 'Composant X', 40)]));

        self::assertSame(
            [
                [self::STATION_A, 0, 0, 0, 0, 40, 0, 0, null],
                [self::STATION_B, 40, 0, 0, 0, 0, 40, 0, null],
            ],
            $this->rowsOf($this->entryOf($entries, self::COMPONENT_X)),
        );
    }

    public function testComponentInTheContainerOfTheConsumingStationIsOnSite(): void
    {
        $entries = $this->computeScenario($this->containersWith(self::STATION_B, [new ProjectContainerContent(self::COMPONENT_X, 'Composant X', 15)]));

        self::assertSame([[self::STATION_B, 40, 0, 0, 15, 0, 25, 0, null]], $this->rowsOf($this->entryOf($entries, self::COMPONENT_X)));
    }

    public function testFinalProductInTheContainerIsOutAndNeverNeeded(): void
    {
        $entries = $this->computeScenario($this->containersWith(self::STATION_B, [new ProjectContainerContent(self::ISHTAR, 'Ishtar', 20)]));

        self::assertSame([[self::STATION_B, 0, 0, 0, 0, 20, 0, 0, null]], $this->rowsOf($this->entryOf($entries, self::ISHTAR)));
    }

    public function testContainerOfTheProjectWithoutLocationFeedsNoEntry(): void
    {
        $readings = [
            ...$this->emptyContainers(),
            ProjectContainerReading::known('c-nowhere', 'Ishtar legacy', null, [new ProjectContainerContent(self::ZYDRINE, 'Zydrine', 50)], null),
        ];

        $entries = $this->computeScenario($readings);

        self::assertSame([[self::STATION_B, 100, 0, 0, 0, 0, 100, 0, null]], $this->rowsOf($this->entryOf($entries, self::ZYDRINE)));
    }

    public function testJobLineWithoutPlannedStructureIsAnUnresolvedStationEntry(): void
    {
        $this->finalJob->setPlannedStructureId(null);

        $entries = $this->computeScenario($this->emptyContainers());

        self::assertSame([[null, 100, 0, 0, null, null, null, 0, 'station_unresolved']], $this->rowsOf($this->entryOf($entries, self::ZYDRINE)));
        self::assertNull($this->entryOf($entries, self::ZYDRINE)->stations[0]->stationName);
    }

    public function testPlannedStructureAbsentOrWithoutLocationIdIsNeverAnInventedStation(): void
    {
        foreach ([[self::STRUCTURE_A => self::STATION_A, self::STRUCTURE_B => null], [self::STRUCTURE_A => self::STATION_A]] as $locationIdByStructureId) {
            $entries = $this->computeScenario($this->emptyContainers(), $locationIdByStructureId);

            self::assertSame([[null, 100, 0, 0, null, null, null, 0, 'station_unresolved']], $this->rowsOf($this->entryOf($entries, self::ZYDRINE)));
        }
    }

    public function testStationWithoutProjectContainerIsUnknownNotZero(): void
    {
        $readings = [ProjectContainerReading::known('c-a', 'Ishtar A', self::STATION_A, [], null)];

        $entries = $this->computeScenario($readings);

        self::assertSame([[self::STATION_B, 100, 0, 0, null, null, null, 0, 'no_container_at_station']], $this->rowsOf($this->entryOf($entries, self::ZYDRINE)));
    }

    public function testStationWithoutLocatedContainerIsUnknownBecauseAProjectContainerHasNoLocation(): void
    {
        $readings = [
            ProjectContainerReading::known('c-a', 'Ishtar A', self::STATION_A, [], null),
            ProjectContainerReading::known('c-nowhere', 'Ishtar legacy', null, [], null),
        ];

        $entries = $this->computeScenario($readings);

        self::assertSame([[self::STATION_B, 100, 0, 0, null, null, null, 0, 'container_location_unknown']], $this->rowsOf($this->entryOf($entries, self::ZYDRINE)));
    }

    public function testStationWithALocatedContainerIsNotAffectedByAnUnlocatedContainer(): void
    {
        $readings = [
            ProjectContainerReading::known('c-b', 'Ishtar B', self::STATION_B, [new ProjectContainerContent(self::ZYDRINE, 'Zydrine', 30)], null),
            ProjectContainerReading::known('c-nowhere', 'Ishtar legacy', null, [], null),
        ];

        $entries = $this->computeScenario($readings);

        self::assertSame([[self::STATION_B, 100, 0, 0, 30, 0, 70, 0, null]], $this->rowsOf($this->entryOf($entries, self::ZYDRINE)));
    }

    public function testUncomputablePlanGivesNullStationsAndPlanUnavailableForEveryType(): void
    {
        $entries = $this->compute([$this->zydrineLine, $this->tritaniumLine, $this->componentXJob, $this->finalJob], null, $this->emptyContainers());

        self::assertCount(4, $entries);
        foreach ($entries as $entry) {
            self::assertNull($entry->stations);
            self::assertSame('plan_unavailable', $entry->unknownReason);
        }
    }

    public function testStartedRunsWithoutKnownNeedGiveNullStationsAndConsumptionUnknownForEveryType(): void
    {
        $this->withMatch($this->finalJob, CachedIndustryJob::STATUS_ACTIVE, 5);
        $needsWithoutFinalJob = [self::lineId($this->componentXJob) => [self::TRITANIUM => 80]];

        $entries = $this->compute([$this->zydrineLine, $this->tritaniumLine, $this->componentXJob, $this->finalJob], $needsWithoutFinalJob, $this->emptyContainers());

        self::assertCount(4, $entries);
        foreach ($entries as $entry) {
            self::assertNull($entry->stations);
            self::assertSame('consumption_unknown', $entry->unknownReason);
        }
    }

    public function testCancelledJobConsumesNothingAndProducesNothing(): void
    {
        $this->withMatch($this->finalJob, CachedIndustryJob::STATUS_CANCELLED, 10);

        $entries = $this->computeScenario($this->emptyContainers());

        self::assertSame([[self::STATION_B, 100, 0, 0, 0, 0, 100, 0, null]], $this->rowsOf($this->entryOf($entries, self::ZYDRINE)));
        self::assertSame([], $this->rowsOf($this->entryOf($entries, self::ISHTAR)));
    }

    public function testLineOfZeroRunsCoveredByStockBringsNoNeedAndNoProduction(): void
    {
        $this->finalJob->setRuns(0)->setRequiredQuantity(0);

        $entries = $this->compute(
            [$this->zydrineLine, $this->finalJob],
            [self::lineId($this->finalJob) => []],
            $this->emptyContainers(),
        );

        self::assertSame([], $this->rowsOf($this->entryOf($entries, self::ZYDRINE)));
        self::assertSame([], $this->rowsOf($this->entryOf($entries, self::ISHTAR)));
    }

    public function testTwoJobLinesOfTheSameTypeAddTheirNeedsConsumptionsAndProductions(): void
    {
        $firstVariant = $this->jobLine(self::ISHTAR, 'Ishtar', 'final', null, 6)->setPlannedStructureId(self::STRUCTURE_B);
        $secondVariant = $this->jobLine(self::ISHTAR, 'Ishtar', 'final', null, 4, 'manufacturing', 8, 16)->setPlannedStructureId(self::STRUCTURE_B);
        $this->withMatch($firstVariant, CachedIndustryJob::STATUS_ACTIVE, 3);
        $this->withMatch($secondVariant, CachedIndustryJob::STATUS_ACTIVE, 2);
        $needs = [
            self::lineId($firstVariant) => [self::ZYDRINE => 60],
            self::lineId($secondVariant) => [self::ZYDRINE => 44],
        ];

        $entries = $this->compute([$this->zydrineLine, $firstVariant, $secondVariant], $needs, $this->emptyContainers());

        self::assertSame([[self::STATION_B, 104, 52, 0, 0, 0, 52, 0, null]], $this->rowsOf($this->entryOf($entries, self::ZYDRINE)));
        $ishtar = $this->entryOf($entries, self::ISHTAR);
        self::assertSame([self::lineId($firstVariant), self::lineId($secondVariant)], $ishtar->lineIds);
        self::assertSame([[self::STATION_B, 0, 0, 5, 0, 0, 0, 0, null]], $this->rowsOf($ishtar));
    }

    public function testTypeNameFallsBackToTheBomLineWhenTheAssetHasNone(): void
    {
        $entries = $this->computeScenario($this->containersWith(self::STATION_B, [new ProjectContainerContent(self::ZYDRINE, null, 60)]));

        self::assertSame('Zydrine', $this->entryOf($entries, self::ZYDRINE)->typeName);
        self::assertSame([[self::STATION_B, 100, 0, 0, 60, 0, 40, 0, null]], $this->rowsOf($this->entryOf($entries, self::ZYDRINE)));
    }

    public function testEntriesAreOrderedByTypeNameThenTypeIdAndEachListsItsLines(): void
    {
        $twinLow = $this->materialLine(41, 'Twin', 5, 0);
        $twinHigh = $this->materialLine(50, 'Twin', 6, 0);

        $entries = $this->compute(
            [$this->zydrineLine, $twinHigh, $this->finalJob, $this->tritaniumLine, $this->componentXJob, $twinLow],
            [],
            $this->emptyContainers(),
        );

        self::assertSame(
            [
                [self::COMPONENT_X, 'Composant X', [self::lineId($this->componentXJob)]],
                [self::ISHTAR, 'Ishtar', [self::lineId($this->finalJob)]],
                [self::TRITANIUM, 'Tritanium', [self::lineId($this->tritaniumLine)]],
                [41, 'Twin', [self::lineId($twinLow)]],
                [50, 'Twin', [self::lineId($twinHigh)]],
                [self::ZYDRINE, 'Zydrine', [self::lineId($this->zydrineLine)]],
            ],
            array_map(static fn (TypeStationQuantities $entry): array => [$entry->typeId, $entry->typeName, $entry->lineIds], $entries),
        );
    }

    public function testStationsFollowThePlanOrderThenLocationIdThenTheUnresolvedStation(): void
    {
        $jobAtB = $this->finalJob;
        $jobAtA = $this->componentXJob;
        $jobWithoutStation = $this->jobLine(self::CAPITAL_HULL, 'Capital Hull', 'component', self::ISHTAR, 2);
        $readings = [
            ProjectContainerReading::known('c-a', 'Ishtar A', self::STATION_A, [], null),
            ProjectContainerReading::known('c-b', 'Ishtar B', self::STATION_B, [], null),
            ProjectContainerReading::known('c-300', 'Ishtar 300', 300, [new ProjectContainerContent(self::ZYDRINE, 'Zydrine', 7)], null),
            ProjectContainerReading::known('c-250', 'Ishtar 250', 250, [new ProjectContainerContent(self::ZYDRINE, 'Zydrine', 3)], null),
        ];
        $needs = [
            self::lineId($jobAtB) => [self::ZYDRINE => 10],
            self::lineId($jobAtA) => [self::ZYDRINE => 5],
            self::lineId($jobWithoutStation) => [self::ZYDRINE => 2],
        ];

        $entries = $this->compute(
            [$this->zydrineLine, $jobWithoutStation, $jobAtA, $jobAtB],
            $needs,
            $readings,
            [self::STRUCTURE_A => self::STATION_A, self::STRUCTURE_B => self::STATION_B],
            [self::STATION_B => 'Structure B', self::STATION_A => 'Structure A'],
        );

        $zydrine = $this->entryOf($entries, self::ZYDRINE);
        self::assertSame(
            [
                [self::STATION_B, 10, 0, 0, 0, 0, 10, 0, null],
                [self::STATION_A, 5, 0, 0, 0, 0, 5, 0, null],
                [250, 0, 0, 0, 0, 3, 0, 0, null],
                [300, 0, 0, 0, 0, 7, 0, 0, null],
                [null, 2, 0, 0, null, null, null, 0, 'station_unresolved'],
            ],
            $this->rowsOf($zydrine),
        );
        self::assertSame(
            ['Structure B', 'Structure A', null, null, null],
            array_map(static fn (StationQuantity $station): ?string => $station->stationName, $zydrine->stations),
        );
    }

    public function testNeedFullyConsumedLeavesNothingToBringAndTheStockIsOut(): void
    {
        $this->withMatch($this->finalJob, CachedIndustryJob::STATUS_DELIVERED, 10);

        $entries = $this->computeScenario($this->containersWith(self::STATION_B, [new ProjectContainerContent(self::ZYDRINE, 'Zydrine', 3)]));

        self::assertSame([[self::STATION_B, 100, 100, 0, 0, 3, 0, 0, null]], $this->rowsOf($this->entryOf($entries, self::ZYDRINE)));
    }

    public function testActivePausedAndReadyRunsAreInProductionWhileDeliveredAndRevertedOnesAreNot(): void
    {
        $this->componentXJob->setRuns(10)->setRequiredQuantity(30);
        $this->withMatch($this->componentXJob, CachedIndustryJob::STATUS_READY, 2);
        $this->withMatch($this->componentXJob, CachedIndustryJob::STATUS_PAUSED, 3);
        $this->withMatch($this->componentXJob, CachedIndustryJob::STATUS_DELIVERED, 1);
        $this->withMatch($this->componentXJob, CachedIndustryJob::STATUS_REVERTED, 4);

        $entries = $this->computeScenario($this->emptyContainers());

        self::assertSame(15, $this->rowAt($this->entryOf($entries, self::COMPONENT_X), self::STATION_A)[3]);
    }

    public function testInProductionNeverExceedsTheQuantityOfTheLine(): void
    {
        $this->withMatch($this->componentXJob, CachedIndustryJob::STATUS_ACTIVE, 6);
        $this->withMatch($this->componentXJob, CachedIndustryJob::STATUS_ACTIVE, 6);

        $entries = $this->computeScenario($this->emptyContainers());

        self::assertSame(40, $this->rowAt($this->entryOf($entries, self::COMPONENT_X), self::STATION_A)[3]);
    }

    public function testActiveReactionLineIsInProductionAtItsStation(): void
    {
        $reaction = $this->jobLine(self::REACTION_PRODUCT, 'Photonic Reaction', 'component', self::ISHTAR, 20, 'reaction')
            ->setRequiredQuantity(2_000)
            ->setPlannedStructureId(self::STRUCTURE_A);
        $this->withMatch($reaction, CachedIndustryJob::STATUS_ACTIVE, 5);

        $entries = $this->compute([$reaction], [self::lineId($reaction) => [self::TRITANIUM => 600]], $this->emptyContainers());

        self::assertSame([[self::STATION_A, 0, 0, 500, 0, 0, 0, 0, null]], $this->rowsOf($this->entryOf($entries, self::REACTION_PRODUCT)));
    }

    public function testBlueprintJobLineBringsNoNeedAndIsNotInProduction(): void
    {
        $copy = $this->jobLine(self::BLUEPRINT_COPY, 'Ishtar Blueprint Copy', 'blueprint', self::ISHTAR, 5, 'copy')
            ->setPlannedStructureId(self::STRUCTURE_A);
        $datacoreLine = $this->materialLine(self::DATACORE, 'Datacore', 25, 0);
        $this->withMatch($copy, CachedIndustryJob::STATUS_ACTIVE, 5);

        $entries = $this->compute([$datacoreLine, $copy], [self::lineId($copy) => [self::DATACORE => 25]], $this->emptyContainers());

        self::assertSame([], $this->rowsOf($this->entryOf($entries, self::BLUEPRINT_COPY)));
        self::assertSame([], $this->rowsOf($this->entryOf($entries, self::DATACORE)));
    }

    public function testTransferInTransitToTheStationIsDeductedFromWhatIsToBring(): void
    {
        $readings = $this->containersWith(self::STATION_B, [new ProjectContainerContent(self::ZYDRINE, 'Zydrine', 60)]);

        $entries = $this->computeScenario($readings, inTransitByLocationAndType: [self::STATION_B => [self::ZYDRINE => 30]]);

        self::assertSame([[self::STATION_B, 100, 0, 0, 60, 0, 10, 30, null]], $this->rowsOf($this->entryOf($entries, self::ZYDRINE)));
    }

    public function testTransferCoveringTheWholeNeedLeavesNothingToBring(): void
    {
        $entries = $this->computeScenario($this->emptyContainers(), inTransitByLocationAndType: [self::STATION_B => [self::ZYDRINE => 100]]);

        self::assertSame([[self::STATION_B, 100, 0, 0, 0, 0, 0, 100, null]], $this->rowsOf($this->entryOf($entries, self::ZYDRINE)));
    }

    public function testUnreadableTransferGivesNullInTransitAndNullToBringWithTransferUnreadable(): void
    {
        $readings = $this->containersWith(self::STATION_B, [new ProjectContainerContent(self::ZYDRINE, 'Zydrine', 60)]);

        $entries = $this->computeScenario($readings, inTransitByLocationAndType: [self::STATION_B => [self::ZYDRINE => null]]);

        self::assertSame([[self::STATION_B, 100, 0, 0, 60, 0, null, null, 'transfer_unreadable']], $this->rowsOf($this->entryOf($entries, self::ZYDRINE)));
    }

    public function testStationWithOnlyAnInTransitQuantityHasAnEntryOfItsOwn(): void
    {
        $entries = $this->computeScenario($this->emptyContainers(), inTransitByLocationAndType: [self::STATION_A => [self::ZYDRINE => 25]]);

        self::assertSame(
            [
                [self::STATION_A, 0, 0, 0, 0, 0, 0, 25, null],
                [self::STATION_B, 100, 0, 0, 0, 0, 100, 0, null],
            ],
            $this->rowsOf($this->entryOf($entries, self::ZYDRINE)),
        );
    }

    private function computeScenario(array $readings, array $locationIdByStructureId = [self::STRUCTURE_A => self::STATION_A, self::STRUCTURE_B => self::STATION_B], array $inTransitByLocationAndType = []): array
    {
        $needs = [
            self::lineId($this->componentXJob) => [self::TRITANIUM => 80],
            self::lineId($this->finalJob) => [self::ZYDRINE => 100, self::COMPONENT_X => 40],
        ];

        return $this->compute([$this->zydrineLine, $this->tritaniumLine, $this->componentXJob, $this->finalJob], $needs, $readings, $locationIdByStructureId, inTransitByLocationAndType: $inTransitByLocationAndType);
    }

    private function compute(
        array $bomLines,
        ?array $needs,
        array $readings,
        array $locationIdByStructureId = [self::STRUCTURE_A => self::STATION_A, self::STRUCTURE_B => self::STATION_B],
        array $stationNameByLocationId = [self::STATION_A => 'Structure A', self::STATION_B => 'Structure B'],
        array $inTransitByLocationAndType = [],
    ): array {
        return (new StationQuantityCalculator())->compute($bomLines, $needs, $readings, $locationIdByStructureId, $stationNameByLocationId, $inTransitByLocationAndType);
    }

    private function emptyContainers(): array
    {
        return $this->containersWith(self::STATION_B, []);
    }

    private function containersWith(int $locationId, array $contents): array
    {
        $other = self::STATION_A === $locationId ? self::STATION_B : self::STATION_A;

        return [
            ProjectContainerReading::known('c-'.$locationId, 'Ishtar '.$locationId, $locationId, $contents, null),
            ProjectContainerReading::known('c-'.$other, 'Ishtar '.$other, $other, [], null),
        ];
    }

    private function withMatch(IndustryProjectBomLine $line, string $status, int $runs): void
    {
        $line->addJobMatch(
            (new IndustryProjectJobMatch())
                ->setEsiJobId(++$this->nextEsiJobId)
                ->setStatus($status)
                ->setRuns($runs),
        );
    }

    private function entryOf(array $entries, int $typeId): TypeStationQuantities
    {
        foreach ($entries as $entry) {
            if ($entry->typeId === $typeId) {
                return $entry;
            }
        }

        self::fail(\sprintf('No entry for type %d.', $typeId));
    }

    private function rowsOf(TypeStationQuantities $entry): array
    {
        self::assertNotNull($entry->stations);

        return array_map(
            static fn (StationQuantity $station): array => [
                $station->locationId,
                $station->needed,
                $station->consumed,
                $station->inProduction,
                $station->onSite,
                $station->out,
                $station->toBring,
                $station->inTransit,
                $station->unknownReason,
            ],
            $entry->stations,
        );
    }

    private function rowAt(TypeStationQuantities $entry, int $locationId): array
    {
        foreach ($this->rowsOf($entry) as $row) {
            if ($row[0] === $locationId) {
                return $row;
            }
        }

        self::fail(\sprintf('No station %d in the entry of type %d.', $locationId, $entry->typeId));
    }
}
