<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Industry\Project\StationMigration;

use App\Entity\IndustryProject;
use App\Entity\PersonalStockContainer;
use App\Enum\ProjectStatus;
use App\Service\Industry\Project\StationMigration\ProjectStationMigration;
use App\Service\Industry\Project\StationMigration\ProjectStationMigrationReport;
use App\Service\Industry\Project\StationMigration\StationMigrationChange;
use App\Service\Industry\Project\StationMigration\StationMigrationChangeKind;
use App\Service\Industry\Project\StationMigration\StationMigrationReason;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(ProjectStationMigration::class)]
#[Group('issue-1320')]
final class ProjectStationMigrationTest extends IntegrationTestCase
{
    use SeedsStationMigration;

    private ProjectStationMigration $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStationMigrationWorld();
        $this->migration = self::getContainer()->get(ProjectStationMigration::class);
    }

    public function testComponentLinesAllPlannedInAStationThatCanManufactureSetThePlanStationOfComponents(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId, $this->azbelAId, $this->azbelAId);
        $this->flushAndClear();
        $project = $this->reloadedProject($project);

        $report = $this->migration->migrate($project);

        self::assertSame(['components' => $this->workStationOf($this->azbelAId)], $project->getWorkStations());
        self::assertEquals(
            [new StationMigrationChange($project->getId()->toRfc4122(), StationMigrationChangeKind::WorkStation, 'components', $this->azbelAId)],
            $report->changes,
        );
        self::assertSame([], $report->unmigrated);
    }

    public function testTheReportCarriesTheProjectIdNameAndStatus(): void
    {
        $project = $this->newProject('Ishtar fleet', ProjectStatus::Selling);
        $this->flushAndClear();
        $project = $this->reloadedProject($project);

        $report = $this->migration->migrate($project);

        self::assertSame([$project->getId()->toRfc4122(), 'Ishtar fleet', 'selling'], [$report->projectId, $report->projectName, $report->status]);
    }

    public function testAProjectWithoutNameIsReportedWithANullName(): void
    {
        $project = $this->newProject(null, ProjectStatus::Draft);
        $this->flushAndClear();

        $report = $this->migration->migrate($this->reloadedProject($project));

        self::assertNull($report->projectName);
        self::assertSame('draft', $report->status);
    }

    public function testMaterialLinesDoNotCountAsJobLinesOfAWorkType(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId);
        $this->addMaterialLine($project, 34, 5_000);
        $this->flushAndClear();
        $project = $this->reloadedProject($project);

        $report = $this->migration->migrate($project);

        self::assertSame(['components' => $this->workStationOf($this->azbelAId)], $project->getWorkStations());
        self::assertSame([], $report->unmigrated);
    }

    public function testEveryWorkTypeIsClassifiedLikeTheWorkStationsOfTheFlow(): void
    {
        $project = $this->newProject();
        $this->addJobLine($project, self::COMPONENT_TYPE_ID, 'blueprint', 'copy', $this->sotiyoBId);
        $this->addJobLine($project, 16_634, 'component', 'reaction', $this->athanorCId);
        $this->addJobLine($project, self::COMPONENT_TYPE_ID, 'component', 'manufacturing', $this->azbelAId);
        $this->addJobLine($project, self::HULL_TYPE_ID, 'component', 'manufacturing', $this->sotiyoBId);
        $this->addJobLine($project, self::FINAL_PRODUCT_TYPE_ID, 'final', 'manufacturing', $this->azbelAId);
        $this->addTargetProduct($project, self::FINAL_PRODUCT_TYPE_ID);
        $this->flushAndClear();
        $project = $this->reloadedProject($project);

        $report = $this->migration->migrate($project);

        self::assertEquals(
            [
                'blueprints' => $this->workStationOf($this->sotiyoBId),
                'reaction' => $this->workStationOf($this->athanorCId),
                'components' => $this->workStationOf($this->azbelAId),
                'hulls' => $this->workStationOf($this->sotiyoBId),
                'final_products' => $this->workStationOf($this->azbelAId),
            ],
            $project->getWorkStations(),
        );
        self::assertCount(5, $report->changes);
        self::assertSame([], $report->unmigrated);
    }

    public function testComponentLinesPlannedInTwoStationsSetNothingAndAreReportedAsSeveralStations(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId, $this->sotiyoBId);
        $this->flushAndClear();
        $project = $this->reloadedProject($project);

        $report = $this->migration->migrate($project);

        self::assertSame([], $project->getWorkStations());
        self::assertSame([], $report->changes);
        self::assertSame([[StationMigrationChangeKind::WorkStation, 'components', StationMigrationReason::SeveralStations]], $this->unmigratedOf($report));
    }

    public function testALineWhoseTypeIsMissingFromTheSdeIsReportedAndTheOtherWorkTypesStillMigrate(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId);
        $this->addJobLine($project, 99_999, 'component', 'manufacturing', $this->azbelAId);
        $this->addJobLine($project, 16_634, 'component', 'reaction', $this->athanorCId);
        $this->flushAndClear();
        $project = $this->reloadedProject($project);

        $report = $this->migration->migrate($project);

        self::assertSame(
            ['components' => $this->workStationOf($this->azbelAId), 'reaction' => $this->workStationOf($this->athanorCId)],
            $project->getWorkStations(),
        );
        self::assertSame([[StationMigrationChangeKind::WorkStation, 'type_99999', StationMigrationReason::TypeMissingFromSde]], $this->unmigratedOf($report));
    }

    public function testAReactionLineWithoutPlannedStructureIsReportedAsLinesWithoutStation(): void
    {
        $project = $this->newProject();
        $this->addJobLine($project, 16_634, 'component', 'reaction', $this->athanorCId);
        $this->addJobLine($project, 16_635, 'component', 'reaction', null);
        $this->flushAndClear();
        $project = $this->reloadedProject($project);

        $report = $this->migration->migrate($project);

        self::assertSame([], $project->getWorkStations());
        self::assertSame([[StationMigrationChangeKind::WorkStation, 'reaction', StationMigrationReason::LinesWithoutStation]], $this->unmigratedOf($report));
    }

    public function testHullLinesPlannedInADeletedConfigurationAreReportedAsStructureUnknown(): void
    {
        $project = $this->newProject();
        $this->addJobLine($project, self::HULL_TYPE_ID, 'component', 'manufacturing', $this->deletedAzbelId);
        $this->flushAndClear();
        $project = $this->reloadedProject($project);

        $report = $this->migration->migrate($project);

        self::assertSame([], $project->getWorkStations());
        self::assertSame([[StationMigrationChangeKind::WorkStation, 'hulls', StationMigrationReason::StructureUnknown]], $this->unmigratedOf($report));
    }

    public function testLinesPlannedInAStructureOfAnotherUserAreReportedAsStructureUnknown(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->foreignAzbelId);
        $this->flushAndClear();
        $project = $this->reloadedProject($project);

        $report = $this->migration->migrate($project);

        self::assertSame([], $project->getWorkStations());
        self::assertSame([[StationMigrationChangeKind::WorkStation, 'components', StationMigrationReason::StructureUnknown]], $this->unmigratedOf($report));
    }

    public function testLinesPlannedWithAValueThatIsNotAConfigurationUuidAreReportedAsStructureUnknown(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, 'legacy-structure-17');
        $this->flushAndClear();
        $project = $this->reloadedProject($project);

        $report = $this->migration->migrate($project);

        self::assertSame([[StationMigrationChangeKind::WorkStation, 'components', StationMigrationReason::StructureUnknown]], $this->unmigratedOf($report));
    }

    public function testReactionLinesPlannedInAnAzbelAreReportedAsStructureCannotRun(): void
    {
        $project = $this->newProject();
        $this->addJobLine($project, 16_634, 'component', 'reaction', $this->azbelAId);
        $this->flushAndClear();
        $project = $this->reloadedProject($project);

        $report = $this->migration->migrate($project);

        self::assertSame([], $project->getWorkStations());
        self::assertSame([[StationMigrationChangeKind::WorkStation, 'reaction', StationMigrationReason::StructureCannotRun]], $this->unmigratedOf($report));
    }

    public function testAWorkTypeAlreadyInThePlanIsNeitherChangedNorReported(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId);
        $project->setWorkStations(['components' => $this->workStationOf($this->sotiyoBId)]);
        $this->flushAndClear();
        $project = $this->reloadedProject($project);

        $report = $this->migration->migrate($project);

        self::assertSame(['components' => $this->workStationOf($this->sotiyoBId)], $project->getWorkStations());
        self::assertSame([], $report->changes);
        self::assertSame([], $report->unmigrated);
    }

    public function testAnExistingWorkTypeKeepsItsRecommendedStructureWhileAnAbsentTypeIsAdded(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId);
        $this->addJobLine($project, 16_634, 'component', 'reaction', $this->athanorCId);
        $project->setWorkStations(['components' => ['structureId' => $this->sotiyoBId, 'recommendedStructureId' => $this->azbelAId]]);
        $this->flushAndClear();
        $project = $this->reloadedProject($project);

        $report = $this->migration->migrate($project);

        self::assertSame(
            [
                'components' => ['structureId' => $this->sotiyoBId, 'recommendedStructureId' => $this->azbelAId],
                'reaction' => $this->workStationOf($this->athanorCId),
            ],
            $project->getWorkStations(),
        );
        self::assertEquals(
            [new StationMigrationChange($project->getId()->toRfc4122(), StationMigrationChangeKind::WorkStation, 'reaction', $this->athanorCId)],
            $report->changes,
        );
    }

    public function testMigratingAMigratedProjectAgainChangesNothing(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId, $this->azbelAId);
        $containerId = $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_A, self::FIRST_CONTAINER_NAME);
        $this->flushAndClear();
        $first = $this->migration->migrate($this->reloadedProject($project));
        $this->flushAndClear();
        $stateAfterFirstPass = $this->databaseState();

        $second = $this->migration->migrate($this->reloadedProject($project));
        $this->flushAndClear();

        self::assertCount(2, $first->changes);
        self::assertSame([], $second->changes);
        self::assertSame([], $second->unmigrated);
        self::assertSame($stateAfterFirstPass, $this->databaseState());
        self::assertSame(self::STATION_A, $this->reloadedContainer($containerId)->getLocationId());
    }

    public function testMigrateDoesNotFlushAndLeavesTheBomLinesAndQuantitiesAsTheyWere(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId, $this->azbelAId);
        $this->addMaterialLine($project, 34, 7_500);
        $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_A, self::FIRST_CONTAINER_NAME);
        $this->flushAndClear();
        $stateBefore = $this->databaseState();
        $project = $this->reloadedProject($project);

        $this->migration->migrate($project);

        self::assertSame($stateBefore, $this->databaseState());
        $this->em->flush();
        $stateAfter = $this->databaseState();
        self::assertSame($stateBefore['bomLines'], $stateAfter['bomLines']);
        self::assertNotSame($stateBefore['projects'], $stateAfter['projects']);
        self::assertNotSame($stateBefore['containers'], $stateAfter['containers']);
    }

    public function testAContainerWithASingleNamedAssetInAStationOfThePlanIsLocatedThere(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId);
        $containerId = $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_A, self::FIRST_CONTAINER_NAME);
        $this->flushAndClear();
        $project = $this->reloadedProject($project);

        $report = $this->migration->migrate($project);

        self::assertSame(self::STATION_A, $this->reloadedContainer($containerId)->getLocationId());
        self::assertEquals(
            [
                new StationMigrationChange($project->getId()->toRfc4122(), StationMigrationChangeKind::WorkStation, 'components', $this->azbelAId),
                new StationMigrationChange($project->getId()->toRfc4122(), StationMigrationChangeKind::ContainerLocation, $containerId, (string) self::STATION_A),
            ],
            $report->changes,
        );
        self::assertSame([], $report->unmigrated);
    }

    public function testTheContainerIsMatchedByItsNameIgnoringCaseAndEdgeSpaces(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->sotiyoBId);
        $containerId = $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_B, '  ISHTAR ');
        $this->flushAndClear();

        $this->migration->migrate($this->reloadedProject($project));

        self::assertSame(self::STATION_B, $this->reloadedContainer($containerId)->getLocationId());
    }

    public function testAContainerWhoseNamedAssetIsAtAStationOutsideThePlanIsReportedAsStationNotInPlan(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId);
        $containerId = $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_B, self::FIRST_CONTAINER_NAME);
        $this->flushAndClear();

        $report = $this->migration->migrate($this->reloadedProject($project));

        self::assertNull($this->reloadedContainer($containerId)->getLocationId());
        self::assertSame([[StationMigrationChangeKind::ContainerLocation, $containerId, StationMigrationReason::StationNotInPlan]], $this->unmigratedOf($report));
    }

    public function testAContainerNamedInTwoStationsOfThePlanGivesOneLocatedContainerPerStation(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId, $this->sotiyoBId);
        $containerId = $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_A, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_B, self::FIRST_CONTAINER_NAME);
        $this->flushAndClear();

        $report = $this->migration->migrate($this->reloadedProject($project));
        $this->flushAndClear();

        $containers = $this->reloadedProject($project)->getContainers()->toArray();
        $locationsByName = array_map(static fn ($container): array => [$container->getName(), $container->getLocationId()], $containers);
        usort($locationsByName, static fn (array $left, array $right): int => $left[1] <=> $right[1]);
        self::assertSame([[self::FIRST_CONTAINER_NAME, self::STATION_A], [self::FIRST_CONTAINER_NAME, self::STATION_B]], $locationsByName);
        self::assertSame(self::STATION_A, $this->reloadedContainer($containerId)->getLocationId());
        $containerChanges = array_values(array_filter(
            $report->changes,
            static fn (StationMigrationChange $change): bool => StationMigrationChangeKind::ContainerLocation === $change->kind,
        ));
        self::assertSame([(string) self::STATION_A, (string) self::STATION_B], array_map(static fn (StationMigrationChange $change): string => $change->value, $containerChanges));
        self::assertSame($containerId, $containerChanges[0]->subject);
        self::assertNotSame($containerId, $containerChanges[1]->subject);
        self::assertSame([StationMigrationReason::SeveralStations], array_map(static fn ($unmigrated): StationMigrationReason => $unmigrated->reason, $report->unmigrated));
    }

    public function testAContainerNamedInAStationOfThePlanAndInAStationOutsideItIsLocatedAndTheOtherStationReported(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId);
        $containerId = $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_A, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_B, self::FIRST_CONTAINER_NAME);
        $this->flushAndClear();

        $report = $this->migration->migrate($this->reloadedProject($project));

        self::assertSame(self::STATION_A, $this->reloadedContainer($containerId)->getLocationId());
        self::assertSame([[StationMigrationChangeKind::ContainerLocation, $containerId, StationMigrationReason::StationNotInPlan]], $this->unmigratedOf($report));
        self::assertSame((string) self::STATION_B, $report->unmigrated[0]->detail);
    }

    public function testAContainerNamedInTwoStationsOutsideThePlanStaysUnlocatedAndBothStationsAreReported(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->athanorCId);
        $containerId = $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_A, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_B, self::FIRST_CONTAINER_NAME);
        $this->flushAndClear();

        $report = $this->migration->migrate($this->reloadedProject($project));

        self::assertNull($this->reloadedContainer($containerId)->getLocationId());
        self::assertSame(
            [
                [StationMigrationChangeKind::ContainerLocation, $containerId, StationMigrationReason::StationNotInPlan],
                [StationMigrationChangeKind::ContainerLocation, $containerId, StationMigrationReason::StationNotInPlan],
            ],
            array_values(array_filter(
                $this->unmigratedOf($report),
                static fn (array $unmigrated): bool => StationMigrationChangeKind::ContainerLocation === $unmigrated[0],
            )),
        );
    }

    public function testAContainerWhoseNamedAssetHasNoResolvedStationIsReportedAsStationUnresolved(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId);
        $containerId = $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerWithUncachedOffice(self::FIRST_CONTAINER_NAME);
        $this->flushAndClear();

        $report = $this->migration->migrate($this->reloadedProject($project));

        self::assertNull($this->reloadedContainer($containerId)->getLocationId());
        self::assertSame([[StationMigrationChangeKind::ContainerLocation, $containerId, StationMigrationReason::StationUnresolved]], $this->unmigratedOf($report));
    }

    public function testAnUnresolvedAssetIsNotChosenAgainstAKnownStation(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId);
        $containerId = $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_A, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerWithUncachedOffice(self::FIRST_CONTAINER_NAME);
        $this->flushAndClear();

        $report = $this->migration->migrate($this->reloadedProject($project));

        self::assertNull($this->reloadedContainer($containerId)->getLocationId());
        self::assertSame([[StationMigrationChangeKind::ContainerLocation, $containerId, StationMigrationReason::StationUnresolved]], $this->unmigratedOf($report));
    }

    public function testAContainerWithoutNamedAssetIsReportedAsContainerNotFound(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId);
        $containerId = $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_A, 'Another Box');
        $this->flushAndClear();

        $report = $this->migration->migrate($this->reloadedProject($project));

        self::assertNull($this->reloadedContainer($containerId)->getLocationId());
        self::assertSame([[StationMigrationChangeKind::ContainerLocation, $containerId, StationMigrationReason::ContainerNotFound]], $this->unmigratedOf($report));
    }

    public function testAContainerNamedInAHiddenDivisionIsReportedAsContainerNotFound(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId);
        $containerId = $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_A, self::FIRST_CONTAINER_NAME, 'CorpSAG5');
        $this->cacheContainerAt(self::STATION_A, 'Visible Box');
        $this->flushAndClear();

        $report = $this->migration->migrate($this->reloadedProject($project));

        self::assertNull($this->reloadedContainer($containerId)->getLocationId());
        self::assertSame([[StationMigrationChangeKind::ContainerLocation, $containerId, StationMigrationReason::ContainerNotFound]], $this->unmigratedOf($report));
    }

    public function testAStationThatAlreadyHasAnotherContainerIsReportedAsStationAlreadyHasContainer(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId);
        $this->addContainer($project, self::SECOND_CONTAINER_NAME, self::STATION_A);
        $containerId = $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_A, self::FIRST_CONTAINER_NAME);
        $this->flushAndClear();

        $report = $this->migration->migrate($this->reloadedProject($project));

        self::assertNull($this->reloadedContainer($containerId)->getLocationId());
        self::assertSame([[StationMigrationChangeKind::ContainerLocation, $containerId, StationMigrationReason::StationAlreadyHasContainer]], $this->unmigratedOf($report));
    }

    public function testTwoContainersOfTheSameStationLocateOnlyOneAndReportTheOther(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId);
        $firstContainerId = $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $secondContainerId = $this->addContainer($project, self::SECOND_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_A, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_A, self::SECOND_CONTAINER_NAME);
        $this->flushAndClear();

        $report = $this->migration->migrate($this->reloadedProject($project));

        $locations = [
            $this->reloadedContainer($firstContainerId)->getLocationId(),
            $this->reloadedContainer($secondContainerId)->getLocationId(),
        ];
        self::assertContains(self::STATION_A, $locations);
        self::assertContains(null, $locations);
        $containerChanges = array_values(array_filter(
            $report->changes,
            static fn (StationMigrationChange $change): bool => StationMigrationChangeKind::ContainerLocation === $change->kind,
        ));
        self::assertCount(1, $containerChanges);
        self::assertSame(
            [StationMigrationReason::StationAlreadyHasContainer],
            array_map(static fn (array $unmigrated): StationMigrationReason => $unmigrated[2], $this->unmigratedOf($report)),
        );
        self::assertSame(
            [$containerChanges[0]->subject === $firstContainerId ? $secondContainerId : $firstContainerId],
            array_map(static fn (array $unmigrated): string => $unmigrated[1], $this->unmigratedOf($report)),
        );
    }

    public function testAContainerNamedLikeAPersonalStockContainerIsReportedAsNamedLikePersonalStock(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId);
        $containerId = $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_A, self::FIRST_CONTAINER_NAME);
        $this->em->persist(new PersonalStockContainer($this->owner(), 1_132_555_000_001, 'ishtar', 'Division 3', self::STATION_A, 'Test Station'));
        $this->flushAndClear();

        $report = $this->migration->migrate($this->reloadedProject($project));

        self::assertNull($this->reloadedContainer($containerId)->getLocationId());
        self::assertSame([[StationMigrationChangeKind::ContainerLocation, $containerId, StationMigrationReason::NamedLikePersonalStock]], $this->unmigratedOf($report));
    }

    public function testAContainerThatAlreadyHasALocationIsNeitherChangedNorReported(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId);
        $containerId = $this->addContainer($project, self::FIRST_CONTAINER_NAME, self::STATION_B);
        $this->cacheContainerAt(self::STATION_A, self::FIRST_CONTAINER_NAME);
        $this->flushAndClear();

        $report = $this->migration->migrate($this->reloadedProject($project));

        self::assertSame(self::STATION_B, $this->reloadedContainer($containerId)->getLocationId());
        self::assertSame([], $report->unmigrated);
        self::assertNotContains(StationMigrationChangeKind::ContainerLocation, array_map(static fn (StationMigrationChange $change): StationMigrationChangeKind => $change->kind, $report->changes));
    }

    public function testALocatedContainerIsNotReportedEvenWhenTheOwnerAssetsAreUnavailable(): void
    {
        $ownerWithoutCorporation = $this->createUser();
        $project = $this->newProject('Offline owner', ProjectStatus::InProgress, $ownerWithoutCorporation);
        $this->addContainer($project, self::FIRST_CONTAINER_NAME, self::STATION_A);
        $this->flushAndClear();

        $report = $this->migration->migrate($this->reloadedProject($project));

        self::assertSame([], $report->changes);
        self::assertSame([], $report->unmigrated);
    }

    public function testACompletedProjectReportsItsUnlocatedContainersAsProjectCompleted(): void
    {
        $project = $this->newProject('Delivered fleet', ProjectStatus::Completed);
        $this->addComponentLines($project, $this->azbelAId);
        $containerId = $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_A, self::FIRST_CONTAINER_NAME);
        $this->flushAndClear();

        $report = $this->migration->migrate($this->reloadedProject($project));

        self::assertNull($this->reloadedContainer($containerId)->getLocationId());
        self::assertSame([[StationMigrationChangeKind::ContainerLocation, $containerId, StationMigrationReason::ProjectCompleted]], $this->unmigratedOf($report));
    }

    public function testACompletedProjectWithoutCorporationOwnerIsStillReportedAsProjectCompleted(): void
    {
        $project = $this->newProject('Delivered offline', ProjectStatus::Completed, $this->createUser());
        $containerId = $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $this->flushAndClear();

        $report = $this->migration->migrate($this->reloadedProject($project));

        self::assertSame([[StationMigrationChangeKind::ContainerLocation, $containerId, StationMigrationReason::ProjectCompleted]], $this->unmigratedOf($report));
    }

    public function testCorporationAssetsSyncedMoreThan48HoursAgoReportTheContainersAsAssetsUnavailable(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId);
        $containerId = $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_A, self::FIRST_CONTAINER_NAME);
        $this->flushAndClear();
        $this->em->getConnection()->executeStatement("UPDATE cached_assets SET cached_at = NOW() - INTERVAL '3 days' WHERE is_corporation_asset = true");

        $report = $this->migration->migrate($this->reloadedProject($project));

        self::assertNull($this->reloadedContainer($containerId)->getLocationId());
        self::assertCount(1, $report->unmigrated);
        self::assertSame(
            [StationMigrationChangeKind::ContainerLocation, $containerId, StationMigrationReason::AssetsUnavailable, 'corporation_assets_stale'],
            [$report->unmigrated[0]->kind, $report->unmigrated[0]->subject, $report->unmigrated[0]->reason, $report->unmigrated[0]->detail],
        );
    }

    public function testAnAssetWithoutNameNextToTheContainerDoesNotPreventItsLocation(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId);
        $containerId = $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_A, self::FIRST_CONTAINER_NAME);
        $this->persistAsset($this->nextAssetItemId++, 34, 'Tritanium', $this->cacheOfficeAt(self::STATION_A), 'item', 'CorpSAG2', 'Division 2', null);
        $this->flushAndClear();

        $report = $this->migration->migrate($this->reloadedProject($project));

        self::assertSame(self::STATION_A, $this->reloadedContainer($containerId)->getLocationId());
        self::assertSame([], $report->unmigrated);
    }

    public function testContainersAreLocatedInTheOrderOfTheirNameSoTheFirstOneTakesTheStation(): void
    {
        $project = $this->newProject();
        $this->addComponentLines($project, $this->azbelAId);
        $ishtarId = $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $fuelDepotId = $this->addContainer($project, self::SECOND_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_A, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_A, self::SECOND_CONTAINER_NAME);
        $this->flushAndClear();

        $report = $this->migration->migrate($this->reloadedProject($project));

        self::assertSame(self::STATION_A, $this->reloadedContainer($fuelDepotId)->getLocationId());
        self::assertNull($this->reloadedContainer($ishtarId)->getLocationId());
        self::assertSame([[StationMigrationChangeKind::ContainerLocation, $ishtarId, StationMigrationReason::StationAlreadyHasContainer]], $this->unmigratedOf($report));
    }

    public function testACompletedProjectStillGetsItsPlanStations(): void
    {
        $project = $this->newProject('Delivered fleet', ProjectStatus::Completed);
        $this->addComponentLines($project, $this->azbelAId);
        $this->flushAndClear();
        $project = $this->reloadedProject($project);

        $report = $this->migration->migrate($project);

        self::assertSame(['components' => $this->workStationOf($this->azbelAId)], $project->getWorkStations());
        self::assertCount(1, $report->changes);
    }

    public function testAnOwnerWithoutCorporationHasItsContainersReportedAsAssetsUnavailableWithTheReasonAsDetail(): void
    {
        $ownerWithoutCorporation = $this->createUser();
        $project = $this->newProject('Offline owner', ProjectStatus::InProgress, $ownerWithoutCorporation);
        $containerId = $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $this->flushAndClear();

        $report = $this->migration->migrate($this->reloadedProject($project));

        self::assertCount(1, $report->unmigrated);
        $unmigrated = $report->unmigrated[0];
        self::assertSame(
            [StationMigrationChangeKind::ContainerLocation, $containerId, StationMigrationReason::AssetsUnavailable, 'owner_without_corporation'],
            [$unmigrated->kind, $unmigrated->subject, $unmigrated->reason, $unmigrated->detail],
        );
        self::assertSame($project->getId()->toRfc4122(), $unmigrated->projectId);
    }

    public function testAnOwnerWhoseCorporationHasNoDirectorHasItsContainersReportedAsAssetsUnavailable(): void
    {
        $ownerWithoutDirector = $this->createCorporationMember('Lonely Member', 98_132_002, false);
        $project = $this->newProject('No director', ProjectStatus::InProgress, $ownerWithoutDirector);
        $containerId = $this->addContainer($project, self::FIRST_CONTAINER_NAME);
        $this->flushAndClear();

        $report = $this->migration->migrate($this->reloadedProject($project));

        self::assertCount(1, $report->unmigrated);
        $unmigrated = $report->unmigrated[0];
        self::assertSame(
            [StationMigrationChangeKind::ContainerLocation, $containerId, StationMigrationReason::AssetsUnavailable, 'no_director'],
            [$unmigrated->kind, $unmigrated->subject, $unmigrated->reason, $unmigrated->detail],
        );
    }

    private function unmigratedOf(ProjectStationMigrationReport $report): array
    {
        return array_map(
            static fn ($unmigrated): array => [$unmigrated->kind, $unmigrated->subject, $unmigrated->reason],
            $report->unmigrated,
        );
    }
}
