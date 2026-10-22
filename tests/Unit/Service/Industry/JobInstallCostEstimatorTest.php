<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Service\Industry\JobInstallCostEstimator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobInstallCostEstimator::class)]
class JobInstallCostEstimatorTest extends TestCase
{
    private const NITROGEN_FUEL_BLOCK_MATERIALS = [
        44 => 4,
        3683 => 22,
        3689 => 4,
        9832 => 9,
        9848 => 1,
        16272 => 170,
        16273 => 350,
        16275 => 20,
        17888 => 450,
    ];
    private const ADJUSTED_PRICE_DIVISOR = 1_000.0;
    private const NITROGEN_FUEL_BLOCK_EIV = 17_031.184;
    private const MORPHITE = 11399;
    private const MORPHITE_ADJUSTED_PRICE = 10_000_000.0;
    private const ROBOTICS = 9848;
    private const COOLANT = 3683;
    private const MANUFACTURING_COST_INDEX = 0.05;
    private const REACTION_COST_INDEX = 0.04;
    private const INVENTION_COST_INDEX = 0.08;
    private const FACILITY_TAX_PERCENT = 10.0;

    private JobInstallCostEstimator $estimator;

    protected function setUp(): void
    {
        $this->estimator = new JobInstallCostEstimator();
    }

    public function testFixtureEivOfNitrogenFuelBlockIsSeventeenThousandThirtyOneAndChange(): void
    {
        $eiv = 0.0;
        foreach ($this->nitrogenFuelBlockMaterials() as $material) {
            $eiv += $material['quantity'] * $this->nitrogenFuelBlockAdjustedPrices()[$material['materialTypeId']];
        }

        $this->assertEqualsWithDelta(self::NITROGEN_FUEL_BLOCK_EIV, $eiv, 1e-6);
    }

    public function testManufacturingInstallCostAddsCostIndexFacilityTaxAndSccSurchargeOnTheEiv(): void
    {
        $estimate = $this->estimator->estimate(
            $this->nitrogenFuelBlockMaterials(),
            $this->nitrogenFuelBlockAdjustedPrices(),
            self::MANUFACTURING_COST_INDEX,
            1,
            'manufacturing',
            self::FACILITY_TAX_PERCENT,
        );

        $this->assertEqualsWithDelta(3235.92496, $estimate['cost'], 1e-6);
        $this->assertNull($estimate['unknownReason']);
        $this->assertSame([], $estimate['missingAdjustedPriceTypeIds']);
    }

    public function testNullFacilityTaxStillAddsTheSccSurcharge(): void
    {
        $estimate = $this->estimator->estimate(
            $this->nitrogenFuelBlockMaterials(),
            $this->nitrogenFuelBlockAdjustedPrices(),
            self::MANUFACTURING_COST_INDEX,
            1,
            'manufacturing',
            null,
        );

        $this->assertEqualsWithDelta(1532.80656, $estimate['cost'], 1e-6);
        $this->assertNull($estimate['unknownReason']);
        $this->assertSame([], $estimate['missingAdjustedPriceTypeIds']);
    }

    public function testExplicitZeroFacilityTaxIsTheSameAsNullFacilityTax(): void
    {
        $estimate = $this->estimator->estimate(
            $this->nitrogenFuelBlockMaterials(),
            $this->nitrogenFuelBlockAdjustedPrices(),
            self::MANUFACTURING_COST_INDEX,
            1,
            'manufacturing',
            0.0,
        );

        $this->assertEqualsWithDelta(1532.80656, $estimate['cost'], 1e-6);
        $this->assertNull($estimate['unknownReason']);
    }

    public function testReactionInstallCostUsesTheGivenCostIndex(): void
    {
        $estimate = $this->estimator->estimate(
            $this->nitrogenFuelBlockMaterials(),
            $this->nitrogenFuelBlockAdjustedPrices(),
            self::REACTION_COST_INDEX,
            1,
            'reaction',
            self::FACILITY_TAX_PERCENT,
        );

        $this->assertEqualsWithDelta(3065.61312, $estimate['cost'], 1e-6);
        $this->assertNull($estimate['unknownReason']);
        $this->assertSame([], $estimate['missingAdjustedPriceTypeIds']);
    }

