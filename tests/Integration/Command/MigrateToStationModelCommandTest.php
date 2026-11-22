<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\MigrateToStationModelCommand;
use App\Enum\ProjectStatus;
use App\Service\Industry\Project\StationMigration\StationMigrationLog;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Service\Industry\Project\StationMigration\SeedsStationMigration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(MigrateToStationModelCommand::class)]
#[Group('issue-1320')]
final class MigrateToStationModelCommandTest extends IntegrationTestCase
{
    use SeedsStationMigration;

    private const string COMMAND_NAME = 'app:industry:migrate-to-station-model';

    private string $logDirectory;
    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStationMigrationWorld();
        $this->seedProjectsOfEveryStatus();
        $this->logDirectory = sys_get_temp_dir() . '/station-migration-command-' . bin2hex(random_bytes(4));
        mkdir($this->logDirectory);
        $this->logPath = $this->logDirectory . '/migration.json';
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->logDirectory);
        parent::tearDown();
    }

    public function testApplyWithoutLogFailsNamingTheOptionAndWritesNothing(): void
    {
        $stateBefore = $this->databaseState();
        $tester = $this->tester();

        $exitCode = $tester->execute(['--apply' => true]);
        $this->em->clear();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('--log', $tester->getDisplay());
        self::assertSame($stateBefore, $this->databaseState());
    }

    public function testALogWithoutApplyFailsAndCreatesNoFile(): void
    {
        $stateBefore = $this->databaseState();
        $tester = $this->tester();

        $exitCode = $tester->execute(['--log' => $this->logPath]);
        $this->em->clear();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('--apply', $tester->getDisplay());
        self::assertFileDoesNotExist($this->logPath);
        self::assertSame($stateBefore, $this->databaseState());
    }

    public function testDryRunSucceedsAnnouncesItsModeAndWritesNothing(): void
    {
        $stateBefore = $this->databaseState();
        $tester = $this->tester();

        $exitCode = $tester->execute([]);
        $this->em->clear();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('Mode: dry run', $this->firstLineOf($tester));
        self::assertStringContainsString('Changes: 5', $tester->getDisplay());
        self::assertSame($stateBefore, $this->databaseState());
    }

    public function testApplyWithALogSucceedsWritesTheChangesAndTheLog(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute(['--apply' => true, '--log' => $this->logPath]);
        $this->em->clear();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('Mode: applied', $this->firstLineOf($tester));
        self::assertSame(self::STATION_A, $this->reloadedContainer($this->containerIds['ishtar'])->getLocationId());
        self::assertSame(['components' => $this->workStationOf($this->sotiyoBId)], $this->reloadedProject($this->projectIds['sotiyo'])->getWorkStations());
        self::assertCount(5, (new StationMigrationLog())->read($this->logPath));
    }

    public function testTheOutputHasOneBlockPerProjectWithItsChangesAndItsUnmigratedElements(): void
    {
        $tester = $this->tester();

        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Project Ishtar fleet (in_progress)', $display);
        self::assertStringContainsString('Project Sotiyo run (published)', $display);
        self::assertStringContainsString('Project Delivered fleet (completed)', $display);
        self::assertStringContainsString('Project Draft idea (draft)', $display);
        self::assertStringContainsString('  change work_station components -> ' . $this->azbelAId, $display);
        self::assertStringContainsString('  change container_location ' . $this->containerIds['ishtar'] . ' -> ' . self::STATION_A, $display);
        self::assertStringContainsString('  change work_station components -> ' . $this->sotiyoBId, $display);
        self::assertStringContainsString('  not migrated work_station reaction: lines_without_station', $display);
        self::assertStringContainsString('  not migrated container_location ' . $this->containerIds['completed'] . ': project_completed', $display);
        self::assertLessThan(
            (int) strpos($display, 'Project Sotiyo run (published)'),
            (int) strpos($display, 'Project Ishtar fleet (in_progress)'),
        );
    }

    public function testTheOutputEndsWithTheTotalsAndTheCountOfUnmigratedElementsByReason(): void
    {
        $tester = $this->tester();

        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString("Projects: 4\n", $display);
        self::assertStringContainsString("Changes: 5\n", $display);
        self::assertStringContainsString("Not migrated: 2\n", $display);
        self::assertStringContainsString("  lines_without_station: 1\n", $display);
        self::assertStringContainsString("  project_completed: 1\n", $display);
        self::assertLessThan(
            (int) strpos($display, 'Projects: 4'),
            (int) strpos($display, 'Project Draft idea (draft)'),
        );
    }

    public function testAProjectWithoutNameIsShownByItsId(): void
    {
        $unnamed = $this->newProject(null, ProjectStatus::Draft);
        $this->flushAndClear();
        $tester = $this->tester();

        $tester->execute(['--project' => $unnamed->getId()->toRfc4122()]);

        self::assertStringContainsString('Project ' . $unnamed->getId()->toRfc4122() . ' (draft)', $tester->getDisplay());
    }

    public function testTheProjectOptionMigratesOnlyThatProject(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute(['--apply' => true, '--log' => $this->logPath, '--project' => $this->projectIds['sotiyo']]);
        $this->em->clear();

        $display = $tester->getDisplay();
        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString("Projects: 1\n", $display);
        self::assertStringContainsString("Changes: 2\n", $display);
        self::assertStringNotContainsString('Project Ishtar fleet', $display);
        self::assertSame([], $this->reloadedProject($this->projectIds['ishtar'])->getWorkStations());
        self::assertSame(self::STATION_B, $this->reloadedContainer($this->containerIds['sotiyo'])->getLocationId());
    }

    public function testAnUnmigratedElementWithADetailShowsItBetweenParentheses(): void
    {
        $lonelyOwner = $this->createCorporationMember('Lonely Member', 98_132_002, false);
        $project = $this->newProject('No director', ProjectStatus::InProgress, $lonelyOwner);
        $containerId = $this->addContainer($project, 'Lonely Box');
        $this->flushAndClear();
        $tester = $this->tester();

        $tester->execute(['--project' => $project->getId()->toRfc4122()]);

        self::assertStringContainsString('  not migrated container_location ' . $containerId . ': assets_unavailable (no_director)', $tester->getDisplay());
    }

    public function testASecondApplyReportsNoChange(): void
    {
        $this->tester()->execute(['--apply' => true, '--log' => $this->logPath]);
        $this->em->clear();
        $stateAfterFirstApply = $this->databaseState();
        $tester = $this->tester();

        $exitCode = $tester->execute(['--apply' => true, '--log' => $this->logDirectory . '/second.json']);
        $this->em->clear();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString("Changes: 0\n", $tester->getDisplay());
        self::assertSame($stateAfterFirstApply, $this->databaseState());
    }

    public function testAnInvalidOrUnknownProjectOptionFailsTheCommandAndWritesNothing(): void
    {
        $stateBefore = $this->databaseState();

        foreach (['not-a-uuid', '0191b000-0000-7000-8000-00000000ffff'] as $projectId) {
            $tester = $this->tester();
            $exitCode = $tester->execute(['--apply' => true, '--log' => $this->logPath, '--project' => $projectId]);
            $this->em->clear();

            self::assertSame(Command::FAILURE, $exitCode);
        }

        self::assertSame($stateBefore, $this->databaseState());
        self::assertFileDoesNotExist($this->logPath);
    }

    public function testAFailedLogWriteFailsTheCommandAndAppliesNothing(): void
    {
        $stateBefore = $this->databaseState();
        $tester = $this->tester();

        $exitCode = $tester->execute(['--apply' => true, '--log' => $this->logDirectory . '/missing/migration.json']);
        $this->em->clear();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertNotSame('', trim($tester->getDisplay()));
        self::assertSame($stateBefore, $this->databaseState());
    }

    public function testRevertWithoutApplyIsADryRunReportingWhatItWouldUndo(): void
    {
        $this->tester()->execute(['--apply' => true, '--log' => $this->logPath]);
        $this->em->clear();
        $stateAfterApply = $this->databaseState();
        $tester = $this->tester();

        $exitCode = $tester->execute(['--revert' => $this->logPath]);
        $this->em->clear();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('Mode: revert dry run', $this->firstLineOf($tester));
        self::assertStringContainsString("Reverted: 5\n", $tester->getDisplay());
        self::assertStringContainsString("Changed since: 0\n", $tester->getDisplay());
        self::assertSame($stateAfterApply, $this->databaseState());
    }

    public function testRevertWithApplyRestoresTheOriginalStateWithoutNeedingALogOption(): void
    {
        $stateBefore = $this->databaseState();
        $this->tester()->execute(['--apply' => true, '--log' => $this->logPath]);
        $this->em->clear();
        $tester = $this->tester();

        $exitCode = $tester->execute(['--revert' => $this->logPath, '--apply' => true]);
        $this->em->clear();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('Mode: reverted', $this->firstLineOf($tester));
        self::assertStringContainsString("Reverted: 5\n", $tester->getDisplay());
        self::assertSame($stateBefore, $this->databaseState());
    }

    public function testRevertListsTheEntriesChangedSinceTheMigration(): void
    {
        $this->tester()->execute(['--apply' => true, '--log' => $this->logPath]);
        $this->em->clear();
        $this->reloadedContainer($this->containerIds['ishtar'])->locateAt(self::STATION_C);
        $this->flushAndClear();
        $tester = $this->tester();

        $exitCode = $tester->execute(['--revert' => $this->logPath, '--apply' => true]);
        $this->em->clear();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString("Reverted: 4\n", $tester->getDisplay());
        self::assertStringContainsString("Changed since: 1\n", $tester->getDisplay());
        self::assertStringContainsString('  container_location ' . $this->containerIds['ishtar'], $tester->getDisplay());
        self::assertSame(self::STATION_C, $this->reloadedContainer($this->containerIds['ishtar'])->getLocationId());
    }

    public function testRevertOfAnInvalidLogFailsAndWritesNothing(): void
    {
        $this->tester()->execute(['--apply' => true, '--log' => $this->logPath]);
        $this->em->clear();
        file_put_contents($this->logPath, 'not json');
        $stateAfterApply = $this->databaseState();
        $tester = $this->tester();

        $exitCode = $tester->execute(['--revert' => $this->logPath, '--apply' => true]);
        $this->em->clear();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertNotSame('', trim($tester->getDisplay()));
        self::assertSame($stateAfterApply, $this->databaseState());
    }

    public function testRevertOfAMissingLogFails(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute(['--revert' => $this->logDirectory . '/absent.json']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertNotSame('', trim($tester->getDisplay()));
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find(self::COMMAND_NAME));
    }

    private function firstLineOf(CommandTester $tester): string
    {
        return trim(explode("\n", $tester->getDisplay())[0]);
    }
}
