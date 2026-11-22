<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\StructureComparatorJobInstallationCosts;
use App\Industry\Domain\Cost;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Runs;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StructureComparatorJobInstallationCosts::class)]
#[Group('issue-1288')]
final class StructureComparatorJobInstallationCostsTest extends TestCase
{
    private const float INSTALLATION_OF_RIGGED_STRUCTURE_C = 850_000.0;

    public function testInstallationOfAJobIsTheInstallationTermOfTheStructureTotalCostBreakdown(): void
    {
        $comparator = Example::comparator();
        $structure = Example::structureC();
        $runs = new Runs(Example::REFERENCE_RUNS);

        $installation = (new StructureComparatorJobInstallationCosts($comparator))->installationOf(Example::recipe(), $runs, $structure);

        $expected = $comparator->breakdownOf(Example::recipe(), $runs, Example::PRODUCT_CATEGORY, $structure, Example::WORKSHOP_SYSTEM_ID, false)->installation;
        self::assertTrue($installation->isKnown());
        self::assertEquals($expected, $installation);
        self::assertEqualsWithDelta(self::INSTALLATION_OF_RIGGED_STRUCTURE_C, $installation->amount()->amount, 0.01);
    }

    public function testInstallationIsUnknownWithTheCostIndexMissingWhenTheStructureIsAbsentOrHasNoSolarSystem(): void
    {
        $adapter = new StructureComparatorJobInstallationCosts(Example::comparator());
        $runs = new Runs(Example::REFERENCE_RUNS);

        $withoutStructure = $adapter->installationOf(Example::recipe(), $runs, null);
        $withoutSolarSystem = $adapter->installationOf(Example::recipe(), $runs, Example::riggedStructure('without-system', null));

        self::assertEquals(Cost::unknown(MissingData::costIndex()), $withoutStructure);
        self::assertEquals(Cost::unknown(MissingData::costIndex()), $withoutSolarSystem);
    }
}
