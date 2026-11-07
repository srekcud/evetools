<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\StructureCostBreakdown;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StructureCostBreakdown::class)]
#[Group('issue-341')]
final class StructureCostBreakdownTest extends TestCase
{
    private const float ISK_TOLERANCE = 0.01;

    public function testTotalIsTheSumOfMaterialsInstallationAndTransport(): void
    {
        $breakdown = new StructureCostBreakdown($this->known(1_000.0), $this->known(70.0), $this->known(5.0), 20.0, 3);

        $this->assertEqualsWithDelta(1_075.0, $breakdown->total()->amount()->amount, self::ISK_TOLERANCE);
    }

    public function testSumOfTwoBreakdownsAddsEachCostAndTheCubicMetersAndKeepsTheJumpsOfTheSameStructure(): void
    {
        $first = new StructureCostBreakdown($this->known(1_000.0), $this->known(70.0), $this->known(5.0), 20.0, 3);
        $second = new StructureCostBreakdown($this->known(250.0), $this->known(30.0), $this->known(7.5), 30.5, 3);

        $sum = $first->plus($second);

        $this->assertEqualsWithDelta(1_250.0, $sum->materials->amount()->amount, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(100.0, $sum->installation->amount()->amount, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(12.5, $sum->transport->amount()->amount, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(50.5, $sum->transportedCubicMeters, self::ISK_TOLERANCE);
        $this->assertSame(3, $sum->jumps);
    }

    public function testSumIsUnknownForTheCubicMetersAndTheJumpsWhenOneSideIsUnknown(): void
    {
        $known = new StructureCostBreakdown($this->known(1_000.0), $this->known(70.0), $this->known(5.0), 20.0, 3);
        $withoutVolumeOrRoute = new StructureCostBreakdown($this->known(250.0), $this->known(30.0), Cost::unknown(MissingData::jumpRoute()), null, null);

        $sum = $known->plus($withoutVolumeOrRoute);
        $reversedSum = $withoutVolumeOrRoute->plus($known);

        $this->assertNull($sum->transportedCubicMeters);
        $this->assertNull($sum->jumps);
        $this->assertNull($reversedSum->transportedCubicMeters);
        $this->assertNull($reversedSum->jumps);
        $this->assertFalse($sum->transport->isKnown());
    }

    public function testUnknownCostMakesTheTotalUnknownWithItsMissingDataNeverPartial(): void
    {
        $breakdown = new StructureCostBreakdown(
            $this->known(1_000.0),
            Cost::unknown(MissingData::costIndex()),
            $this->known(5.0),
            20.0,
            3,
        );

        $total = $breakdown->total();

        $this->assertFalse($total->isKnown());
        $this->assertEquals([MissingData::costIndex()], $total->missingData);
    }

    private function known(float $amount): Cost
    {
        return Cost::known(new Isk($amount));
    }
}
