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
final class WorkStationAdvisorCargoTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int OWNED_PYERITE = 35;
    private const int ISHTAR = 12005;
    private const int HECATE = 35683;
    private const int MORPHITE = 11399;
    private const int MISSING_BLUEPRINT_PRODUCT = 777;

    public function testMaterialThatIsNeitherProducedByTheProjectNorBoughtIsNotShipped(): void
    {
        $advisor = Scenario::advisor(
            [Scenario::recipe(self::ISHTAR, ActivityKind::Manufacturing, 1, [self::TRITANIUM => 10, self::OWNED_PYERITE => 4])],
            [self::TRITANIUM => 0.1, self::OWNED_PYERITE => 2.0, self::ISHTAR => 100.0],
            [self::TRITANIUM => 5.0, self::OWNED_PYERITE => 9.0],
        );
        $plan = Scenario::plan(
            [Scenario::job(self::ISHTAR, ActivityKind::Manufacturing, 1, [self::TRITANIUM => 100, self::OWNED_PYERITE => 50])],
            [self::TRITANIUM => 100],
        );

        $advices = $advisor->advise(new PlannedProduction($plan, []), [Scenario::complex('CW', Example::WORKSHOP_SYSTEM_ID)], Scenario::favoriteSystemsWithHub(), [self::ISHTAR]);

        $courier = $this->legOfKind($advices[0], 'CW', WorkTransportKind::Courier);
        $this->assertSame(10.0, $courier->cubicMeters);
        $this->assertSame(8_000.0, $courier->cost);
    }

    public function testFinalProductsLeaveForTheHubWithTheUnitsOfEveryJobOfEveryTargetProduct(): void
    {
        $advisor = Scenario::advisor(
            [
                Scenario::recipe(self::ISHTAR, ActivityKind::Manufacturing, 5, [self::MORPHITE => 10]),
                Scenario::recipe(self::HECATE, ActivityKind::Manufacturing, 3, [self::MORPHITE => 6]),
            ],
            [self::MORPHITE => 0.5, self::ISHTAR => 2.0, self::HECATE => 10.0],
            [self::MORPHITE => 100.0],
        );
        $plan = Scenario::plan(
            [
                Scenario::job(self::ISHTAR, ActivityKind::Manufacturing, 3, [self::MORPHITE => 30]),
                Scenario::job(self::ISHTAR, ActivityKind::Manufacturing, 4, [self::MORPHITE => 40]),
                Scenario::job(self::HECATE, ActivityKind::Manufacturing, 2, [self::MORPHITE => 12]),
            ],
            [self::MORPHITE => 82],
        );

        $advices = $advisor->advise(new PlannedProduction($plan, []), [Scenario::complex('CT', Example::THREE_JUMPS_SYSTEM_ID)], Scenario::favoriteSystemsWithHub(), [self::ISHTAR, self::HECATE]);

        $toHub = $this->legOfKind($advices[0], 'CT', WorkTransportKind::ToHub);
        $this->assertSame(130.0, $toHub->cubicMeters);
        $this->assertSame(6_500.0, $toHub->cost);
        $this->assertSame([3, 9], [$advices[0]->jobs, $advices[0]->runs]);
    }

    public function testJobWithoutRecipeFailsExplicitlyWhenAStructureCanRunIt(): void
    {
        $advisor = Scenario::advisor([], [], []);
        $plan = Scenario::plan([Scenario::job(self::MISSING_BLUEPRINT_PRODUCT, ActivityKind::Manufacturing, 1, [])], []);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Job of product 777 has no recipe.');

        $advisor->advise(new PlannedProduction($plan, []), [Scenario::complex('CW', Example::WORKSHOP_SYSTEM_ID)], Scenario::favoriteSystemsWithHub(), [self::MISSING_BLUEPRINT_PRODUCT]);
    }

    public function testFinalProductsWithoutRecipeFailExplicitlyEvenWhenNoStructureCanRunThem(): void
    {
        $advisor = Scenario::advisor([], [], []);
        $plan = Scenario::plan([Scenario::job(self::MISSING_BLUEPRINT_PRODUCT, ActivityKind::Manufacturing, 1, [])], []);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Job of product 777 has no recipe.');

        $advisor->advise(new PlannedProduction($plan, []), [Scenario::refinery('RB', Example::WORKSHOP_SYSTEM_ID)], Scenario::favoriteSystemsWithHub(), [self::MISSING_BLUEPRINT_PRODUCT]);
    }

    public function testJobsOfAnActivityOtherThanManufacturingAndReactionAreNotAWorkType(): void
    {
        $advisor = Scenario::advisor(
            [Scenario::recipe(self::ISHTAR, ActivityKind::Manufacturing, 1, [self::MORPHITE => 10])],
            [self::MORPHITE => 0.5, self::ISHTAR => 2.0],
            [self::MORPHITE => 100.0],
        );
        $plan = Scenario::plan(
            [
                Scenario::job(self::MISSING_BLUEPRINT_PRODUCT, ActivityKind::Copying, 1, []),
                Scenario::job(self::ISHTAR, ActivityKind::Manufacturing, 1, [self::MORPHITE => 10]),
            ],
            [self::MORPHITE => 10],
        );

        $advices = $advisor->advise(new PlannedProduction($plan, []), [Scenario::complex('CW', Example::WORKSHOP_SYSTEM_ID)], Scenario::favoriteSystemsWithHub(), [self::ISHTAR]);

        $this->assertSame([WorkType::FinalProducts], array_map(static fn (WorkTypeAdvice $advice): WorkType => $advice->workType, $advices));
        $this->assertSame([1, 1], [$advices[0]->jobs, $advices[0]->runs]);
    }

    private function legOfKind(WorkTypeAdvice $advice, string $structureId, WorkTransportKind $kind): WorkTransportLeg
    {
        $row = array_find($advice->rows, static fn (WorkStationRow $row): bool => $row->structure->id === $structureId);
        $this->assertNotNull($row);
        $leg = array_find($row->transportLegs, static fn (WorkTransportLeg $leg): bool => $kind === $leg->kind);
        $this->assertNotNull($leg);

        return $leg;
    }
}
