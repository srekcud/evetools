<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\StationMigration;

use App\Service\Industry\Project\StationMigration\ProjectStationMigrationReport;
use App\Service\Industry\Project\StationMigration\StationMigrationChange;
use App\Service\Industry\Project\StationMigration\StationMigrationChangeKind;
use App\Service\Industry\Project\StationMigration\StationMigrationReason;
use App\Service\Industry\Project\StationMigration\StationMigrationReport;
use App\Service\Industry\Project\StationMigration\StationMigrationUnmigrated;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StationMigrationReport::class)]
#[Group('issue-1320')]
final class StationMigrationReportTest extends TestCase
{
    private const string FIRST_PROJECT_ID = '0191c000-0000-7000-8000-000000000001';
    private const string SECOND_PROJECT_ID = '0191c000-0000-7000-8000-000000000002';
    private const string THIRD_PROJECT_ID = '0191c000-0000-7000-8000-000000000003';
    private const string STRUCTURE_ID = '0191c000-0000-7000-8000-0000000000a1';
    private const string CONTAINER_ID = '0191c000-0000-7000-8000-0000000000c1';

    public function testChangesAreFlattenedAcrossProjectsInProjectOrder(): void
    {
        $workStation = new StationMigrationChange(self::FIRST_PROJECT_ID, StationMigrationChangeKind::WorkStation, 'components', self::STRUCTURE_ID);
        $containerLocation = new StationMigrationChange(self::FIRST_PROJECT_ID, StationMigrationChangeKind::ContainerLocation, self::CONTAINER_ID, '60003760');
        $otherProjectWorkStation = new StationMigrationChange(self::THIRD_PROJECT_ID, StationMigrationChangeKind::WorkStation, 'reaction', self::STRUCTURE_ID);
        $report = new StationMigrationReport([
            $this->projectReport(self::FIRST_PROJECT_ID, [$workStation, $containerLocation], []),
            $this->projectReport(self::SECOND_PROJECT_ID, [], []),
            $this->projectReport(self::THIRD_PROJECT_ID, [$otherProjectWorkStation], []),
        ]);

        self::assertSame([$workStation, $containerLocation, $otherProjectWorkStation], $report->changes());
    }

    public function testChangesAreEmptyWithoutProject(): void
    {
        self::assertSame([], (new StationMigrationReport([]))->changes());
    }

    public function testUnmigratedCountsAreGroupedByReasonValueAcrossProjects(): void
    {
        $report = new StationMigrationReport([
            $this->projectReport(self::FIRST_PROJECT_ID, [], [
                $this->unmigrated(self::FIRST_PROJECT_ID, StationMigrationChangeKind::WorkStation, 'components', StationMigrationReason::SeveralStations),
                $this->unmigrated(self::FIRST_PROJECT_ID, StationMigrationChangeKind::WorkStation, 'hulls', StationMigrationReason::SeveralStations),
                $this->unmigrated(self::FIRST_PROJECT_ID, StationMigrationChangeKind::ContainerLocation, self::CONTAINER_ID, StationMigrationReason::ContainerNotFound),
            ]),
            $this->projectReport(self::SECOND_PROJECT_ID, [], [
                $this->unmigrated(self::SECOND_PROJECT_ID, StationMigrationChangeKind::WorkStation, 'reaction', StationMigrationReason::SeveralStations),
                $this->unmigrated(self::SECOND_PROJECT_ID, StationMigrationChangeKind::ContainerLocation, self::CONTAINER_ID, StationMigrationReason::AssetsUnavailable, 'no_director'),
            ]),
        ]);

        self::assertSame(
            ['several_stations' => 3, 'container_not_found' => 1, 'assets_unavailable' => 1],
            $report->unmigratedCountsByReason(),
        );
    }

    public function testUnmigratedCountsAreEmptyWhenEverythingMigrated(): void
    {
        $report = new StationMigrationReport([
            $this->projectReport(self::FIRST_PROJECT_ID, [new StationMigrationChange(self::FIRST_PROJECT_ID, StationMigrationChangeKind::WorkStation, 'components', self::STRUCTURE_ID)], []),
        ]);

        self::assertSame([], $report->unmigratedCountsByReason());
    }

    private function projectReport(string $projectId, array $changes, array $unmigrated): ProjectStationMigrationReport
    {
        return new ProjectStationMigrationReport($projectId, 'Project ' . substr($projectId, -1), 'in_progress', $changes, $unmigrated);
    }

    private function unmigrated(string $projectId, StationMigrationChangeKind $kind, string $subject, StationMigrationReason $reason, ?string $detail = null): StationMigrationUnmigrated
    {
        return new StationMigrationUnmigrated($projectId, $kind, $subject, $reason, $detail);
    }
}
