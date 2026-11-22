<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\PlannedProduction;
use App\Industry\Application\WorkCargo;
use App\Industry\Application\WorkStationAdvisor;
use App\Industry\Application\WorkStationRow;
use App\Industry\Application\WorkTransportKind;
use App\Industry\Application\WorkTransportLeg;
use App\Industry\Application\WorkType;
use App\Industry\Application\WorkTypeAdvice;
use App\Industry\Domain\ActivityKind;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use App\Tests\Unit\Industry\Application\Double\WorkStationAdvisorScenario as Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkStationAdvisor::class)]
#[CoversClass(WorkCargo::class)]
final class WorkStationAdvisorStationRefinementTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int REACTION_PRODUCT = 5001;
    private const int COMPONENT = 5002;
    private const int HULL = 5003;
    private const int FINAL_PRODUCT = 5004;

    public function testFinalProductsReadTheHullStationOfTheSecondRefinementPass(): void
    {
        $advices = $this->adviseChainOfFourTypesOfWork();

        $final = $this->adviceFor(WorkType::FinalProducts, $advices);
        $this->assertSame(['CT', 'CW'], $this->structureIdsSortedOf($final));
        $this->assertSame([WorkTransportKind::JumpBridge, 'CT', 'CW', 100.0, 5_000.0], $this->hullLegOf($final, 'CW'));
        $this->assertSame([WorkTransportKind::JumpBridge, 'CT', 'CT', 100.0, 0.0], $this->hullLegOf($final, 'CT'));
    }

    public function testReactionAndComponentsFollowTheStationOfTheTypeTheyDependOn(): void
    {
        $advices = $this->adviseChainOfFourTypesOfWork();

        $this->assertSame('RB', $this->adviceFor(WorkType::Reaction, $advices)->recommendedStructureId);
        $this->assertSame('CW', $this->adviceFor(WorkType::Components, $advices)->recommendedStructureId);
    }

    /**
     * @return list<WorkTypeAdvice>
     */
    private function adviseChainOfFourTypesOfWork(): array
    {
        $advisor = Scenario::advisor(
            [
                Scenario::recipe(self::REACTION_PRODUCT, ActivityKind::Reaction, 1, [self::TRITANIUM => 10]),
                Scenario::recipe(self::COMPONENT, ActivityKind::Manufacturing, 1, [self::REACTION_PRODUCT => 2, self::TRITANIUM => 4]),
                Scenario::recipe(self::HULL, ActivityKind::Manufacturing, 1, [self::COMPONENT => 1, self::TRITANIUM => 3]),
                Scenario::recipe(self::FINAL_PRODUCT, ActivityKind::Manufacturing, 1, [self::HULL => 1]),
            ],
            [self::TRITANIUM => 0.1, self::REACTION_PRODUCT => 1.0, self::COMPONENT => 5.0, self::HULL => 50.0, self::FINAL_PRODUCT => 200.0],
            [self::TRITANIUM => 5.0, self::REACTION_PRODUCT => 700.0, self::COMPONENT => 3_000.0, self::HULL => 40_000.0, self::FINAL_PRODUCT => 90_000.0],
            [self::HULL],
        );
        $plan = Scenario::plan(
            [
                Scenario::job(self::FINAL_PRODUCT, ActivityKind::Manufacturing, 1, [self::HULL => 2]),
                Scenario::job(self::HULL, ActivityKind::Manufacturing, 1, [self::COMPONENT => 10, self::TRITANIUM => 30]),
                Scenario::job(self::COMPONENT, ActivityKind::Manufacturing, 1, [self::REACTION_PRODUCT => 20, self::TRITANIUM => 40]),
                Scenario::job(self::REACTION_PRODUCT, ActivityKind::Reaction, 1, [self::TRITANIUM => 100]),
            ],
            [self::TRITANIUM => 170],
        );
        $structures = [
            Scenario::refinery('RA', Example::THREE_JUMPS_SYSTEM_ID),
            Scenario::refinery('RB', Example::WORKSHOP_SYSTEM_ID),
            Scenario::complex('CT', Example::THREE_JUMPS_SYSTEM_ID),
            Scenario::complex('CW', Example::WORKSHOP_SYSTEM_ID),
        ];

        return $advisor->advise(new PlannedProduction($plan, []), $structures, Scenario::favoriteSystemsWithHub(), [self::FINAL_PRODUCT]);
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

    /**
     * @return list<string>
     */
    private function structureIdsSortedOf(WorkTypeAdvice $advice): array
    {
        $ids = array_map(static fn (WorkStationRow $row): string => $row->structure->id, $advice->rows);
        sort($ids);

        return $ids;
    }

    /**
     * @return array{0: WorkTransportKind, 1: ?string, 2: ?string, 3: float, 4: ?float}
     */
    private function hullLegOf(WorkTypeAdvice $advice, string $structureId): array
    {
        $row = array_find($advice->rows, static fn (WorkStationRow $row): bool => $row->structure->id === $structureId);
        $this->assertNotNull($row);
        $leg = array_find($row->transportLegs, static fn (WorkTransportLeg $leg): bool => WorkTransportKind::JumpBridge === $leg->kind);
        $this->assertNotNull($leg);

        return [$leg->kind, $leg->fromStructureId, $leg->toStructureId, $leg->cubicMeters, $leg->cost];
    }
}
