<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Industry\Application\CourierHub;
use App\Industry\Application\DeliveredSourcingPlanner;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\PlannedProduction;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureChoice;
use App\Industry\Application\StructureChoiceStatus;
use App\Industry\Application\WorkTypeClassifier;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Intermediate;
use App\Industry\Domain\Isk;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Service\Industry\Project\DeliveredSourcingPresenter;
use App\Tests\Unit\Industry\Application\Double\FixedJobInstallationCosts;
use App\Tests\Unit\Industry\Application\Double\InMemoryItemVolumes;
use App\Tests\Unit\Industry\Application\Double\InMemoryMarketPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryShipProducts;
use App\Tests\Unit\Industry\Application\Double\InMemoryStructureMarketPrices;
use App\Tests\Unit\Industry\Application\Double\PlannedProductionsByPurchases;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeliveredSourcingPresenter::class)]
#[Group('issue-1340')]
final class DeliveredSourcingPresenterTest extends TestCase
{
    private const int TARGET_PRODUCT = 12005;
    private const int ARMOR_PLATE = 11540;
    private const int LEAF_ALPHA = 16633;
    private const int HUB_SYSTEM_ID = 30_000_772;
    private const int OTHER_SYSTEM_ID = 30_002_187;
    private const int REACTION_SYSTEM_ID = 30_002_659;
    private const float HUB_ISK_PER_CUBIC_METER = 800.0;
    private const float LEAF_UNIT_PRICE = 10.0;

    public function testAPurchaseCheaperThanBuildingReusingTheSurplusGivesTheReusedPerUnitCostAndTheSaving(): void
    {
        $row = $this->rowOfArmorPlate(demand: 100, produced: 120, installation: 100.0, jitaUnitPrice: 9.0, volumePerUnit: 0.000625);

        self::assertSame('jita', $row['recommended']);
        self::assertSame(1_000.0, $row['options']['build']['total']);
        self::assertSame(950.0, $row['options']['jita']['total']);
        self::assertArrayHasKey('perUnitIfSurplusReused', $row['options']['build']);
        self::assertEqualsWithDelta(8.33, $row['options']['build']['perUnitIfSurplusReused'], 0.001);
        self::assertArrayHasKey('savingIfSurplusReused', $row);
        self::assertEqualsWithDelta(116.67, $row['savingIfSurplusReused'], 0.001);
    }

    public function testReusingTheSurplusStillCostsMoreThanThePurchaseGivesAPerUnitCostButNoSaving(): void
    {
        $row = $this->rowOfArmorPlate(demand: 100, produced: 120, installation: 300.0, jitaUnitPrice: 9.0, volumePerUnit: 0.000625);

        self::assertSame(1_200.0, $row['options']['build']['total']);
        self::assertArrayHasKey('perUnitIfSurplusReused', $row['options']['build']);
        self::assertEqualsWithDelta(10.0, $row['options']['build']['perUnitIfSurplusReused'], 0.001);
        self::assertArrayHasKey('savingIfSurplusReused', $row);
        self::assertNull($row['savingIfSurplusReused']);
    }

    public function testWithoutSurplusThereIsNoReusedPerUnitCostAndNoSaving(): void
    {
        $row = $this->rowOfArmorPlate(demand: 100, produced: 100, installation: 100.0, jitaUnitPrice: 9.0, volumePerUnit: 0.000625);

        self::assertSame('jita', $row['recommended']);
        self::assertArrayHasKey('perUnitIfSurplusReused', $row['options']['build']);
        self::assertNull($row['options']['build']['perUnitIfSurplusReused']);
        self::assertArrayHasKey('savingIfSurplusReused', $row);
        self::assertNull($row['savingIfSurplusReused']);
    }

    public function testBuildingRecommendedWithASurplusKeepsTheReusedPerUnitCostButHasNoSaving(): void
    {
        $row = $this->rowOfArmorPlate(demand: 100, produced: 120, installation: 100.0, jitaUnitPrice: 15.0, volumePerUnit: 0.0025);

        self::assertSame('build', $row['recommended']);
        self::assertArrayHasKey('perUnitIfSurplusReused', $row['options']['build']);
        self::assertEqualsWithDelta(8.33, $row['options']['build']['perUnitIfSurplusReused'], 0.001);
        self::assertArrayHasKey('savingIfSurplusReused', $row);
        self::assertNull($row['savingIfSurplusReused']);
    }

