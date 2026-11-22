<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\WorkCargo;
use App\Industry\Application\WorkTransportKind;
use App\Industry\Application\WorkType;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;
use App\Tests\Unit\Industry\Application\Double\InMemoryItemVolumes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkCargo::class)]
final class WorkCargoTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int ISOGEN = 37;

    public function testCubicMetersAreTheSumOfTheUnitsTimesTheVolumeOfEveryType(): void
    {
        $cargo = WorkCargo::of(
            WorkTransportKind::JumpBridge,
            WorkType::Components,
            [self::TRITANIUM => 3, self::PYERITE => 5],
            new InMemoryItemVolumes([self::TRITANIUM => 2.0, self::PYERITE => 0.5]),
        );

        $this->assertSame(8.5, $cargo->cubicMeters);
        $this->assertSame(WorkTransportKind::JumpBridge, $cargo->kind);
        $this->assertSame(WorkType::Components, $cargo->producedBy);
    }

    public function testCostIsTheCubicMetersTimesTheRateWhenEveryVolumeIsKnown(): void
    {
        $cargo = WorkCargo::of(
            WorkTransportKind::Courier,
            null,
            [self::TRITANIUM => 3, self::PYERITE => 5],
            new InMemoryItemVolumes([self::TRITANIUM => 2.0, self::PYERITE => 0.5]),
        );

        $this->assertEquals(Cost::known(new Isk(340.0)), $cargo->costAt(40.0));
        $this->assertNull($cargo->producedBy);
    }

    public function testTypesWithoutVolumeAreSkippedAndEveryOneOfThemMakesTheCostUnknownInOrder(): void
    {
        $cargo = WorkCargo::of(
            WorkTransportKind::Courier,
            null,
            [self::TRITANIUM => 4, self::PYERITE => 10, self::MEXALLON => 7, self::ISOGEN => 3],
            new InMemoryItemVolumes([self::PYERITE => 1.5, self::ISOGEN => 0.5]),
        );

        $this->assertSame(16.5, $cargo->cubicMeters);
        $this->assertEquals(Cost::unknown(MissingData::itemVolume(self::TRITANIUM), MissingData::itemVolume(self::MEXALLON)), $cargo->costAt(40.0));
    }

    public function testCostWhenVolumesAreKnownIsReturnedAsIsOnlyWithoutMissingVolume(): void
    {
        $volumes = new InMemoryItemVolumes([self::TRITANIUM => 2.0]);
        $givenCost = Cost::known(new Isk(1234.0));

        $complete = WorkCargo::of(WorkTransportKind::ToHub, null, [self::TRITANIUM => 1], $volumes);
        $incomplete = WorkCargo::of(WorkTransportKind::ToHub, null, [self::TRITANIUM => 1, self::PYERITE => 1], $volumes);

        $this->assertSame($givenCost, $complete->costOf($givenCost));
        $this->assertEquals(Cost::unknown(MissingData::itemVolume(self::PYERITE)), $incomplete->costOf($givenCost));
    }
}
