<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\PlannedProduction;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\WorkCargo;
use App\Industry\Application\WorkStationAdvisor;
use App\Industry\Application\WorkStationRow;
use App\Industry\Application\WorkTransportKind;
use App\Industry\Application\WorkTransportLeg;
use App\Industry\Application\WorkType;
use App\Industry\Application\WorkTypeAdvice;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\MissingData;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use App\Tests\Unit\Industry\Application\Double\WorkStationAdvisorScenario as Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkStationAdvisor::class)]
#[CoversClass(WorkCargo::class)]
final class WorkStationAdvisorUnknownLegsTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int REACTION_PRODUCT = 5001;
    private const int COMPONENT = 5002;
    private const int HULL = 5003;
    private const int FINAL_PRODUCT = 5004;

    public function testJumpBridgeToAStructureWithoutSolarSystemIsUnknown(): void
    {
        $advices = $this->adviseReactionThenComponents(
            [Scenario::refinery('RB', Example::WORKSHOP_SYSTEM_ID), Scenario::complex('Z', null)],
            ['reaction' => 'RB'],
        );

        $leg = $this->legOfKind($this->adviceFor(WorkType::Components, $advices), 'Z', WorkTransportKind::JumpBridge);
        $this->assertSame(['RB', 'Z', 20.0, null], [$leg->fromStructureId, $leg->toStructureId, $leg->cubicMeters, $leg->cost]);
        $this->assertContainsEquals(MissingData::jumpRoute(), $this->rowOf($this->adviceFor(WorkType::Components, $advices), 'Z')->missingData);
    }

    public function testJumpBridgeFromAStationWithoutSolarSystemIsUnknown(): void
    {
        $advices = $this->adviseReactionThenComponents(
            [Scenario::refinery('RN', null), Scenario::complex('CW', Example::WORKSHOP_SYSTEM_ID)],
            ['reaction' => 'RN'],
        );

        $leg = $this->legOfKind($this->adviceFor(WorkType::Components, $advices), 'CW', WorkTransportKind::JumpBridge);
        $this->assertSame(['RN', 'CW', 20.0, null], [$leg->fromStructureId, $leg->toStructureId, $leg->cubicMeters, $leg->cost]);
    }

    public function testJumpBridgeFromATypeOfWorkWithoutStationIsUnknownWithTheJumpRouteMissing(): void
    {
        $advices = $this->adviseReactionThenComponents([Scenario::complex('CW', Example::WORKSHOP_SYSTEM_ID)], []);

        $components = $this->adviceFor(WorkType::Components, $advices);
        $leg = $this->legOfKind($components, 'CW', WorkTransportKind::JumpBridge);
        $this->assertSame([null, 'CW', 20.0, null], [$leg->fromStructureId, $leg->toStructureId, $leg->cubicMeters, $leg->cost]);
        $this->assertNull($this->rowOf($components, 'CW')->totalCost);
        $this->assertContainsEquals(MissingData::jumpRoute(), $this->rowOf($components, 'CW')->missingData);
        $this->assertNull($components->recommendedStructureId);
    }

    public function testJumpBridgeBetweenTheSameStructureWithoutSolarSystemIsFree(): void
    {
        $advisor = Scenario::advisor(
            [
                Scenario::recipe(self::COMPONENT, ActivityKind::Manufacturing, 1, [self::TRITANIUM => 4]),
                Scenario::recipe(self::HULL, ActivityKind::Manufacturing, 1, [self::COMPONENT => 1, self::TRITANIUM => 3]),
            ],
            [self::TRITANIUM => 0.1, self::COMPONENT => 5.0, self::HULL => 50.0],
            [self::TRITANIUM => 5.0, self::COMPONENT => 3_000.0],
            [self::HULL],
        );
        $plan = Scenario::plan(
            [
                Scenario::job(self::HULL, ActivityKind::Manufacturing, 1, [self::COMPONENT => 10, self::TRITANIUM => 30]),
                Scenario::job(self::COMPONENT, ActivityKind::Manufacturing, 1, [self::TRITANIUM => 40]),
            ],
            [self::TRITANIUM => 70],
        );

        $advices = $advisor->advise(new PlannedProduction($plan, []), [Scenario::complex('Z', null)], Scenario::favoriteSystemsWithHub(), [self::FINAL_PRODUCT], ['components' => 'Z']);

        $leg = $this->legOfKind($this->adviceFor(WorkType::Hulls, $advices), 'Z', WorkTransportKind::JumpBridge);
        $this->assertSame(['Z', 'Z', 50.0, 0.0], [$leg->fromStructureId, $leg->toStructureId, $leg->cubicMeters, $leg->cost]);
    }

    public function testFinalProductsLeavingForTheHubFromAStructureWithoutSolarSystemHaveAnUnknownCost(): void
    {
        $advisor = Scenario::advisor(
            [Scenario::recipe(self::FINAL_PRODUCT, ActivityKind::Manufacturing, 2, [self::TRITANIUM => 10])],
            [self::TRITANIUM => 0.1, self::FINAL_PRODUCT => 200.0],
            [self::TRITANIUM => 5.0],
        );
        $plan = Scenario::plan([Scenario::job(self::FINAL_PRODUCT, ActivityKind::Manufacturing, 3, [self::TRITANIUM => 30])], [self::TRITANIUM => 30]);

        $advices = $advisor->advise(new PlannedProduction($plan, []), [Scenario::complex('Z', null)], Scenario::favoriteSystemsWithHub(), [self::FINAL_PRODUCT]);

        $leg = $this->legOfKind($advices[0], 'Z', WorkTransportKind::ToHub);
        $this->assertSame(['Z', null, 1_200.0, null], [$leg->fromStructureId, $leg->toStructureId, $leg->cubicMeters, $leg->cost]);
        $this->assertContainsEquals(MissingData::jumpRoute(), $this->rowOf($advices[0], 'Z')->missingData);
    }

    /**
     * @param list<ProductionStructure> $structures
     * @param array<string, string>     $storedStructureIds
     *
     * @return list<WorkTypeAdvice>
     */
    private function adviseReactionThenComponents(array $structures, array $storedStructureIds): array
    {
        $advisor = Scenario::advisor(
            [
                Scenario::recipe(self::REACTION_PRODUCT, ActivityKind::Reaction, 1, [self::TRITANIUM => 10]),
                Scenario::recipe(self::COMPONENT, ActivityKind::Manufacturing, 1, [self::REACTION_PRODUCT => 2, self::TRITANIUM => 4]),
            ],
            [self::TRITANIUM => 0.1, self::REACTION_PRODUCT => 1.0, self::COMPONENT => 5.0],
            [self::TRITANIUM => 5.0, self::REACTION_PRODUCT => 700.0],
        );
        $plan = Scenario::plan(
            [
                Scenario::job(self::COMPONENT, ActivityKind::Manufacturing, 1, [self::REACTION_PRODUCT => 20, self::TRITANIUM => 40]),
                Scenario::job(self::REACTION_PRODUCT, ActivityKind::Reaction, 1, [self::TRITANIUM => 100]),
            ],
            [self::TRITANIUM => 140],
        );

        return $advisor->advise(new PlannedProduction($plan, []), $structures, Scenario::favoriteSystemsWithHub(), [self::FINAL_PRODUCT], $storedStructureIds);
    }

    /**
     * @param list<WorkTypeAdvice> $advices
     */
    private function adviceFor(WorkType $workType, array $advices): WorkTypeAdvice
    {
        $found = array_values(array_filter($advices, static fn (WorkTypeAdvice $advice): bool => $advice->workType === $workType));
        $this->assertCount(1, $found);

        return $found[0];
    }

    private function rowOf(WorkTypeAdvice $advice, string $structureId): WorkStationRow
    {
        $row = array_find($advice->rows, static fn (WorkStationRow $row): bool => $row->structure->id === $structureId);
        $this->assertNotNull($row);

        return $row;
    }

    private function legOfKind(WorkTypeAdvice $advice, string $structureId, WorkTransportKind $kind): WorkTransportLeg
    {
        $leg = array_find($this->rowOf($advice, $structureId)->transportLegs, static fn (WorkTransportLeg $leg): bool => $kind === $leg->kind);
        $this->assertNotNull($leg);

        return $leg;
    }
}
