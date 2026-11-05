<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\StructureTotalCostComparator;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Runs;
use App\Tests\Unit\Industry\Application\Double\InMemoryItemVolumes;
use App\Tests\Unit\Industry\Application\Double\MarketPricesByQuantity;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StructureTotalCostComparator::class)]
final class StructureTotalCostComparatorGuardsTest extends TestCase
{
    private const int QUANTITY_OF_STRUCTURE_C = 90000;
    private const float UNIT_PRICE = 1.0;

    public function testCopyingRecipeCannotBeComparedAtTotalCost(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('manufacturing and reactions only, got Copying');

        $this->totalCostOfRecipeOf(ActivityKind::Copying);
    }

    public function testInventionRecipeCannotBeComparedAtTotalCost(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('manufacturing and reactions only, got Invention');

        $this->totalCostOfRecipeOf(ActivityKind::Invention);
    }

    public function testMaterialQuantitiesAreHandedToTheMarketAsFloats(): void
    {
        $marketPrices = new MarketPricesByQuantity([self::QUANTITY_OF_STRUCTURE_C => self::UNIT_PRICE]);

        $comparator = new StructureTotalCostComparator(
            $marketPrices,
            Example::adjustedPrices(),
            Example::costIndices(),
            Example::jumpDistances(),
            Example::itemVolumes(),
        );

        $comparator->totalCostOf(
            Example::recipe(),
            new Runs(Example::REFERENCE_RUNS),
            Example::PRODUCT_CATEGORY,
            Example::structureC(),
            Example::WORKSHOP_SYSTEM_ID,
            false,
        );

        $this->assertSame([[Example::MATERIAL_TYPE_ID => 90000.0]], $marketPrices->requestedQuantities);
    }

    public function testMissingVolumesOfTheProductAndOfTheMaterialAreBothListed(): void
    {
        $comparator = new StructureTotalCostComparator(
            Example::marketPrices(),
            Example::adjustedPrices(),
            Example::costIndices(),
            Example::jumpDistances(),
            new InMemoryItemVolumes([]),
        );

        $totalCost = $comparator->totalCostOf(
            Example::recipe(),
            new Runs(Example::REFERENCE_RUNS),
            Example::PRODUCT_CATEGORY,
            Example::structureC(),
            Example::WORKSHOP_SYSTEM_ID,
            false,
        );

        $this->assertFalse($totalCost->isKnown());
        $this->assertEquals(
            [MissingData::itemVolume(Example::PRODUCT_TYPE_ID), MissingData::itemVolume(Example::MATERIAL_TYPE_ID)],
            $totalCost->missingData,
        );
    }

    private function totalCostOfRecipeOf(ActivityKind $activity): void
    {
        Example::comparator()->totalCostOf(
            Example::recipe($activity),
            new Runs(Example::REFERENCE_RUNS),
            Example::PRODUCT_CATEGORY,
            Example::structureC(),
            Example::WORKSHOP_SYSTEM_ID,
            false,
        );
    }
}