    public function testInstallCostOfSeveralRunsMultipliesTheEivByTheRuns(): void
    {
        $estimate = $this->estimator->estimate(
            $this->nitrogenFuelBlockMaterials(),
            $this->nitrogenFuelBlockAdjustedPrices(),
            self::MANUFACTURING_COST_INDEX,
            3,
            'manufacturing',
            null,
        );

        $this->assertEqualsWithDelta(4598.41968, $estimate['cost'], 1e-6);
        $this->assertNull($estimate['unknownReason']);
    }

    public function testInventionOfOneMaterialOfTenMillionAdjustedPriceMatchesTheReferenceVector(): void
    {
        $estimate = $this->estimator->estimate(
            [['materialTypeId' => self::MORPHITE, 'quantity' => 1]],
            [self::MORPHITE => self::MORPHITE_ADJUSTED_PRICE],
            self::INVENTION_COST_INDEX,
            1,
            'invention',
            5.0,
        );

        $this->assertEqualsWithDelta(34_000.0, $estimate['cost'], 1e-6);
        $this->assertNull($estimate['unknownReason']);
        $this->assertSame([], $estimate['missingAdjustedPriceTypeIds']);
    }

    public function testMissingCostIndexLeavesTheInstallCostUnknown(): void
    {
        $estimate = $this->estimator->estimate(
            $this->nitrogenFuelBlockMaterials(),
            $this->nitrogenFuelBlockAdjustedPrices(),
            null,
            1,
            'manufacturing',
            self::FACILITY_TAX_PERCENT,
        );

        $this->assertNull($estimate['cost']);
        $this->assertSame('missing_cost_index', $estimate['unknownReason']);
        $this->assertSame([], $estimate['missingAdjustedPriceTypeIds']);
    }

    public function testMissingAdjustedPriceLeavesTheInstallCostUnknownAndListsTheTypeId(): void
    {
        $adjustedPrices = $this->nitrogenFuelBlockAdjustedPrices();
        unset($adjustedPrices[self::ROBOTICS]);

        $estimate = $this->estimator->estimate(
            $this->nitrogenFuelBlockMaterials(),
            $adjustedPrices,
            self::MANUFACTURING_COST_INDEX,
            1,
            'manufacturing',
            self::FACILITY_TAX_PERCENT,
        );

        $this->assertNull($estimate['cost']);
        $this->assertSame('missing_adjusted_price', $estimate['unknownReason']);
        $this->assertSame([self::ROBOTICS], $estimate['missingAdjustedPriceTypeIds']);
    }

    public function testMissingCostIndexTakesPrecedenceOverMissingAdjustedPriceAndTheTypeIdsAreListedOnceInAscendingOrder(): void
    {
        $adjustedPrices = $this->nitrogenFuelBlockAdjustedPrices();
        unset($adjustedPrices[self::ROBOTICS], $adjustedPrices[self::COOLANT]);
        $materialsWithRoboticsTwice = [
            ...array_reverse($this->nitrogenFuelBlockMaterials()),
            ['materialTypeId' => self::ROBOTICS, 'quantity' => 2],
        ];

        $estimate = $this->estimator->estimate(
            $materialsWithRoboticsTwice,
            $adjustedPrices,
            null,
            1,
            'manufacturing',
            self::FACILITY_TAX_PERCENT,
        );

        $this->assertNull($estimate['cost']);
        $this->assertSame('missing_cost_index', $estimate['unknownReason']);
        $this->assertSame([self::COOLANT, self::ROBOTICS], $estimate['missingAdjustedPriceTypeIds']);
    }

    public function testBlueprintWithoutMaterialHasAnInstallCostOfZero(): void
    {
        $estimate = $this->estimator->estimate([], [], self::MANUFACTURING_COST_INDEX, 1, 'manufacturing', self::FACILITY_TAX_PERCENT);

        $this->assertSame(0.0, $estimate['cost']);
        $this->assertNull($estimate['unknownReason']);
        $this->assertSame([], $estimate['missingAdjustedPriceTypeIds']);
    }

    private function nitrogenFuelBlockMaterials(): array
    {
        $materials = [];
        foreach (self::NITROGEN_FUEL_BLOCK_MATERIALS as $typeId => $quantity) {
            $materials[] = ['materialTypeId' => $typeId, 'quantity' => $quantity];
        }

        return $materials;
    }

    private function nitrogenFuelBlockAdjustedPrices(): array
    {
        $adjustedPrices = [];
        foreach (array_keys(self::NITROGEN_FUEL_BLOCK_MATERIALS) as $typeId) {
            $adjustedPrices[$typeId] = $typeId / self::ADJUSTED_PRICE_DIVISOR;
        }

        return $adjustedPrices;
    }
}
