<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Industry\Project\StationMigration;

use App\Exception\InvalidStationMigrationLogException;
use App\Service\Industry\Project\StationMigration\ProjectStationMigrationReport;
use App\Service\Industry\Project\StationMigration\StationMigration;
use App\Service\Industry\Project\StationMigration\StationMigrationChange;
use App\Service\Industry\Project\StationMigration\StationMigrationChangeKind;
use App\Service\Industry\Project\StationMigration\StationMigrationLog;
use App\Service\Industry\Project\StationMigration\StationMigrationReason;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(StationMigration::class)]
#[Group('issue-1320')]
final class StationMigrationTest extends IntegrationTestCase
{
    use SeedsStationMigration;

    private StationMigration $migration;
    private string $logDirectory;
    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStationMigrationWorld();
        $this->migration = self::getContainer()->get(StationMigration::class);
        $this->logDirectory = sys_get_temp_dir() . '/station-migration-run-' . bin2hex(random_bytes(4));
        mkdir($this->logDirectory);
        $this->logPath = $this->logDirectory . '/migration.json';
        $this->seedProjectsOfEveryStatus();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->logDirectory);
        parent::tearDown();
    }

    public function testDryRunReadsEveryProjectOfEveryStatusOldestFirst(): void
    {
        $report = $this->migration->run(false, null, null);

        self::assertSame(
            [$this->projectIds['draft'], $this->projectIds['ishtar'], $this->projectIds['sotiyo'], $this->projectIds['completed']],
            array_map(static fn (ProjectStationMigrationReport $project): string => $project->projectId, $report->projects),
        );
        self::assertSame(
            ['Draft idea', 'Ishtar fleet', 'Sotiyo run', 'Delivered fleet'],
            array_map(static fn (ProjectStationMigrationReport $project): ?string => $project->projectName, $report->projects),
        );
        self::assertSame(
            ['draft', 'in_progress', 'published', 'completed'],
            array_map(static fn (ProjectStationMigrationReport $project): string => $project->status, $report->projects),
        );
    }

    public function testDryRunReportsTheChangesAndTheUnmigratedElementsOfEachProject(): void
    {
        $report = $this->migration->run(false, null, null);

        self::assertEquals(
            [
                new StationMigrationChange($this->projectIds['ishtar'], StationMigrationChangeKind::WorkStation, 'components', $this->azbelAId),
                new StationMigrationChange($this->projectIds['ishtar'], StationMigrationChangeKind::ContainerLocation, $this->containerIds['ishtar'], (string) self::STATION_A),
                new StationMigrationChange($this->projectIds['sotiyo'], StationMigrationChangeKind::WorkStation, 'components', $this->sotiyoBId),
                new StationMigrationChange($this->projectIds['sotiyo'], StationMigrationChangeKind::ContainerLocation, $this->containerIds['sotiyo'], (string) self::STATION_B),
                new StationMigrationChange($this->projectIds['completed'], StationMigrationChangeKind::WorkStation, 'components', $this->azbelAId),
            ],
            $report->changes(),
        );
        self::assertEquals(['lines_without_station' => 1, 'project_completed' => 1], $report->unmigratedCountsByReason());
    }

    public function testDryRunLeavesTheDatabaseUnchanged(): void
    {
        $stateBefore = $this->databaseState();

        $report = $this->migration->run(false, null, null);
        $this->em->clear();

        self::assertCount(5, $report->changes());
        self::assertSame($stateBefore, $this->databaseState());
    }

    public function testDryRunAndApplyGiveTheSameReport(): void
    {
        $dryRun = $this->migration->run(false, null, null);
        $applied = $this->migration->run(true, null, $this->logPath);

        self::assertCount(5, $applied->changes());
        self::assertEquals($dryRun, $applied);
    }

    public function testApplyWritesThePlanStationsAndTheContainerLocationsAndTouchesNoBomLine(): void
    {
        $stateBefore = $this->databaseState();

        $this->migration->run(true, null, $this->logPath);
        $this->em->clear();

        $stateAfter = $this->databaseState();
        self::assertSame($stateBefore['bomLines'], $stateAfter['bomLines']);
        self::assertSame(
            ['components' => $this->workStationOf($this->azbelAId)],
            $this->reloadedProject($this->projectIds['ishtar'])->getWorkStations(),
        );
        self::assertSame(
            ['components' => $this->workStationOf($this->sotiyoBId)],
            $this->reloadedProject($this->projectIds['sotiyo'])->getWorkStations(),
        );
        self::assertSame([], $this->reloadedProject($this->projectIds['draft'])->getWorkStations());
        self::assertSame(self::STATION_A, $this->reloadedContainer($this->containerIds['ishtar'])->getLocationId());
        self::assertSame(self::STATION_B, $this->reloadedContainer($this->containerIds['sotiyo'])->getLocationId());
        self::assertNull($this->reloadedContainer($this->containerIds['completed'])->getLocationId());
    }

    public function testApplyWritesTheLogWithEveryChangeAndNoName(): void
    {
        $report = $this->migration->run(true, null, $this->logPath);

        self::assertFileExists($this->logPath);
        self::assertEquals($report->changes(), (new StationMigrationLog())->read($this->logPath));
        $content = (string) file_get_contents($this->logPath);
        foreach (['Ishtar', 'Sotiyo', 'Delivered', 'Draft idea', 'Fuel Depot'] as $name) {
            self::assertStringNotContainsString($name, $content);
        }
    }

    public function testApplyWithoutLogPathIsRefusedAndWritesNothing(): void
    {
        $stateBefore = $this->databaseState();
        $refused = false;

        try {
            $this->migration->run(true, null, null);
        } catch (\LogicException) {
            $refused = true;
            $this->em->clear();
        }

        self::assertTrue($refused);
        self::assertSame($stateBefore, $this->databaseState());
        self::assertSame([], glob($this->logDirectory . '/*') ?: []);
    }

    public function testAFailedLogWriteAppliesNothing(): void
    {
        $stateBefore = $this->databaseState();
        $aborted = false;

        try {
            $this->migration->run(true, null, $this->logDirectory . '/missing/migration.json');
        } catch (\Throwable) {
            $aborted = true;
            $this->em->clear();
        }

        self::assertTrue($aborted);
        self::assertSame($stateBefore, $this->databaseState());
    }

    public function testRunningOnASingleProjectLeavesTheOthersAlone(): void
    {
        $report = $this->migration->run(true, $this->projectIds['sotiyo'], $this->logPath);
        $this->em->clear();

        self::assertSame([$this->projectIds['sotiyo']], array_map(static fn (ProjectStationMigrationReport $project): string => $project->projectId, $report->projects));
        self::assertSame(['components' => $this->workStationOf($this->sotiyoBId)], $this->reloadedProject($this->projectIds['sotiyo'])->getWorkStations());
        self::assertSame([], $this->reloadedProject($this->projectIds['ishtar'])->getWorkStations());
        self::assertNull($this->reloadedContainer($this->containerIds['ishtar'])->getLocationId());
        self::assertSame(self::STATION_B, $this->reloadedContainer($this->containerIds['sotiyo'])->getLocationId());
        self::assertEquals($report->changes(), (new StationMigrationLog())->read($this->logPath));
    }

    public function testASecondApplyChangesNothing(): void
    {
        $first = $this->migration->run(true, null, $this->logPath);
        $this->em->clear();
        $stateAfterFirstApply = $this->databaseState();

        $second = $this->migration->run(true, null, $this->logDirectory . '/second.json');
        $this->em->clear();

        self::assertCount(5, $first->changes());
        self::assertSame([], $second->changes());
        self::assertSame($stateAfterFirstApply, $this->databaseState());
    }

    public function testRevertRestoresTheOriginalStateOfTheDatabase(): void
    {
        $stateBefore = $this->databaseState();
        $applied = $this->migration->run(true, null, $this->logPath);
        $this->em->clear();
        self::assertNotSame($stateBefore, $this->databaseState());

        $reverted = $this->migration->revert($this->logPath, true);
        $this->em->clear();

        self::assertCount(5, $reverted->reverted);
        self::assertSame($stateBefore, $this->databaseState());
        self::assertEqualsCanonicalizing($applied->changes(), $reverted->reverted);
        self::assertSame([], $reverted->changedSince);
    }

    public function testRevertWithoutApplyOnlyReportsWhatItWouldUndo(): void
    {
        $applied = $this->migration->run(true, null, $this->logPath);
        $this->em->clear();
        $stateAfterApply = $this->databaseState();

        $reverted = $this->migration->revert($this->logPath, false);
        $this->em->clear();

        self::assertCount(5, $reverted->reverted);
        self::assertSame($stateAfterApply, $this->databaseState());
        self::assertEqualsCanonicalizing($applied->changes(), $reverted->reverted);
        self::assertSame([], $reverted->changedSince);
    }

    public function testRevertRemovesOnlyTheWorkTypeTheMigrationAdded(): void
    {
        $project = $this->reloadedProject($this->projectIds['ishtar']);
        $project->setWorkStations(['reaction' => $this->workStationOf($this->athanorCId)]);
        $this->flushAndClear();
        $this->migration->run(true, $this->projectIds['ishtar'], $this->logPath);
        $this->em->clear();
        self::assertSame(
            ['reaction' => $this->workStationOf($this->athanorCId), 'components' => $this->workStationOf($this->azbelAId)],
            $this->reloadedProject($this->projectIds['ishtar'])->getWorkStations(),
        );
        $this->em->clear();

        $this->migration->revert($this->logPath, true);
        $this->em->clear();

        self::assertSame(['reaction' => $this->workStationOf($this->athanorCId)], $this->reloadedProject($this->projectIds['ishtar'])->getWorkStations());
    }

    public function testRevertIgnoresAndReportsAPlanStationChangedByHand(): void
    {
        $this->migration->run(true, null, $this->logPath);
        $this->em->clear();
        $handChoice = ['components' => $this->workStationOf($this->azbelAId)];
        $this->reloadedProject($this->projectIds['sotiyo'])->setWorkStations($handChoice);
        $this->flushAndClear();

        $reverted = $this->migration->revert($this->logPath, true);
        $this->em->clear();

        self::assertEquals(
            [new StationMigrationChange($this->projectIds['sotiyo'], StationMigrationChangeKind::WorkStation, 'components', $this->sotiyoBId)],
            $reverted->changedSince,
        );
        self::assertSame($handChoice, $this->reloadedProject($this->projectIds['sotiyo'])->getWorkStations());
        self::assertSame([], $this->reloadedProject($this->projectIds['ishtar'])->getWorkStations());
        self::assertCount(4, $reverted->reverted);
    }

    public function testRevertIgnoresAPlanStationThatNowHasARecommendedStructure(): void
    {
        $this->migration->run(true, $this->projectIds['ishtar'], $this->logPath);
        $this->em->clear();
        $withRecommendation = ['components' => ['structureId' => $this->azbelAId, 'recommendedStructureId' => $this->sotiyoBId]];
        $this->reloadedProject($this->projectIds['ishtar'])->setWorkStations($withRecommendation);
        $this->flushAndClear();

        $reverted = $this->migration->revert($this->logPath, true);
        $this->em->clear();

        self::assertEquals(
            [new StationMigrationChange($this->projectIds['ishtar'], StationMigrationChangeKind::WorkStation, 'components', $this->azbelAId)],
            $reverted->changedSince,
        );
        self::assertSame($withRecommendation, $this->reloadedProject($this->projectIds['ishtar'])->getWorkStations());
    }

    public function testRevertIgnoresAndReportsAContainerLocationChangedByHand(): void
    {
        $this->migration->run(true, null, $this->logPath);
        $this->em->clear();
        $this->reloadedContainer($this->containerIds['ishtar'])->locateAt(self::STATION_C);
        $this->flushAndClear();

        $reverted = $this->migration->revert($this->logPath, true);
        $this->em->clear();

        self::assertEquals(
            [new StationMigrationChange($this->projectIds['ishtar'], StationMigrationChangeKind::ContainerLocation, $this->containerIds['ishtar'], (string) self::STATION_A)],
            $reverted->changedSince,
        );
        self::assertSame(self::STATION_C, $this->reloadedContainer($this->containerIds['ishtar'])->getLocationId());
        self::assertNull($this->reloadedContainer($this->containerIds['sotiyo'])->getLocationId());
        self::assertCount(4, $reverted->reverted);
    }

    public function testRevertReportsTheEntriesOfAProjectThatNoLongerExists(): void
    {
        $this->migration->run(true, null, $this->logPath);
        $this->em->clear();
        $this->em->remove($this->reloadedProject($this->projectIds['sotiyo']));
        $this->flushAndClear();

        $reverted = $this->migration->revert($this->logPath, true);
        $this->em->clear();

        self::assertEqualsCanonicalizing(
            [
                new StationMigrationChange($this->projectIds['sotiyo'], StationMigrationChangeKind::WorkStation, 'components', $this->sotiyoBId),
                new StationMigrationChange($this->projectIds['sotiyo'], StationMigrationChangeKind::ContainerLocation, $this->containerIds['sotiyo'], (string) self::STATION_B),
            ],
            $reverted->changedSince,
        );
        self::assertCount(3, $reverted->reverted);
        self::assertSame([], $this->reloadedProject($this->projectIds['ishtar'])->getWorkStations());
    }

    public function testRevertReportsTheEntryOfAContainerThatNoLongerExists(): void
    {
        $this->migration->run(true, null, $this->logPath);
        $this->em->clear();
        $this->em->remove($this->reloadedContainer($this->containerIds['ishtar']));
        $this->flushAndClear();

        $reverted = $this->migration->revert($this->logPath, true);

        self::assertEquals(
            [new StationMigrationChange($this->projectIds['ishtar'], StationMigrationChangeKind::ContainerLocation, $this->containerIds['ishtar'], (string) self::STATION_A)],
            $reverted->changedSince,
        );
        self::assertCount(4, $reverted->reverted);
    }

    public function testRevertingTwiceUndoesNothingTheSecondTime(): void
    {
        $this->migration->run(true, null, $this->logPath);
        $this->em->clear();
        $this->migration->revert($this->logPath, true);
        $this->em->clear();
        $stateAfterFirstRevert = $this->databaseState();

        $second = $this->migration->revert($this->logPath, true);
        $this->em->clear();

        self::assertSame([], $second->reverted);
        self::assertCount(5, $second->changedSince);
        self::assertSame($stateAfterFirstRevert, $this->databaseState());
    }

    public function testRevertDoesNotTouchAContainerOfAnotherProjectNamedByAHandEditedLog(): void
    {
        $this->migration->run(true, null, $this->logPath);
        $this->em->clear();
        $editedLogPath = $this->logDirectory . '/edited.json';
        (new StationMigrationLog())->write($editedLogPath, [
            new StationMigrationChange($this->projectIds['sotiyo'], StationMigrationChangeKind::ContainerLocation, $this->containerIds['ishtar'], (string) self::STATION_A),
        ], new \DateTimeImmutable());

        $reverted = $this->migration->revert($editedLogPath, true);
        $this->em->clear();

        self::assertSame([], $reverted->reverted);
        self::assertCount(1, $reverted->changedSince);
        self::assertSame(self::STATION_A, $this->reloadedContainer($this->containerIds['ishtar'])->getLocationId());
    }

    public function testRevertReportsEntriesWhoseIdsAreNotUuidsWithoutFailing(): void
    {
        (new StationMigrationLog())->write($this->logPath, [
            new StationMigrationChange('not-a-uuid', StationMigrationChangeKind::WorkStation, 'components', $this->azbelAId),
            new StationMigrationChange($this->projectIds['ishtar'], StationMigrationChangeKind::ContainerLocation, 'not-a-uuid', (string) self::STATION_A),
        ], new \DateTimeImmutable());

        $reverted = $this->migration->revert($this->logPath, true);

        self::assertSame([], $reverted->reverted);
        self::assertCount(2, $reverted->changedSince);
    }

    public function testApplyRefusesToOverwriteAnExistingLogAndAppliesNothing(): void
    {
        file_put_contents($this->logPath, 'previous log');
        $stateBefore = $this->databaseState();
        $refused = false;

        try {
            $this->migration->run(true, null, $this->logPath);
        } catch (\Throwable) {
            $refused = true;
            $this->em->clear();
        }

        self::assertTrue($refused);
        self::assertSame('previous log', file_get_contents($this->logPath));
        self::assertSame($stateBefore, $this->databaseState());
    }

    public function testAnInvalidOrUnknownProjectIdIsRefusedAndAppliesNothing(): void
    {
        $stateBefore = $this->databaseState();

        foreach (['not-a-uuid', '0191b000-0000-7000-8000-00000000ffff'] as $projectId) {
            try {
                $this->migration->run(true, $projectId, $this->logPath);
                self::fail('The project id must be refused.');
            } catch (\InvalidArgumentException) {
                $this->em->clear();
            }
        }

        self::assertSame($stateBefore, $this->databaseState());
        self::assertFileDoesNotExist($this->logPath);
    }

    public function testRevertOfAnInvalidLogFailsAndWritesNothing(): void
    {
        $this->migration->run(true, null, $this->logPath);
        $this->em->clear();
        file_put_contents($this->logPath, '{"appliedAt": "2026-11-22T10:30:00+00:00", "changes": "none"}');
        $stateAfterApply = $this->databaseState();
        $refused = false;

        try {
            $this->migration->revert($this->logPath, true);
        } catch (InvalidStationMigrationLogException) {
            $refused = true;
            $this->em->clear();
        }

        self::assertTrue($refused);
        self::assertSame($stateAfterApply, $this->databaseState());
    }

    public function testRevertOfAMissingLogFails(): void
    {
        $this->expectException(InvalidStationMigrationLogException::class);

        $this->migration->revert($this->logDirectory . '/absent.json', true);
    }
}
