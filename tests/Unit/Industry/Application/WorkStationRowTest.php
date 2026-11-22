<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\WorkStationRow;
use App\Industry\Application\WorkTransportKind;
use App\Industry\Application\WorkTransportLeg;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Multiplier;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkStationRow::class)]
final class WorkStationRowTest extends TestCase
{
    public function testWithDeltaReturnsACopyCarryingTheGivenDeltaAndEveryOtherFieldUnchanged(): void
    {
        $structure = Example::structureC();
        $leg = new WorkTransportLeg(WorkTransportKind::JumpBridge, 'B', 'C', 42.5, 2125.0);
        $row = new WorkStationRow($structure, true, 3, new Multiplier(0.9), 1_234.5, 67.25, 2_125.0, [$leg], 3_426.75, null, [MissingData::jumpRoute()]);

        $withDelta = $row->withDelta(512.5);

        $this->assertNotSame($row, $withDelta);
        $this->assertSame(512.5, $withDelta->delta);
        $this->assertNull($row->delta);
        $this->assertSame($structure, $withDelta->structure);
        $this->assertTrue($withDelta->inFavoriteSystem);
        $this->assertSame(3, $withDelta->jumps);
        $this->assertSame(0.9, $withDelta->materialMultiplier->value);
        $this->assertSame(1_234.5, $withDelta->materialsCost);
        $this->assertSame(67.25, $withDelta->installationCost);
        $this->assertSame(2_125.0, $withDelta->transportCost);
        $this->assertSame([$leg], $withDelta->transportLegs);
        $this->assertSame(3_426.75, $withDelta->totalCost);
        $this->assertEquals([MissingData::jumpRoute()], $withDelta->missingData);
    }

    public function testWithDeltaCanClearTheDelta(): void
    {
        $row = new WorkStationRow(Example::structureD(), false, null, Multiplier::one(), null, null, null, [], null, 88.0, []);

        $this->assertNull($row->withDelta(null)->delta);
        $this->assertSame(88.0, $row->delta);
    }
}
