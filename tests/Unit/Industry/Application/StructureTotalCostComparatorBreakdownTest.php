<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureCostBreakdown;
use App\Industry\Application\StructureTotalCostComparator;
use App\Industry\Domain\Cost;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Runs;
use App\Tests\Unit\Industry\Application\Double\InMemoryMarketPrices;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StructureTotalCostComparator::class)]
#[Group('issue-341')]
final class StructureTotalCostComparatorBreakdownTest extends TestCase
{
    private const float ISK_TOLERANCE = 0.01;

    public function testRiggedStructureThreeJumpsAwayIsBrokenDownIntoMaterialsInstallationAndTransport(): void
    {
        $breakdown = $this->breakdownOf(Example::structureC());

        $this->assertKnownCost(9_000_000.0, $breakdown->materials);
        $this->assertKnownCost(850_000.0, $breakdown->installation);
        $this->assertKnownCost(900_000.0, $breakdown->transport);
        $this->assertEqualsWithDelta(2000.0, $breakdown->transportedCubicMeters, self::ISK_TOLERANCE);
        $this->assertSame(3, $breakdown->jumps);
    }

    public function testStructureOfTheWorkshopSystemHasAKnownTransportOfZeroOverZeroJumps(): void
    {
        $breakdown = $this->breakdownOf(Example::workshopStructureWithoutRig());

        $this->assertKnownCost(10_000_000.0, $breakdown->materials);
        $this->assertKnownCost(900_000.0, $breakdown->installation);
        $this->assertKnownCost(0.0, $breakdown->transport);
        $this->assertSame(0, $breakdown->jumps);
    }

    public function testTotalOfTheBreakdownIsTheTotalCostOfTheLotOneComparator(): void
    {
        $comparator = Example::comparator();
        $structure = Example::structureC();

        $total = $this->breakdownOf($structure, $comparator)->total();

        $this->assertKnownCost(10_750_000.0, $total);
        $this->assertEqualsWithDelta(
            $comparator->totalCostOf(Example::recipe(), new Runs(Example::REFERENCE_RUNS), Example::PRODUCT_CATEGORY, $structure, Example::WORKSHOP_SYSTEM_ID, false)->amount()->amount,
            $total->amount()->amount,
            self::ISK_TOLERANCE,
        );
    }

    public function testWithoutFavoriteSystemTheTransportIsUnknownWhileMaterialsAndInstallationAreKnown(): void
    {
        $breakdown = Example::comparator()->breakdownOf(
            Example::recipe(),
            new Runs(Example::REFERENCE_RUNS),
            Example::PRODUCT_CATEGORY,
            Example::structureC(),
            null,
            false,
        );

        $this->assertKnownCost(9_000_000.0, $breakdown->materials);
        $this->assertKnownCost(850_000.0, $breakdown->installation);
        $this->assertFalse($breakdown->transport->isKnown());
        $this->assertEquals([MissingData::jumpRoute()], $breakdown->transport->missingData);
        $this->assertNull($breakdown->jumps);
        $this->assertFalse($breakdown->total()->isKnown());
    }

    public function testStructureWithoutKnownSolarSystemHasUnknownInstallationAndTransportButKnownMaterials(): void
    {
        $breakdown = $this->breakdownOf(Example::riggedStructure('system-unknown', null));

        $this->assertKnownCost(9_000_000.0, $breakdown->materials);
        $this->assertFalse($breakdown->installation->isKnown());
        $this->assertEquals([MissingData::costIndex()], $breakdown->installation->missingData);
        $this->assertFalse($breakdown->transport->isKnown());
        $this->assertEquals([MissingData::jumpRoute()], $breakdown->transport->missingData);
    }

    public function testMissingMarketPriceMakesTheMaterialsUnknownWhileTheInstallationStaysKnown(): void
    {
        $comparator = Example::comparator(marketPrices: new InMemoryMarketPrices([]));

        $breakdown = $this->breakdownOf(Example::structureC(), $comparator);

        $this->assertFalse($breakdown->materials->isKnown());
        $this->assertEquals([MissingData::marketPrice(Example::MATERIAL_TYPE_ID)], $breakdown->materials->missingData);
        $this->assertKnownCost(850_000.0, $breakdown->installation);
    }

    private function breakdownOf(ProductionStructure $structure, ?StructureTotalCostComparator $comparator = null): StructureCostBreakdown
    {
        return ($comparator ?? Example::comparator())->breakdownOf(
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
