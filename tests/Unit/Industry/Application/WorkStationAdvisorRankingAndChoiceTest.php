<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\PlannedProduction;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\WorkStationAdvisor;
use App\Industry\Application\WorkStationRow;
use App\Industry\Application\WorkType;
use App\Industry\Application\WorkTypeAdvice;
use App\Industry\Domain\ActivityKind;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use App\Tests\Unit\Industry\Application\Double\WorkStationAdvisorScenario as Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkStationAdvisor::class)]
final class WorkStationAdvisorRankingAndChoiceTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int ISHTAR = 12005;

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function inputOrdersOfTwoUnknownStructures(): iterable
    {
        yield 'already in id order' => [['Z1', 'Z2']];
        yield 'reversed' => [['Z2', 'Z1']];
    }

    /**
     * @param list<string> $structureIds
     */
    #[DataProvider('inputOrdersOfTwoUnknownStructures')]
    public function testRowsWithAnUnknownTotalAreRankedByIdWhateverTheInputOrder(array $structureIds): void
    {
        $structures = array_map(static fn (string $id): ProductionStructure => Scenario::complex($id, null), $structureIds);

        $advice = $this->adviseFinalProducts($structures);

        $this->assertSame(['Z1', 'Z2'], array_map(static fn (WorkStationRow $row): string => $row->structure->id, $advice->rows));
        $this->assertNull($advice->recommendedStructureId);
    }

    public function testStoredStructureWhoseTotalEqualsTheRecommendedOneHasNoSaving(): void
    {
        $advice = $this->adviseFinalProducts(
            [Scenario::complex('P', Example::SECOND_THREE_JUMPS_SYSTEM_ID), Scenario::complex('Q', Example::EIGHT_JUMPS_SYSTEM_ID)],
            ['final_products' => 'Q'],
        );

        $this->assertSame(['P', 'Q', false], [$advice->recommendedStructureId, $advice->chosenStructureId, $advice->choiceStale]);
        $this->assertNotNull($this->totalOf($advice, 'P'));
        $this->assertSame($this->totalOf($advice, 'P'), $this->totalOf($advice, 'Q'));
        $this->assertNull($advice->savingIfRecommended);
    }

    public function testBlueprintsAdviceCarriesNeitherJobsNorRunsAndIsNotStaleWithoutStoredChoice(): void
    {
        $advice = $this->adviseBlueprints([Scenario::complex('CW', Example::WORKSHOP_SYSTEM_ID)], []);

        $this->assertSame([WorkType::Blueprints, ActivityKind::Copying, 0, 0, 'CW', null, false, null, false], [
            $advice->workType, $advice->activity, $advice->jobs, $advice->runs, $advice->recommendedStructureId, $advice->chosenStructureId, $advice->choiceStale, $advice->savingIfRecommended, $advice->costsCompared,
        ]);
    }

    public function testBlueprintsStoredStructureThatDoesNotExistAnymoreIsStale(): void
    {
        $advice = $this->adviseBlueprints([Scenario::complex('CW', Example::WORKSHOP_SYSTEM_ID)], ['blueprints' => 'deleted']);

        $this->assertSame([null, true], [$advice->chosenStructureId, $advice->choiceStale]);
    }

    public function testBlueprintsStoredStructureThatCannotCopyIsStale(): void
    {
        $advice = $this->adviseBlueprints([Scenario::complex('CW', Example::WORKSHOP_SYSTEM_ID), Scenario::refinery('RB', Example::WORKSHOP_SYSTEM_ID)], ['blueprints' => 'RB']);

        $this->assertSame(['CW', null, true], [$advice->recommendedStructureId, $advice->chosenStructureId, $advice->choiceStale]);
    }

    public function testBlueprintsWithoutStructureAbleToCopyHaveNoRecommendationAndNoRow(): void
    {
        $advice = $this->adviseBlueprints([Scenario::refinery('RB', Example::WORKSHOP_SYSTEM_ID)], []);

        $this->assertNull($advice->recommendedStructureId);
        $this->assertSame([], $advice->rows);
    }

    /**
     * @param list<ProductionStructure> $structures
     * @param array<string, string>     $storedStructureIds
     */
    private function adviseFinalProducts(array $structures, array $storedStructureIds = []): WorkTypeAdvice
    {
        $advices = $this->advise($structures, $storedStructureIds, false);

        return $advices[0];
    }

    /**
     * @param list<ProductionStructure> $structures
     * @param array<string, string>     $storedStructureIds
     */
    private function adviseBlueprints(array $structures, array $storedStructureIds): WorkTypeAdvice
    {
        $advices = $this->advise($structures, $storedStructureIds, true);

        return array_find($advices, static fn (WorkTypeAdvice $advice): bool => WorkType::Blueprints === $advice->workType) ?? $this->fail('No blueprints advice.');
    }

    /**
     * @param list<ProductionStructure> $structures
     * @param array<string, string>     $storedStructureIds
     *
     * @return list<WorkTypeAdvice>
     */
    private function advise(array $structures, array $storedStructureIds, bool $includesBlueprints): array
    {
        $advisor = Scenario::advisor(
            [Scenario::recipe(self::ISHTAR, ActivityKind::Manufacturing, 1, [self::TRITANIUM => 10])],
            [self::TRITANIUM => 0.1, self::ISHTAR => 200.0],
            [self::TRITANIUM => 5.0],
        );
        $plan = Scenario::plan([Scenario::job(self::ISHTAR, ActivityKind::Manufacturing, 3, [self::TRITANIUM => 30])], [self::TRITANIUM => 30]);

        return $advisor->advise(new PlannedProduction($plan, []), $structures, Scenario::favoriteSystemsWithHub(), [self::ISHTAR], $storedStructureIds, $includesBlueprints);
    }

    private function totalOf(WorkTypeAdvice $advice, string $structureId): ?float
    {
        $row = array_find($advice->rows, static fn (WorkStationRow $row): bool => $row->structure->id === $structureId);
        $this->assertNotNull($row);

        return $row->totalCost;
    }
}
