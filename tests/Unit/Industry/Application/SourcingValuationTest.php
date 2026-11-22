<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\CourierHub;
use App\Industry\Application\DeliveredSourcingOption;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\SourcingChoice;
use App\Industry\Application\SourcingSide;
use App\Industry\Application\SourcingUniverse;
use App\Industry\Application\SourcingValuation;
use App\Industry\Application\WeightedPrice;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\OrderBookCoverage;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Application\Double\FixedJobInstallationCosts;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourcingValuation::class)]
final class SourcingValuationTest extends TestCase
{
    private const int HUB_SYSTEM_ID = 30_000_772;
    private const int OTHER_SYSTEM_ID = 30_002_187;
    private const int MARKET_SYSTEM_ID = 30_001_000;
    private const int MARKET_STRUCTURE_ID = 1_040_000_000_001;

    private const float HUB_ISK_PER_CUBIC_METER = 800.0;
    private const float BRIDGE_ISK_PER_CUBIC_METER = 10.0;

    private const int TRITANIUM = 34;
    private const int ISOGEN = 342;
    private const int PRODUCT_ALPHA = 20001;
    private const int PRODUCT_BETA = 20002;
    private const int STRUCTURE_SOURCED_PRODUCT = 20011;
    private const int BUILT_SOURCED_PRODUCT = 20012;
    private const int STRUCTURE_PRICELESS_PRODUCT = 20013;
    private const int STRUCTURE_SOURCED_LEAF = 30010;
    private const int BUILT_COMPONENT = 30020;
    private const int COMPONENT_LEAF = 30021;
    private const int STRUCTURE_PRICELESS_LEAF = 30030;
    private const int LEAF_ALPHA = 31001;
    private const int LEAF_BETA = 31002;
    private const int LEAF_GAMMA = 31003;

    public function testADecisionIsComputedOnceWhateverTheNumberOfTimesItIsAskedFor(): void
    {
        $installationCosts = new FixedJobInstallationCosts([self::PRODUCT_ALPHA => Cost::known(new Isk(500.0))]);
        $station = $this->station('A', self::HUB_SYSTEM_ID);
        $valuation = $this->valuation(
            $this->universe(
                jobs: [$this->job(self::PRODUCT_ALPHA, 2, [self::LEAF_ALPHA => 4])],
                builders: [self::PRODUCT_ALPHA => $station],
                consumption: [self::LEAF_ALPHA => ['A' => 4], self::PRODUCT_ALPHA => ['A' => 1]],
                stations: ['A' => $station],
                builtTypeIds: [self::PRODUCT_ALPHA],
            ),
            installationCosts: $installationCosts,
            jitaUnitPrices: [self::LEAF_ALPHA => 10.0],
            volumes: [self::LEAF_ALPHA => 1.0, self::PRODUCT_ALPHA => 1.0],
        );

        $first = $valuation->decisionOf(self::PRODUCT_ALPHA);
        $second = $valuation->decisionOf(self::PRODUCT_ALPHA);

        self::assertSame($first, $second);
        self::assertSame([['productTypeId' => self::PRODUCT_ALPHA, 'runs' => 2, 'structureId' => 'A']], $installationCosts->calls);
    }