    public function testTheReusedPerUnitCostAndTheCoverageExistOnTheJitaOptionAndAreNullOutsideTheirOption(): void
    {
        $row = $this->rowOfArmorPlate(demand: 100, produced: 120, installation: 100.0, jitaUnitPrice: 9.0, volumePerUnit: 0.000625);

        self::assertArrayHasKey('perUnitIfSurplusReused', $row['options']['jita']);
        self::assertNull($row['options']['jita']['perUnitIfSurplusReused']);
        self::assertArrayHasKey('coveragePercent', $row['options']['jita']);
        self::assertNull($row['options']['jita']['coveragePercent']);
        self::assertArrayHasKey('coveragePercent', $row['options']['build']);
        self::assertNull($row['options']['build']['coveragePercent']);
    }

    public function testTheRowCarriesTheVolumeOfOneUnitFromTheItemVolumes(): void
    {
        $row = $this->rowOfArmorPlate(demand: 100, produced: 100, installation: 100.0, jitaUnitPrice: 9.0, volumePerUnit: 12.5);

        self::assertArrayHasKey('volumePerUnit', $row);
        self::assertEqualsWithDelta(12.5, $row['volumePerUnit'], 0.001);
    }

    public function testAnUnknownVolumeGivesANullVolumePerUnitNeverZero(): void
    {
        $row = $this->rowOfArmorPlate(demand: 100, produced: 100, installation: 100.0, jitaUnitPrice: 9.0, volumePerUnit: null);

        self::assertArrayHasKey('volumePerUnit', $row);
        self::assertNull($row['volumePerUnit']);
    }

    public function testTheChoiceChangesWithoutTheImportWhenTheImportMakesBuildWinOverAJitaPriceThatIsCheaper(): void
    {
        $row = $this->rowOfArmorPlate(demand: 100, produced: 100, installation: 100.0, jitaUnitPrice: 9.0, volumePerUnit: 0.0025);

        self::assertSame(1_100.0, $row['options']['jita']['total']);
        self::assertSame('build', $row['recommended']);
        self::assertArrayHasKey('choiceChangesWithoutImport', $row);
        self::assertTrue($row['choiceChangesWithoutImport']);
    }

    public function testTheChoiceStaysTheSameWithoutTheImportWhenBuildWinsBothWays(): void
    {
        $row = $this->rowOfArmorPlate(demand: 100, produced: 100, installation: 100.0, jitaUnitPrice: 15.0, volumePerUnit: 0.0025);

        self::assertSame(1_700.0, $row['options']['jita']['total']);
        self::assertArrayHasKey('choiceChangesWithoutImport', $row);
        self::assertFalse($row['choiceChangesWithoutImport']);
    }

    public function testAJitaOptionWithoutPriceNeverChangesTheChoiceWithoutTheImport(): void
    {
        $row = $this->rowOfArmorPlate(demand: 100, produced: 100, installation: 100.0, jitaUnitPrice: null, volumePerUnit: 0.0025);

        self::assertNull($row['options']['jita']['total']);
        self::assertArrayHasKey('choiceChangesWithoutImport', $row);
        self::assertFalse($row['choiceChangesWithoutImport']);
    }

    public function testAKnownJitaPriceWithAnUnknownImportCostChangesTheChoiceWithoutTheImport(): void
    {
        $row = $this->rowOfArmorPlate(demand: 100, produced: 100, installation: 100.0, jitaUnitPrice: 9.0, volumePerUnit: null);

        self::assertNull($row['options']['jita']['total']);
        self::assertArrayHasKey('choiceChangesWithoutImport', $row);
        self::assertTrue($row['choiceChangesWithoutImport']);
    }

    public function testAJitaOrderBookThatDoesNotCoverTheQuantityGivesItsCoveragePercent(): void
    {
        $row = $this->rowOfArmorPlate(demand: 100, produced: 100, installation: 100.0, jitaUnitPrice: 9.0, volumePerUnit: 0.000625, jitaCoverage: 0.4);

        self::assertArrayHasKey('coveragePercent', $row['options']['jita']);
        self::assertEqualsWithDelta(40.0, $row['options']['jita']['coveragePercent'], 0.001);
    }

