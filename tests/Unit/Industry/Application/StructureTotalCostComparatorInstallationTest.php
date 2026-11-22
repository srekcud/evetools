<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\StructureTotalCostComparator;
use App\Industry\Domain\Cost;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Runs;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StructureTotalCostComparator::class)]
final class StructureTotalCostComparatorInstallationTest extends TestCase
{
    private const int RUNS = 5;
    private const float FACILITY_TAX_RATE = 0.02;
    private const float INSTALLATION_OF_FIVE_RUNS_AT_TWO_PERCENT_TAX = 500_000.0;

    public function testInstallationOfAJobIsTheCostIndexTermTheSurchargeAndTheFacilityTaxOnTheEstimatedItemValue(): void
    {
        $structure = Example::unriggedStructure('U', Example::THREE_JUMPS_SYSTEM_ID, self::FACILITY_TAX_RATE);

        $installation = Example::comparator()->installationOf(Example::recipe(), new Runs(self::RUNS), $structure, false);

        $this->assertTrue($installation->isKnown());
        $this->assertEqualsWithDelta(self::INSTALLATION_OF_FIVE_RUNS_AT_TWO_PERCENT_TAX, $installation->amount()->amount, 0.01);
    }

    public function testInstallationWithoutStructureIsUnknownWithTheCostIndexMissing(): void
    {
        $installation = Example::comparator()->installationOf(Example::recipe(), new Runs(self::RUNS), null, false);

        $this->assertEquals(Cost::unknown(MissingData::costIndex()), $installation);
    }

    public function testInstallationOfAStructureWithoutSolarSystemIsUnknownWithTheCostIndexMissing(): void
    {
        $installation = Example::comparator()->installationOf(Example::recipe(), new Runs(self::RUNS), Example::riggedStructure('without-system', null), false);

        $this->assertEquals(Cost::unknown(MissingData::costIndex()), $installation);
    }
}
