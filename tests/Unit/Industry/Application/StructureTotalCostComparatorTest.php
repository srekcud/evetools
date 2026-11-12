<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureTotalCostComparator;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Cost;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Runs;
use App\Tests\Unit\Industry\Application\Double\InMemoryItemVolumes;
use App\Tests\Unit\Industry\Application\Double\InMemoryMarketPrices;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StructureTotalCostComparator::class)]
final class StructureTotalCostComparatorTest extends TestCase
{
    private const float ISK_TOLERANCE = 0.01;

    public function testTransportIsChargedAtOneHundredFiftyIskPerCubicMeterAndJump(): void
    {
        $this->assertSame(150.0, StructureTotalCostComparator::TRANSPORT_ISK_PER_CUBIC_METER_JUMP);
    }

    public function testStructureOfTheWorkshopSystemWithoutRigCostsItsMaterialsAndInstallationWithoutTransport(): void
    {
        $totalCost = $this->totalCostOf(Example::workshopStructureWithoutRig());

        $this->assertKnownCost(10_900_000.0, $totalCost);
    }

    public function testRiggedStructureThreeJumpsAwayAddsTheTransportOfTwoThousandCubicMetersOverThreeJumps(): void
    {
        $totalCost = $this->totalCostOf(Example::structureC());

        $this->assertKnownCost(10_750_000.0, $totalCost);
    }

    public function testRiggedStructureEightJumpsAwayAddsTheTransportOfTwoThousandCubicMetersOverEightJumps(): void
    {
        $totalCost = $this->totalCostOf(Example::structureD());

        $this->assertKnownCost(12_250_000.0, $totalCost);
    }

    public function testReactionIsComparedWithTheReactionRigAndCostIndexOfTheRefinery(): void
    {
        $totalCost = Example::comparator()->totalCostOf(
            Example::recipe(ActivityKind::Reaction),
            new Runs(Example::REFERENCE_RUNS),
            Example::PRODUCT_CATEGORY,
            Example::riggedRefinery(),
            Example::WORKSHOP_SYSTEM_ID,
            false,
        );

        $this->assertKnownCost(10_750_000.0, $totalCost);
    }

    public function testMissingMarketPriceOfAMaterialMakesTheTotalCostUnknownNeverZero(): void
    {
        $comparator = Example::comparator(marketPrices: new InMemoryMarketPrices([]));

        $totalCost = $this->totalCostOf(Example::structureC(), $comparator);

        $this->assertFalse($totalCost->isKnown());
        $this->assertEquals([MissingData::marketPrice(Example::MATERIAL_TYPE_ID)], $totalCost->missingData);
    }

    public function testMissingVolumeOfAMaterialMakesTheTotalCostUnknown(): void
    {
        $comparator = Example::comparator(itemVolumes: new InMemoryItemVolumes([Example::PRODUCT_TYPE_ID => Example::PRODUCT_CUBIC_METERS]));

        $totalCost = $this->totalCostOf(Example::structureC(), $comparator);

        $this->assertFalse($totalCost->isKnown());
    }

    public function testMissingVolumeOfTheProductMakesTheTotalCostUnknown(): void
    {
        $comparator = Example::comparator(itemVolumes: new InMemoryItemVolumes([Example::MATERIAL_TYPE_ID => Example::MATERIAL_CUBIC_METERS]));

        $totalCost = $this->totalCostOf(Example::structureC(), $comparator);

        $this->assertFalse($totalCost->isKnown());
    }

    public function testStructureWithoutRouteFromTheWorkshopSystemHasAnUnknownTotalCost(): void
    {
        $totalCost = $this->totalCostOf(Example::riggedStructure('unreachable', Example::UNREACHABLE_SYSTEM_ID));

        $this->assertFalse($totalCost->isKnown());
    }

    public function testStructureWithoutKnownSolarSystemHasAnUnknownTotalCost(): void
    {
        $totalCost = $this->totalCostOf(Example::riggedStructure('system-unknown', null));

        $this->assertFalse($totalCost->isKnown());
    }

    public function testMissingCostIndexOfTheStructureSystemMakesTheTotalCostUnknown(): void
    {
        $comparator = Example::comparator(costIndices: new InMemorySystemCostIndices([
            Example::WORKSHOP_SYSTEM_ID => [ActivityKind::Manufacturing->name => Example::COST_INDEX],
        ]));

        $totalCost = $this->totalCostOf(Example::structureC(), $comparator);

        $this->assertFalse($totalCost->isKnown());
        $this->assertContainsEquals(MissingData::costIndex(), $totalCost->missingData);
    }

    #[Group('issue-780')]
    public function testStructureWithoutFacilityTaxHasAnUnknownTotalCostWithTheFacilityTaxAsOnlyCause(): void
    {
        $totalCost = $this->totalCostOf(Example::unriggedStructure('tax-unset', Example::WORKSHOP_SYSTEM_ID, null));

        $this->assertFalse($totalCost->isKnown());
        $this->assertEquals([MissingData::facilityTax()], $totalCost->missingData);
    }

    #[Group('issue-780')]
    public function testStructureWithFacilityTaxSetToZeroKeepsAKnownTotalCost(): void
    {
        $totalCost = $this->totalCostOf(Example::unriggedStructure('tax-zero', Example::WORKSHOP_SYSTEM_ID, 0.0));

        $this->assertKnownCost(10_800_000.0, $totalCost);
    }

    private function totalCostOf(ProductionStructure $structure, ?StructureTotalCostComparator $comparator = null): Cost
    {
        return ($comparator ?? Example::comparator())->totalCostOf(
            Example::recipe(),
            new Runs(Example::REFERENCE_RUNS),
            Example::PRODUCT_CATEGORY,
            $structure,
            Example::WORKSHOP_SYSTEM_ID,
            false,
        );
    }

    private function assertKnownCost(float $expectedAmount, Cost $cost): void
    {
        $this->assertTrue($cost->isKnown(), 'cost should be known, missing: '.json_encode($cost->missingData));
        $this->assertEqualsWithDelta($expectedAmount, $cost->amount()->amount, self::ISK_TOLERANCE);
    }
}