    public function testAJitaOrderBookThatCoversTheQuantityHasNoCoveragePercent(): void
    {
        $row = $this->rowOfArmorPlate(demand: 100, produced: 100, installation: 100.0, jitaUnitPrice: 9.0, volumePerUnit: 0.000625, jitaCoverage: 1.0);

        self::assertArrayHasKey('coveragePercent', $row['options']['jita']);
        self::assertNull($row['options']['jita']['coveragePercent']);
    }

    private function rowOfArmorPlate(int $demand, int $produced, float $installation, ?float $jitaUnitPrice, ?float $volumePerUnit, float $jitaCoverage = 1.0): array
    {
        $leafQuantity = 90;
        $targetJob = $this->job(self::TARGET_PRODUCT, 1, [self::ARMOR_PLATE => $demand]);
        $plateJob = $this->job(self::ARMOR_PLATE, 1, [self::LEAF_ALPHA => $leafQuantity]);
        $station = ProductionStructures::raitaru('S1', solarSystemId: self::HUB_SYSTEM_ID);

        $universe = $this->plannedProduction(
            [$targetJob, $plateJob],
            [self::LEAF_ALPHA => $leafQuantity],
            [self::ARMOR_PLATE => new Intermediate(new Quantity($demand), new Quantity(0), new Quantity($produced), new Quantity($produced - $demand), [$plateJob])],
            $station,
        );
        $replans = new PlannedProductionsByPurchases([
            '' => $universe,
            (string) self::ARMOR_PLATE => $this->plannedProduction([$targetJob], [self::ARMOR_PLATE => $demand], [], $station),
        ]);

        $unitPrices = [self::LEAF_ALPHA => self::LEAF_UNIT_PRICE];
        $coverages = [];
        if (null !== $jitaUnitPrice) {
            $unitPrices[self::ARMOR_PLATE] = $jitaUnitPrice;
            $coverages[self::ARMOR_PLATE] = $jitaCoverage;
        }
        $volumes = [self::LEAF_ALPHA => 0.0];
        if (null !== $volumePerUnit) {
            $volumes[self::ARMOR_PLATE] = $volumePerUnit;
        }

        $planner = new DeliveredSourcingPlanner(
            new InMemoryBlueprintCatalog(
                new Recipe(self::TARGET_PRODUCT, ActivityKind::Manufacturing, new OutputPerRun(1), [], new Runs(300), 3600),
                new Recipe(self::ARMOR_PLATE, ActivityKind::Manufacturing, new OutputPerRun(1), [], new Runs(300), 3600),
            ),
            new FixedJobInstallationCosts([
                self::TARGET_PRODUCT => Cost::known(new Isk(1_000.0)),
                self::ARMOR_PLATE => Cost::known(new Isk($installation)),
            ]),
            new InMemoryMarketPrices($unitPrices, $coverages),
            new InMemoryStructureMarketPrices([], null, []),
            new InMemoryItemVolumes($volumes),
            new WorkTypeClassifier(new InMemoryShipProducts()),
        );

        $sourcing = $planner->plan(
            $universe,
            $replans->closure(),
            new FavoriteSystems(self::OTHER_SYSTEM_ID, self::REACTION_SYSTEM_ID, new CourierHub(self::HUB_SYSTEM_ID, self::HUB_ISK_PER_CUBIC_METER, 0.0)),
            null,
            null,
            [self::TARGET_PRODUCT],
        );

        foreach ($sourcing->lines as $line) {
            if (self::ARMOR_PLATE === $line->typeId) {

                return DeliveredSourcingPresenter::rowOf($line, 'Armor Plate');
            }
        }

        self::fail('No line for the armor plate.');
    }

    private function job(int $productTypeId, int $runs, array $materialQuantities): Job
    {
        return new Job(
            $productTypeId,
            ActivityKind::Manufacturing,
            new Runs($runs),
            new MaterialEfficiency(0),
            new TimeEfficiency(0),
            array_map(static fn (int $quantity): Quantity => new Quantity($quantity), $materialQuantities),
        );
    }

    private function plannedProduction(array $jobs, array $leafQuantities, array $intermediates, ProductionStructure $station): PlannedProduction
    {
        $choices = [];
        foreach ($jobs as $job) {
            $choices[PlannedProduction::choiceKey($job->productTypeId, $job->activity)] = new StructureChoice($station, StructureChoiceStatus::Assigned, Multiplier::one());
        }

        return new PlannedProduction(
            new Plan($jobs, array_map(static fn (int $quantity): Quantity => new Quantity($quantity), $leafQuantities), $intermediates),
            $choices,
        );
    }
}
