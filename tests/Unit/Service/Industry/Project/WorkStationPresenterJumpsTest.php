<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Industry\Application\ProductionStructure;
use App\Industry\Application\WorkStationRow;
use App\Industry\Application\WorkType;
use App\Industry\Application\WorkTypeAdvice;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Multiplier;
use App\Service\Industry\Project\WorkStationPresenter;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkStationPresenter::class)]
#[Group('issue-1339')]
final class WorkStationPresenterJumpsTest extends TestCase
{
    public function testRowExposesItsJumpsAsAnInteger(): void
    {
        $rows = $this->presentedRows([
            $this->rowOf(Example::structureC(), 3),
            $this->rowOf(Example::workshopStructureWithoutRig(), 0),
            $this->rowOf(Example::structureD(), 8),
        ]);

        $this->assertSame([3, 0, 8], array_column($rows, 'jumps'));
    }

    public function testUnknownJumpsAreExposedAsNullNeverAsZero(): void
    {
        $rows = $this->presentedRows([$this->rowOf(Example::riggedStructure('Z', null), null)]);

        $this->assertArrayHasKey('jumps', $rows[0]);
        $this->assertNull($rows[0]['jumps']);
    }

    private function rowOf(ProductionStructure $structure, ?int $jumps): WorkStationRow
    {
        return new WorkStationRow($structure, 0 === $jumps, $jumps, Multiplier::one(), null, null, null, [], null, null, []);
    }

    /**
     * @param list<WorkStationRow> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function presentedRows(array $rows): array
    {
        $labels = [];
        foreach ($rows as $row) {
            $labels[$row->structure->id] = ['name' => 'Structure '.$row->structure->id, 'kind' => 'raitaru'];
        }
        $advice = new WorkTypeAdvice(WorkType::FinalProducts, ActivityKind::Manufacturing, 1, 1, null, null, false, null, true, $rows);

        return WorkStationPresenter::of($advice, null, null, $labels, [])['rows'];
    }
}