    public function testABuiltItemWhoseJobHasNoRecipeFailsExplicitly(): void
    {
        $station = $this->station('A', self::HUB_SYSTEM_ID);
        $valuation = $this->valuation(
            $this->universe(
                jobs: [$this->job(self::PRODUCT_ALPHA, 2, [self::LEAF_ALPHA => 4])],
                builders: [self::PRODUCT_ALPHA => $station],
                consumption: [self::LEAF_ALPHA => ['A' => 4], self::PRODUCT_ALPHA => ['A' => 1]],
                stations: ['A' => $station],
                builtTypeIds: [self::PRODUCT_ALPHA],
            ),
            recipedProductTypeIds: [],
            jitaUnitPrices: [self::LEAF_ALPHA => 10.0],
            volumes: [self::LEAF_ALPHA => 1.0],
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Job of product 20001 has no recipe.');

        $valuation->decisionOf(self::PRODUCT_ALPHA);
    }

    public function testAnExplicitJitaMarketIsFollowedEvenWhenTheCapitalMarketIsCheaper(): void
    {
        $valuation = $this->valuation(
            $this->universe([], [], [], [], []),
            markets: [self::LEAF_ALPHA => SourcingSide::Jita],
        );
        $options = [
            SourcingChoice::Jita->value => $this->eligibleOption(SourcingChoice::Jita, 1000.0),
            SourcingChoice::Structure->value => $this->eligibleOption(SourcingChoice::Structure, 800.0),
        ];

        self::assertSame(SourcingChoice::Jita, $valuation->buySideOf(self::LEAF_ALPHA, $options));
        self::assertSame(SourcingChoice::Structure, $valuation->buySideOf(self::LEAF_BETA, $options));
    }

    public function testTheUnknownCostOfAnItemWhoseOptionsAreAllKnownBlamesItsMarketPrice(): void
    {
        $cost = SourcingValuation::unknownCostOf(self::LEAF_ALPHA, [
            SourcingChoice::Jita->value => $this->eligibleOption(SourcingChoice::Jita, 1000.0),
        ]);

        self::assertFalse($cost->isKnown());
        self::assertEquals([MissingData::marketPrice(self::LEAF_ALPHA)], $cost->missingData);
    }

    public function testACapitalMarketHoldingExactlyTheDemandIsEligible(): void
    {
        $option = $this->structureOptionOfLeaves()->decisionOf(self::LEAF_ALPHA)->options[SourcingChoice::Structure->value];

        self::assertTrue($option->eligible);
        self::assertSame(100, $option->availableQuantity);
        self::assertSame('1040000000001', $option->structureId);
        self::assertSame(1300.0, $option->total->amount()->amount);
    }

    public function testACapitalMarketHoldingOneUnitLessThanTheDemandIsNotEligible(): void
    {
        $option = $this->structureOptionOfLeaves()->decisionOf(self::LEAF_BETA)->options[SourcingChoice::Structure->value];

        self::assertFalse($option->eligible);
        self::assertSame(249, $option->availableQuantity);
        self::assertTrue($option->total->isKnown());
    }

    public function testACapitalMarketWithoutPriceIsNotEligibleWhateverItsStock(): void
    {
        $option = $this->structureOptionOfLeaves()->decisionOf(self::LEAF_GAMMA)->options[SourcingChoice::Structure->value];

        self::assertFalse($option->eligible);
        self::assertSame(500, $option->availableQuantity);
        self::assertEquals([MissingData::structurePrice(self::LEAF_GAMMA)], $option->total->missingData);
    }

    public function testThePurchaseOptionsCarryNoInstallationCost(): void
    {
        $options = $this->structureOptionOfLeaves()->decisionOf(self::LEAF_ALPHA)->options;

        self::assertSame(0.0, $options[SourcingChoice::Jita->value]->installation->amount()->amount);
        self::assertSame(0.0, $options[SourcingChoice::Structure->value]->installation->amount()->amount);
    }

    public function testABuiltItemWithoutStructureIsBuiltAtNoStructure(): void
    {
        $option = $this->unplacedBuilderValuation()->decisionOf(self::PRODUCT_ALPHA)->options[SourcingChoice::Build->value];

        self::assertNull($option->structureId);
    }

    public function testABuiltItemWithoutStructureHasAnUnknownTransportBecauseTheJumpRouteIsMissing(): void
    {
        $option = $this->unplacedBuilderValuation()->decisionOf(self::PRODUCT_ALPHA)->options[SourcingChoice::Build->value];

        self::assertFalse($option->transport->isKnown());
        self::assertEquals([MissingData::jumpRoute()], $option->transport->missingData);
    }

    public function testTheInputsOfABuiltItemWithoutStructureAreUnknownBecauseTheirDeliveryHasNoJumpRoute(): void
    {
        $option = $this->unplacedBuilderValuation()->decisionOf(self::PRODUCT_ALPHA)->options[SourcingChoice::Build->value];

        self::assertFalse($option->price->isKnown());
        self::assertEquals([MissingData::jumpRoute()], $option->price->missingData);
        self::assertSame(500.0, $option->installation->amount()->amount);
    }

    public function testABuiltItemConsumedAtItsOwnBuilderCostsNoTransportEvenWithoutAKnownVolume(): void
    {
        $station = $this->station('A', self::HUB_SYSTEM_ID);
        $valuation = $this->valuation(
            $this->universe(
                jobs: [$this->job(self::PRODUCT_ALPHA, 1, [self::LEAF_ALPHA => 3])],
                builders: [self::PRODUCT_ALPHA => $station],
                consumption: [self::LEAF_ALPHA => ['A' => 3], self::PRODUCT_ALPHA => ['A' => 2]],
                stations: ['A' => $station],
                builtTypeIds: [self::PRODUCT_ALPHA],
            ),
            jitaUnitPrices: [self::LEAF_ALPHA => 10.0],
            volumes: [self::LEAF_ALPHA => 1.0],
        );

        $transport = $valuation->decisionOf(self::PRODUCT_ALPHA)->options[SourcingChoice::Build->value]->transport;

        self::assertSame(0.0, $transport->amount()->amount);
    }

    public function testABuiltItemConsumedByAJobWithoutStructureHasAnUnknownTransportBecauseTheJumpRouteIsMissing(): void
    {
        $station = $this->station('A', self::HUB_SYSTEM_ID);
        $valuation = $this->valuation(
            $this->universe(
                jobs: [$this->job(self::PRODUCT_ALPHA, 1, [self::LEAF_ALPHA => 3])],
                builders: [self::PRODUCT_ALPHA => $station],
                consumption: [self::LEAF_ALPHA => ['A' => 3], self::PRODUCT_ALPHA => [SourcingUniverse::UNKNOWN_STATION => 2]],
                stations: ['A' => $station, SourcingUniverse::UNKNOWN_STATION => null],
                builtTypeIds: [self::PRODUCT_ALPHA],
            ),
            jitaUnitPrices: [self::LEAF_ALPHA => 10.0],
            volumes: [self::LEAF_ALPHA => 1.0, self::PRODUCT_ALPHA => 4.0],
        );

        $transport = $valuation->decisionOf(self::PRODUCT_ALPHA)->options[SourcingChoice::Build->value]->transport;

        self::assertFalse($transport->isKnown());
        self::assertEquals([MissingData::jumpRoute()], $transport->missingData);
    }

    public function testTheCostOfAMaterialIsComputedForTheStructureThatBuildsWithIt(): void
    {
        $firstBuilder = $this->station('A', self::HUB_SYSTEM_ID);
        $secondBuilder = $this->station('B', self::OTHER_SYSTEM_ID);
        $valuation = $this->valuation(
            $this->universe(
                jobs: [
                    $this->job(self::PRODUCT_ALPHA, 1, [self::TRITANIUM => 5]),
                    $this->job(self::PRODUCT_BETA, 1, [self::TRITANIUM => 7]),
                ],
                builders: [self::PRODUCT_ALPHA => $firstBuilder, self::PRODUCT_BETA => $secondBuilder],
                consumption: [self::TRITANIUM => ['A' => 5, 'B' => 7], self::PRODUCT_ALPHA => ['A' => 1], self::PRODUCT_BETA => ['B' => 1]],
                stations: ['A' => $firstBuilder, 'B' => $secondBuilder],
                builtTypeIds: [self::PRODUCT_ALPHA, self::PRODUCT_BETA],
            ),
            installationCosts: new FixedJobInstallationCosts([self::PRODUCT_ALPHA => Cost::known(new Isk(0.0)), self::PRODUCT_BETA => Cost::known(new Isk(0.0))]),
            jitaUnitPrices: [self::TRITANIUM => 100.0],
            volumes: [self::TRITANIUM => 3.0, self::PRODUCT_ALPHA => 1.0, self::PRODUCT_BETA => 1.0],
        );

        $inputsAtTheHubSystem = $valuation->decisionOf(self::PRODUCT_ALPHA)->options[SourcingChoice::Build->value]->price;
        $inputsElsewhere = $valuation->decisionOf(self::PRODUCT_BETA)->options[SourcingChoice::Build->value]->price;

        self::assertSame(12_500.0, $inputsAtTheHubSystem->amount()->amount);
        self::assertSame(17_710.0, $inputsElsewhere->amount()->amount);
    }

    public function testTheCostsOfTwoMaterialsAtTwoStructuresNeverShareACacheEntry(): void
    {
        $firstBuilder = $this->station('22', self::HUB_SYSTEM_ID);
        $secondBuilder = $this->station('2', self::HUB_SYSTEM_ID);
        $valuation = $this->valuation(
            $this->universe(
                jobs: [
                    $this->job(self::PRODUCT_ALPHA, 1, [self::TRITANIUM => 1]),
                    $this->job(self::PRODUCT_BETA, 1, [self::ISOGEN => 1]),
                ],
                builders: [self::PRODUCT_ALPHA => $firstBuilder, self::PRODUCT_BETA => $secondBuilder],
                consumption: [self::TRITANIUM => ['22' => 1], self::ISOGEN => ['2' => 1], self::PRODUCT_ALPHA => ['22' => 1], self::PRODUCT_BETA => ['2' => 1]],
                stations: ['22' => $firstBuilder, '2' => $secondBuilder],
                builtTypeIds: [self::PRODUCT_ALPHA, self::PRODUCT_BETA],
            ),
            installationCosts: new FixedJobInstallationCosts([self::PRODUCT_ALPHA => Cost::known(new Isk(0.0)), self::PRODUCT_BETA => Cost::known(new Isk(0.0))]),
            jitaUnitPrices: [self::TRITANIUM => 100.0, self::ISOGEN => 700.0],
            volumes: [self::TRITANIUM => 1.0, self::ISOGEN => 1.0, self::PRODUCT_ALPHA => 1.0, self::PRODUCT_BETA => 1.0],
        );

        $inputsOfTritanium = $valuation->decisionOf(self::PRODUCT_ALPHA)->options[SourcingChoice::Build->value]->price;
        $inputsOfIsogen = $valuation->decisionOf(self::PRODUCT_BETA)->options[SourcingChoice::Build->value]->price;

        self::assertSame(900.0, $inputsOfTritanium->amount()->amount);
        self::assertSame(1_500.0, $inputsOfIsogen->amount()->amount);
    }

    public function testAMaterialBoughtFromTheCapitalMarketIsCostedAtItsUnitPriceAndTheBridgeOfOneUnit(): void
    {
        $inputs = $this->unitCostValuation()->decisionOf(self::STRUCTURE_SOURCED_PRODUCT)->options[SourcingChoice::Build->value]->price;

        self::assertSame(440.0, round($inputs->amount()->amount, 2));
    }

    public function testAMaterialBuiltAtAnotherStructureIsCostedAtItsBuildShareOfOneUnitAndTheBridgeOfOneUnit(): void
    {
        $inputs = $this->unitCostValuation()->decisionOf(self::BUILT_SOURCED_PRODUCT)->options[SourcingChoice::Build->value]->price;

        self::assertSame(42_380.0, round($inputs->amount()->amount, 2));
    }

    public function testAMaterialFollowingAnExplicitCapitalMarketWithoutPriceMakesTheInputsUnknown(): void
    {
        $inputs = $this->unitCostValuation()->decisionOf(self::STRUCTURE_PRICELESS_PRODUCT)->options[SourcingChoice::Build->value]->price;

        self::assertFalse($inputs->isKnown());
        self::assertEquals([MissingData::structurePrice(self::STRUCTURE_PRICELESS_LEAF)], $inputs->missingData);
    }

    private function unitCostValuation(): SourcingValuation
    {
        $consumerStation = $this->station('A', self::HUB_SYSTEM_ID);
        $componentBuilder = $this->station('SB', self::OTHER_SYSTEM_ID);

        return $this->valuation(
            $this->universe(
                jobs: [
                    $this->job(self::STRUCTURE_SOURCED_PRODUCT, 1, [self::STRUCTURE_SOURCED_LEAF => 4]),
                    $this->job(self::BUILT_SOURCED_PRODUCT, 1, [self::BUILT_COMPONENT => 6]),
                    $this->job(self::BUILT_COMPONENT, 2, [self::COMPONENT_LEAF => 50]),
                    $this->job(self::STRUCTURE_PRICELESS_PRODUCT, 1, [self::STRUCTURE_PRICELESS_LEAF => 3]),
                ],
                builders: [
                    self::STRUCTURE_SOURCED_PRODUCT => $consumerStation,
                    self::BUILT_SOURCED_PRODUCT => $consumerStation,
                    self::BUILT_COMPONENT => $componentBuilder,
                    self::STRUCTURE_PRICELESS_PRODUCT => $consumerStation,
                ],
                consumption: [
                    self::STRUCTURE_SOURCED_LEAF => ['A' => 4],
                    self::BUILT_COMPONENT => ['A' => 6],
                    self::COMPONENT_LEAF => ['SB' => 50],
                    self::STRUCTURE_PRICELESS_LEAF => ['A' => 3],
                    self::STRUCTURE_SOURCED_PRODUCT => ['A' => 1],
                    self::BUILT_SOURCED_PRODUCT => ['A' => 1],
                    self::STRUCTURE_PRICELESS_PRODUCT => ['A' => 1],
                ],
                stations: ['A' => $consumerStation, 'SB' => $componentBuilder],
                builtTypeIds: [self::STRUCTURE_SOURCED_PRODUCT, self::BUILT_SOURCED_PRODUCT, self::BUILT_COMPONENT, self::STRUCTURE_PRICELESS_PRODUCT],
            ),
            installationCosts: new FixedJobInstallationCosts([
                self::STRUCTURE_SOURCED_PRODUCT => Cost::known(new Isk(100.0)),
                self::BUILT_SOURCED_PRODUCT => Cost::known(new Isk(100.0)),
                self::BUILT_COMPONENT => Cost::known(new Isk(1_200.0)),
                self::STRUCTURE_PRICELESS_PRODUCT => Cost::known(new Isk(100.0)),
            ]),
            jitaUnitPrices: [
                self::STRUCTURE_SOURCED_LEAF => 120.0,
                self::BUILT_COMPONENT => 5_000.0,
                self::COMPONENT_LEAF => 10.0,
                self::STRUCTURE_PRICELESS_LEAF => 50.0,
            ],
            volumes: [
                self::STRUCTURE_SOURCED_LEAF => 2.0,
                self::BUILT_COMPONENT => 3.0,
                self::COMPONENT_LEAF => 1.0,
                self::STRUCTURE_PRICELESS_LEAF => 1.0,
                self::STRUCTURE_SOURCED_PRODUCT => 1.0,
                self::BUILT_SOURCED_PRODUCT => 1.0,
                self::STRUCTURE_PRICELESS_PRODUCT => 1.0,
            ],
            structureUnitPrices: [self::STRUCTURE_SOURCED_LEAF => 90.0],
            structureSellVolumes: [self::STRUCTURE_SOURCED_LEAF => 1_000],
            marketStructureId: self::MARKET_STRUCTURE_ID,
            markets: [self::STRUCTURE_PRICELESS_LEAF => SourcingSide::Structure],
        );
    }

    private function structureOptionOfLeaves(): SourcingValuation
    {
        $station = $this->station('A', self::HUB_SYSTEM_ID);

        return $this->valuation(
            $this->universe(
                jobs: [],
                builders: [],
                consumption: [self::LEAF_ALPHA => ['A' => 100], self::LEAF_BETA => ['A' => 250], self::LEAF_GAMMA => ['A' => 40]],
                stations: ['A' => $station],
                builtTypeIds: [],
            ),
            jitaUnitPrices: [self::LEAF_ALPHA => 10.0, self::LEAF_BETA => 15.0, self::LEAF_GAMMA => 22.0],
            volumes: [self::LEAF_ALPHA => 0.5, self::LEAF_BETA => 0.5, self::LEAF_GAMMA => 0.5],
            structureUnitPrices: [self::LEAF_ALPHA => 8.0, self::LEAF_BETA => 12.0],
            structureSellVolumes: [self::LEAF_ALPHA => 100, self::LEAF_BETA => 249, self::LEAF_GAMMA => 500],
            marketStructureId: self::MARKET_STRUCTURE_ID,
        );
    }

    private function unplacedBuilderValuation(): SourcingValuation
    {
        $consumerStation = $this->station('A', self::HUB_SYSTEM_ID);

        return $this->valuation(
            $this->universe(
                jobs: [$this->job(self::PRODUCT_ALPHA, 1, [self::LEAF_ALPHA => 3, self::LEAF_BETA => 5])],
                builders: [self::PRODUCT_ALPHA => null],
                consumption: [
                    self::LEAF_ALPHA => [SourcingUniverse::UNKNOWN_STATION => 3],
                    self::LEAF_BETA => [SourcingUniverse::UNKNOWN_STATION => 5],
                    self::PRODUCT_ALPHA => ['A' => 1],
                ],
                stations: ['A' => $consumerStation, SourcingUniverse::UNKNOWN_STATION => null],
                builtTypeIds: [self::PRODUCT_ALPHA],
            ),
            installationCosts: new FixedJobInstallationCosts([self::PRODUCT_ALPHA => Cost::known(new Isk(500.0))]),
            jitaUnitPrices: [self::LEAF_ALPHA => 10.0, self::LEAF_BETA => 14.0],
            volumes: [self::LEAF_ALPHA => 2.0, self::LEAF_BETA => 1.0, self::PRODUCT_ALPHA => 4.0],
            structureUnitPrices: [self::LEAF_BETA => 8.0],
            marketStructureId: self::MARKET_STRUCTURE_ID,
            markets: [self::LEAF_ALPHA => SourcingSide::Jita, self::LEAF_BETA => SourcingSide::Structure],
        );
    }

    private function station(string $id, int $solarSystemId): ProductionStructure
    {
        return ProductionStructures::raitaru($id, solarSystemId: $solarSystemId);
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

    private function universe(array $jobs, array $builders, array $consumption, array $stations, array $builtTypeIds): SourcingUniverse
    {
        $jobsByProduct = [];
        foreach ($jobs as $job) {
            $jobsByProduct[$job->productTypeId][] = $job;
        }

        return new SourcingUniverse($jobsByProduct, $builders, $consumption, $stations, $builtTypeIds, [], [], []);
    }

    private function valuation(
        SourcingUniverse $universe,
        ?FixedJobInstallationCosts $installationCosts = null,
        ?array $recipedProductTypeIds = null,
        array $jitaUnitPrices = [],
        array $volumes = [],
        array $structureUnitPrices = [],
        array $structureSellVolumes = [],
        ?int $marketStructureId = null,
        array $markets = [],
    ): SourcingValuation {
        $recipes = [];
        foreach ($recipedProductTypeIds ?? array_keys($universe->jobsByProduct) as $productTypeId) {
            $recipes[] = new Recipe($productTypeId, ActivityKind::Manufacturing, new OutputPerRun(1), [], new Runs(300), 3600);
        }

        return new SourcingValuation(
            $universe,
            new InMemoryBlueprintCatalog(...$recipes),
            $installationCosts ?? new FixedJobInstallationCosts(),
            array_map(static fn (float $unitPrice): WeightedPrice => new WeightedPrice(new Isk($unitPrice), new OrderBookCoverage(1.0)), $jitaUnitPrices),
            array_map(static fn (float $unitPrice): Isk => new Isk($unitPrice), $structureUnitPrices),
            $structureSellVolumes,
            $volumes,
            new CourierHub(self::HUB_SYSTEM_ID, self::HUB_ISK_PER_CUBIC_METER, self::BRIDGE_ISK_PER_CUBIC_METER),
            $marketStructureId,
            null === $marketStructureId ? null : self::MARKET_SYSTEM_ID,
            [],
            $markets,
        );
    }

    private function eligibleOption(SourcingChoice $kind, float $total): DeliveredSourcingOption
    {
        $zero = Cost::known(new Isk(0.0));

        return new DeliveredSourcingOption($kind, Cost::known(new Isk($total)), Cost::known(new Isk($total)), $zero, $zero, true);
    }
}
