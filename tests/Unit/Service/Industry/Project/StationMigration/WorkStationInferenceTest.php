<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\StationMigration;

use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureType;
use App\Industry\Application\WorkType;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\SecurityClass;
use App\Service\Industry\Project\StationMigration\StationMigrationReason;
use App\Service\Industry\Project\StationMigration\WorkStationInference;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkStationInference::class)]
#[Group('issue-1320')]
final class WorkStationInferenceTest extends TestCase
{
    private const string AZBEL_ID = '0191a000-0000-7000-8000-00000000a001';
    private const string SOTIYO_ID = '0191a000-0000-7000-8000-00000000a002';
    private const string ATHANOR_ID = '0191a000-0000-7000-8000-00000000a003';
    private const string DELETED_CONFIGURATION_ID = '0191a000-0000-7000-8000-00000000dead';

    private WorkStationInference $inference;

    protected function setUp(): void
    {
        $this->inference = new WorkStationInference();
    }

    public function testEveryLineInTheSameStationThatCanRunTheActivityGivesThatStation(): void
    {
        $result = $this->inference->of(WorkType::Components, [self::AZBEL_ID, self::AZBEL_ID, self::AZBEL_ID], $this->ownerStructures());

        self::assertSame(self::AZBEL_ID, $result);
    }

    public function testASingleLineGivesItsStation(): void
    {
        $result = $this->inference->of(WorkType::Hulls, [self::SOTIYO_ID], $this->ownerStructures());

        self::assertSame(self::SOTIYO_ID, $result);
    }

    public function testReactionLinesInARefineryGiveTheRefinery(): void
    {
        $result = $this->inference->of(WorkType::Reaction, [self::ATHANOR_ID, self::ATHANOR_ID], $this->ownerStructures());

        self::assertSame(self::ATHANOR_ID, $result);
    }

    public function testReactionLinesInAnEngineeringComplexMeanStructureCannotRun(): void
    {
        $result = $this->inference->of(WorkType::Reaction, [self::AZBEL_ID, self::AZBEL_ID], $this->ownerStructures());

        self::assertSame(StationMigrationReason::StructureCannotRun, $result);
    }

    public static function nonReactionWorkTypes(): array
    {
        return [
            'blueprints' => [WorkType::Blueprints],
            'components' => [WorkType::Components],
            'hulls' => [WorkType::Hulls],
            'final products' => [WorkType::FinalProducts],
        ];
    }

    #[DataProvider('nonReactionWorkTypes')]
    public function testANonReactionWorkTypeInAnEngineeringComplexGivesIt(WorkType $workType): void
    {
        $result = $this->inference->of($workType, [self::SOTIYO_ID], $this->ownerStructures());

        self::assertSame(self::SOTIYO_ID, $result);
    }

    #[DataProvider('nonReactionWorkTypes')]
    public function testANonReactionWorkTypeInARefineryMeansStructureCannotRun(WorkType $workType): void
    {
        $result = $this->inference->of($workType, [self::ATHANOR_ID], $this->ownerStructures());

        self::assertSame(StationMigrationReason::StructureCannotRun, $result);
    }

    public function testALineWithoutStationMeansLinesWithoutStation(): void
    {
        $result = $this->inference->of(WorkType::Reaction, [null], $this->ownerStructures());

        self::assertSame(StationMigrationReason::LinesWithoutStation, $result);
    }

    public function testOneLineWithoutStationAmongLinesInTheSameStationMeansLinesWithoutStation(): void
    {
        $result = $this->inference->of(WorkType::Components, [self::AZBEL_ID, null, self::AZBEL_ID], $this->ownerStructures());

        self::assertSame(StationMigrationReason::LinesWithoutStation, $result);
    }

    public function testNoLineAtAllMeansLinesWithoutStation(): void
    {
        $result = $this->inference->of(WorkType::Components, [], $this->ownerStructures());

        self::assertSame(StationMigrationReason::LinesWithoutStation, $result);
    }

    public function testLinesInTwoStationsMeanSeveralStations(): void
    {
        $result = $this->inference->of(WorkType::Components, [self::AZBEL_ID, self::SOTIYO_ID], $this->ownerStructures());

        self::assertSame(StationMigrationReason::SeveralStations, $result);
    }

    public function testLinesInThreeStationsMeanSeveralStations(): void
    {
        $result = $this->inference->of(WorkType::Components, [self::AZBEL_ID, self::SOTIYO_ID, self::AZBEL_ID, self::ATHANOR_ID], $this->ownerStructures());

        self::assertSame(StationMigrationReason::SeveralStations, $result);
    }

    public function testAStructureMissingFromTheOwnerStructuresMeansStructureUnknown(): void
    {
        $result = $this->inference->of(WorkType::Hulls, [self::DELETED_CONFIGURATION_ID, self::DELETED_CONFIGURATION_ID], $this->ownerStructures());

        self::assertSame(StationMigrationReason::StructureUnknown, $result);
    }

    public function testAPlannedValueThatIsNotAConfigurationUuidMeansStructureUnknown(): void
    {
        $result = $this->inference->of(WorkType::FinalProducts, ['not-a-uuid'], $this->ownerStructures());

        self::assertSame(StationMigrationReason::StructureUnknown, $result);
    }

    public function testNoOwnerStructureAtAllMeansStructureUnknown(): void
    {
        $result = $this->inference->of(WorkType::Components, [self::AZBEL_ID], []);

        self::assertSame(StationMigrationReason::StructureUnknown, $result);
    }

    private function ownerStructures(): array
    {
        return [
            self::AZBEL_ID => $this->structure(self::AZBEL_ID, StructureType::EngineeringComplex),
            self::SOTIYO_ID => $this->structure(self::SOTIYO_ID, StructureType::EngineeringComplex),
            self::ATHANOR_ID => $this->structure(self::ATHANOR_ID, StructureType::Refinery),
        ];
    }

    private function structure(string $id, StructureType $type): ProductionStructure
    {
        return new ProductionStructure(
            $id,
            $type,
            SecurityClass::NullSec,
            30_002_187,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            Multiplier::one(),
            [],
            Multiplier::one(),
            0.0,
        );
    }
}
