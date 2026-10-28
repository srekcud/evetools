<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\CostMode;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\ProductionCost;
use App\Industry\Application\ProductionCostCalculator;
use App\Industry\Application\ProductionCostRequest;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Application\Double\InMemoryAdjustedPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryInventionCatalog;
use App\Tests\Unit\Industry\Application\Double\InMemoryMarketPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductionCostCalculator::class)]
final class ProductionCostCalculatorSeveralUnitsPerRunTest extends TestCase
{
    private const int AMMUNITION = 5001;
    private const int COMPOUND = 5002;
    private const int MINERAL = 5101;
    private const int GAS = 5102;

    private const int AMMUNITION_OUTPUT_PER_RUN = 100;
    private const int COMPOUND_OUTPUT_PER_RUN = 40;
    private const int BPO_MAX_PRODUCTION_LIMIT = 300;

    private const int MANUFACTURING_SYSTEM_ID = 30000142;
    private const int REACTION_SYSTEM_ID = 30002510;

    private const float FLOAT_TOLERANCE = 1e-9;

    public function testPlanCostPerUnitOfATargetYieldingAHundredUnitsPerRunDividesByRunsTimesOutputPerRun(): void
    {
        $productionCost = self::costOf(CostMode::Plan);

        $this->assertSame(300, $productionCost->producedQuantity->value);
        $this->assertEqualsWithDelta(1462.5, $productionCost->materialCost->amount()->amount, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(242.175, $productionCost->installCost->amount()->amount, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(1704.675, $productionCost->totalCost->amount()->amount, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(5.68225, $productionCost->costPerUnit->amount()->amount, self::FLOAT_TOLERANCE);
    }

    public function testMarginalCostPerUnitOfATargetYieldingAHundredUnitsPerRunDividesByRunsTimesOutputPerRun(): void
    {
        $productionCost = self::costOf(CostMode::MarginalCost);

        $this->assertSame(300, $productionCost->producedQuantity->value);
        $this->assertEqualsWithDelta(1162.5, $productionCost->materialCost->amount()->amount, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(228.1125, $productionCost->installCost->amount()->amount, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(1390.6125, $productionCost->totalCost->amount()->amount, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(4.635375, $productionCost->costPerUnit->amount()->amount, self::FLOAT_TOLERANCE);
    }

    private static function costOf(CostMode $mode): ProductionCost
    {
        $calculator = new ProductionCostCalculator(
            new InMemoryBlueprintCatalog(
                self::recipe(self::AMMUNITION, ActivityKind::Manufacturing, self::AMMUNITION_OUTPUT_PER_RUN, [self::COMPOUND => 30, self::MINERAL => 7]),
                self::recipe(self::COMPOUND, ActivityKind::Reaction, self::COMPOUND_OUTPUT_PER_RUN, [self::GAS => 5]),
            ),
            new InMemoryInventionCatalog(),
            new InMemoryMarketPrices([self::MINERAL => 12.5, self::GAS => 80.0]),
            new InMemoryAdjustedPrices([self::COMPOUND => 20.0, self::MINERAL => 10.0, self::GAS => 60.0]),
            new InMemorySystemCostIndices([
                self::MANUFACTURING_SYSTEM_ID => [ActivityKind::Manufacturing->name => 0.05],
                self::REACTION_SYSTEM_ID => [ActivityKind::Reaction->name => 0.02],
            ]),
            new InMemoryRigCategories(),
        );

        return $calculator->costOf(new ProductionCostRequest(
            productTypeId: self::AMMUNITION,
            runs: new Runs(3),
            efficiency: new BlueprintEfficiency(new MaterialEfficiency(0), new TimeEfficiency(0)),
            mode: $mode,
            structures: [],
            favoriteSystems: new FavoriteSystems(self::MANUFACTURING_SYSTEM_ID, self::REACTION_SYSTEM_ID),
        ));
    }

    private static function recipe(int $productTypeId, ActivityKind $activity, int $outputPerRun, array $baseQuantitiesPerRun): Recipe
    {
        $materials = [];
        foreach ($baseQuantitiesPerRun as $materialTypeId => $baseQuantityPerRun) {
            $materials[] = new RecipeMaterial($materialTypeId, new Quantity($baseQuantityPerRun));
        }

        return new Recipe(
            productTypeId: $productTypeId,
            activity: $activity,
            outputPerRun: new OutputPerRun($outputPerRun),
            materials: $materials,
            maxProductionLimit: new Runs(self::BPO_MAX_PRODUCTION_LIMIT),
            baseTimeSeconds: 600,
        );
    }
}
