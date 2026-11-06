<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\CostMode;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\JobCost;
use App\Industry\Application\ProductionCost;
use App\Industry\Application\ProductionCostCalculator;
use App\Industry\Application\ProductionCostRequest;
use App\Industry\Application\StructureChoiceStatus;
use App\Industry\Application\StructureSelectionPolicy;
use App\Industry\Application\StructureTotalCostComparator;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Application\Double\InMemoryInventionCatalog;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductionCostCalculator::class)]
#[CoversClass(ProductionCostRequest::class)]
final class ProductionCostCalculatorStructureSelectionPolicyTest extends TestCase
{
    private const float ISK_TOLERANCE = 0.01;

    public function testActivePolicyRunsTheTargetJobInTheStructureCheaperByMoreThanTheMinimumSaving(): void
    {
        $productionCost = self::calculator(Example::comparator())->costOf(self::request(new StructureSelectionPolicy(true, new Isk(100_000.0))));

        $targetJob = self::targetJobOf($productionCost);
        $this->assertSame('C', $targetJob->structureChoice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestByTotalCost, $targetJob->structureChoice->status);
        $this->assertSame(Example::THREE_JUMPS_SYSTEM_ID, $targetJob->solarSystemId);
        $this->assertKnownCost(850_000.0, $targetJob->installCost->total);
        $this->assertKnownCost(9_000_000.0, $productionCost->materialCost);
    }

    public function testInactivePolicyRunsTheTargetJobInTheStructureOfTheFavoriteSystem(): void
    {
        $productionCost = self::calculator(Example::comparator())->costOf(self::request(StructureSelectionPolicy::favoriteSystemOnly()));

        $targetJob = self::targetJobOf($productionCost);
        $this->assertSame('A', $targetJob->structureChoice->structure?->id);
        $this->assertSame(StructureChoiceStatus::BestInFavoriteSystem, $targetJob->structureChoice->status);
        $this->assertSame(Example::WORKSHOP_SYSTEM_ID, $targetJob->solarSystemId);
        $this->assertKnownCost(900_000.0, $targetJob->installCost->total);
        $this->assertKnownCost(10_000_000.0, $productionCost->materialCost);
    }

    public function testActivePolicyWithoutComparatorIsRefused(): void
    {
        $calculator = self::calculator(null);

        $this->expectException(\LogicException::class);

        $calculator->costOf(self::request(new StructureSelectionPolicy(true, new Isk(100_000.0))));
    }

    private static function calculator(?StructureTotalCostComparator $comparator): ProductionCostCalculator
    {
        return new ProductionCostCalculator(
            new InMemoryBlueprintCatalog(Example::recipe()),
            new InMemoryInventionCatalog(),
            Example::marketPrices(),
            Example::adjustedPrices(),
            Example::costIndices(),
            new InMemoryRigCategories(manufacturing: [Example::PRODUCT_TYPE_ID => Example::PRODUCT_CATEGORY]),
            $comparator,
        );
    }

    private static function request(StructureSelectionPolicy $selectionPolicy): ProductionCostRequest
    {
        return new ProductionCostRequest(
            productTypeId: Example::PRODUCT_TYPE_ID,
            runs: new Runs(Example::REFERENCE_RUNS),
            efficiency: new BlueprintEfficiency(new MaterialEfficiency(0), new TimeEfficiency(0)),
            mode: CostMode::Plan,
            structures: [Example::workshopStructureWithNeutralRig(), Example::structureC()],
            favoriteSystems: new FavoriteSystems(Example::WORKSHOP_SYSTEM_ID, null),
            selectionPolicy: $selectionPolicy,
        );
    }

    private static function targetJobOf(ProductionCost $productionCost): JobCost
    {
        $targetJobs = array_values(array_filter(
            $productionCost->jobs,
            static fn (JobCost $job): bool => Example::PRODUCT_TYPE_ID === $job->productTypeId,
        ));
        self::assertCount(1, $targetJobs);

        return $targetJobs[0];
    }

    private function assertKnownCost(float $expectedAmount, Cost $cost): void
    {
        $this->assertTrue($cost->isKnown(), 'cost should be known, missing: '.json_encode($cost->missingData));
        $this->assertEqualsWithDelta($expectedAmount, $cost->amount()->amount, self::ISK_TOLERANCE);
    }
}
