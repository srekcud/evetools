<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Service\Industry\Project\ForecastedProduct;
use App\Service\Industry\Project\ProjectSellForecast;
use App\Service\Industry\Project\RemainingBomCost;
use App\Service\Industry\Project\SellForecast;
use App\Service\Industry\Project\SellStructure;
use App\Service\Industry\Project\UnsoldProductsOfProject;
use App\Service\Industry\SellMarkets\ImmediateSale;
use App\Service\Industry\SellMarkets\SellMarketComparison;
use App\Service\Industry\SellMarkets\SellMarketComparisonResult;
use App\Service\Industry\SellMarkets\SellMarketRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectSellForecast::class)]
#[CoversClass(ForecastedProduct::class)]
#[CoversClass(SellForecast::class)]
#[Group('issue-50')]
final class ProjectSellForecastTest extends TestCase
{
    private const int HAMMERHEAD_TYPE_ID = 2185;
    private const int SABRE_TYPE_ID = 22456;
    private const int RAVEN_TYPE_ID = 638;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;
    private const int SELL_STRUCTURE_ID = 1_035_466_617_946;
    private const string SELL_STRUCTURE_NAME = 'Perimeter - Tranquility Trade Hub';
    private const string JITA_NAME = 'Jita IV - Moon 4';

    private SellMarketComparison&MockObject $comparison;
    private User $owner;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->comparison = $this->createMock(SellMarketComparison::class);
        $this->owner = new User();
        $this->today = new \DateTimeImmutable('2026-11-09');
    }

    public function testNetRevenueIsTheRemainingQuantityTimesTheNetUnitPrice(): void
    {
        $project = $this->projectSellingAt(
            [$this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 10, 10)],
            [$this->sale(self::HAMMERHEAD_TYPE_ID, 4)],
        );
        $this->comparisonReturns([
            self::HAMMERHEAD_TYPE_ID => [
                'structure' => $this->row('capital', self::SELL_STRUCTURE_NAME, 800_000.0, 761_960.0),
                'jita' => $this->row('jita', self::JITA_NAME, 760_000.0, 723_862.0),
            ],
        ]);

        $forecast = $this->forecastOf($project);

        self::assertCount(1, $forecast->products);
        $product = $forecast->products[0];
        self::assertSame(self::HAMMERHEAD_TYPE_ID, $product->typeId);
        self::assertSame('Hammerhead II', $product->typeName);
        self::assertSame(6, $product->quantity);
        self::assertSame(4_571_760.0, $product->structureNetRevenue());
        self::assertSame(4_343_172.0, $product->jitaNetRevenue());
        self::assertSame(4_571_760.0, $forecast->structureNetRevenue);
        self::assertSame(4_343_172.0, $forecast->jitaNetRevenue);
        self::assertSame(0, $forecast->structureUnknownProductCount);
        self::assertSame(0, $forecast->jitaUnknownProductCount);
    }

    public function testEachRemainingProductIsComparedForTheProjectOwnerAtTheProjectSellStructureForItsRemainingQuantity(): void
    {
        $project = $this->projectSellingAt(
            [
                $this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 10, 10),
                $this->finalJobLine(self::SABRE_TYPE_ID, 'Sabre', 3, 3),
            ],
            [$this->sale(self::HAMMERHEAD_TYPE_ID, 4)],
        );
        $calls = [];
        $this->comparison->expects(self::exactly(2))->method('compareAt')->willReturnCallback(
            function (User $seller, ?SellStructure $structure, int $typeId, int $quantity, \DateTimeImmutable $today) use (&$calls): SellMarketComparisonResult {
                $calls[] = [$seller, $structure, $typeId, $quantity, $today];

                return $this->comparisonResultOf($typeId, $quantity, [
                    $this->row('jita', self::JITA_NAME, 1.0, 1.0),
                    $this->row('capital', self::SELL_STRUCTURE_NAME, 1.0, 1.0),
                ]);
            },
        );

        $this->forecastOf($project);

        self::assertCount(2, $calls);
        self::assertSame($this->owner, $calls[0][0]);
        self::assertEquals(new SellStructure(self::SELL_STRUCTURE_ID, self::SELL_STRUCTURE_NAME), $calls[0][1]);
        self::assertSame([self::HAMMERHEAD_TYPE_ID, 6], [$calls[0][2], $calls[0][3]]);
        self::assertSame($this->owner, $calls[1][0]);
        self::assertEquals(new SellStructure(self::SELL_STRUCTURE_ID, self::SELL_STRUCTURE_NAME), $calls[1][1]);
        self::assertSame([self::SABRE_TYPE_ID, 3], [$calls[1][2], $calls[1][3]]);
        self::assertEquals($this->today, $calls[0][4]);
    }

    public function testForecastCarriesTheProjectSellStructure(): void
    {
        $project = $this->projectSellingAt([], []);

        $forecast = $this->forecastOf($project);

        self::assertEquals(new SellStructure(self::SELL_STRUCTURE_ID, self::SELL_STRUCTURE_NAME), $forecast->sellStructure);
    }

    public function testProjectWithoutSellStructureIsComparedOnJitaOnlyAndHasNoStructureColumn(): void
    {
        $project = $this->projectWithoutSellStructure([$this->finalJobLine(self::SABRE_TYPE_ID, 'Sabre', 3, 3)]);
        $this->comparison->expects(self::once())->method('compareAt')
            ->with(self::identicalTo($this->owner), self::isNull(), self::SABRE_TYPE_ID, 3, self::anything())
            ->willReturn($this->comparisonResultOf(self::SABRE_TYPE_ID, 3, [$this->row('jita', self::JITA_NAME, 1_250_000.0, 1_190_550.0)]));

        $forecast = $this->forecastOf($project);

        self::assertNull($forecast->sellStructure);
        self::assertCount(1, $forecast->products);
        self::assertNull($forecast->products[0]->structure);
        self::assertNull($forecast->products[0]->structureNetRevenue());
        self::assertSame(3_571_650.0, $forecast->products[0]->jitaNetRevenue());
        self::assertNull($forecast->structureNetRevenue);
        self::assertSame(3_571_650.0, $forecast->jitaNetRevenue);
        self::assertSame(0, $forecast->jitaUnknownProductCount);
    }

    public function testProductWithoutPriceHasNoRevenueAndKeepsItsOtherFields(): void
    {
        $project = $this->projectSellingAt([$this->finalJobLine(self::RAVEN_TYPE_ID, 'Raven', 2, 2)], []);
        $this->comparisonReturns([
            self::RAVEN_TYPE_ID => [
                'structure' => $this->row('capital', self::SELL_STRUCTURE_NAME, null, null, 0.0475, 12.5),
                'jita' => $this->row('jita', self::JITA_NAME, 190_000_000.0, 180_962_500.0),
            ],
        ]);

        $product = $this->forecastOf($project)->products[0];

        self::assertNull($product->structureNetRevenue());
        self::assertSame(361_925_000.0, $product->jitaNetRevenue());
        self::assertNotNull($product->structure);
        self::assertNull($product->structure->unitPrice);
        self::assertSame(12.5, $product->structure->daysToSell);
    }

    public function testProductWithKnownPriceButUnknownFeesHasNoRevenue(): void
    {
        $project = $this->projectSellingAt([$this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 6, 6)], []);
        $this->comparisonReturns([
            self::HAMMERHEAD_TYPE_ID => [
                'structure' => $this->row('capital', self::SELL_STRUCTURE_NAME, 800_000.0, null, null),
                'jita' => $this->row('jita', self::JITA_NAME, 760_000.0, null, null),
            ],
        ]);

        $forecast = $this->forecastOf($project);

        self::assertSame(800_000.0, $forecast->products[0]->structure?->unitPrice);
        self::assertNull($forecast->products[0]->structureNetRevenue());
        self::assertNull($forecast->products[0]->jitaNetRevenue());
        self::assertNull($forecast->structureNetRevenue);
        self::assertNull($forecast->jitaNetRevenue);
        self::assertSame(1, $forecast->structureUnknownProductCount);
        self::assertSame(1, $forecast->jitaUnknownProductCount);
    }

    public function testStructureTotalIsUnknownAsSoonAsOneProductIsUnknownWhileJitaStaysSummed(): void
    {
        $project = $this->projectSellingAt([
            $this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 6, 6),
            $this->finalJobLine(self::SABRE_TYPE_ID, 'Sabre', 3, 3),
        ], []);
        $this->comparisonReturns([
            self::HAMMERHEAD_TYPE_ID => [
                'structure' => $this->row('capital', self::SELL_STRUCTURE_NAME, null, null),
                'jita' => $this->row('jita', self::JITA_NAME, 760_000.0, 723_862.0),
            ],
            self::SABRE_TYPE_ID => [
                'structure' => $this->row('capital', self::SELL_STRUCTURE_NAME, 1_300_000.0, 1_235_000.0),
                'jita' => $this->row('jita', self::JITA_NAME, 1_250_000.0, 1_180_000.0),
            ],
        ]);

        $forecast = $this->forecastOf($project);

        self::assertNull($forecast->structureNetRevenue);
        self::assertSame(1, $forecast->structureUnknownProductCount);
        self::assertSame(7_883_172.0, $forecast->jitaNetRevenue);
        self::assertSame(0, $forecast->jitaUnknownProductCount);
    }

    public function testJitaTotalIsUnknownAsSoonAsOneProductIsUnknownWhileTheStructureStaysSummed(): void
    {
        $project = $this->projectSellingAt([
            $this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 6, 6),
            $this->finalJobLine(self::SABRE_TYPE_ID, 'Sabre', 3, 3),
            $this->finalJobLine(self::RAVEN_TYPE_ID, 'Raven', 2, 2),
        ], []);
        $this->comparisonReturns([
            self::HAMMERHEAD_TYPE_ID => [
                'structure' => $this->row('capital', self::SELL_STRUCTURE_NAME, 800_000.0, 761_960.0),
                'jita' => $this->row('jita', self::JITA_NAME, null, null),
            ],
            self::SABRE_TYPE_ID => [
                'structure' => $this->row('capital', self::SELL_STRUCTURE_NAME, 1_300_000.0, 1_235_000.0),
                'jita' => $this->row('jita', self::JITA_NAME, null, null),
            ],
            self::RAVEN_TYPE_ID => [
                'structure' => $this->row('capital', self::SELL_STRUCTURE_NAME, 190_000_000.0, 180_500_000.0),
                'jita' => $this->row('jita', self::JITA_NAME, 195_000_000.0, 185_700_000.0),
            ],
        ]);

        $forecast = $this->forecastOf($project);

        self::assertNull($forecast->jitaNetRevenue);
        self::assertSame(2, $forecast->jitaUnknownProductCount);
        self::assertSame(4_571_760.0 + 3_705_000.0 + 361_000_000.0, $forecast->structureNetRevenue);
        self::assertSame(0, $forecast->structureUnknownProductCount);
    }

    public function testProjectWithEverythingSoldHasNoProductAndNoTotal(): void
    {
        $project = $this->projectSellingAt(
            [$this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 10, 10)],
            [$this->sale(self::HAMMERHEAD_TYPE_ID, 10)],
        );
        $this->comparison->expects(self::never())->method('compareAt');

        $forecast = $this->forecastOf($project);

        self::assertSame([], $forecast->products);
        self::assertNull($forecast->structureNetRevenue);
        self::assertNull($forecast->jitaNetRevenue);
        self::assertSame(0, $forecast->structureUnknownProductCount);
        self::assertSame(0, $forecast->jitaUnknownProductCount);
    }

    public function testProjectWithoutFinalLineHasNoProductAndNoTotal(): void
    {
        $project = $this->projectSellingAt([], []);

        $forecast = $this->forecastOf($project);

        self::assertSame([], $forecast->products);
        self::assertNull($forecast->structureNetRevenue);
        self::assertNull($forecast->jitaNetRevenue);
    }

    public function testProductsAreSortedByTypeNameAndTheSoldOutOneIsLeftOut(): void
    {
        $project = $this->projectSellingAt(
            [
                $this->finalJobLine(self::SABRE_TYPE_ID, 'Sabre', 3, 3),
                $this->finalJobLine(self::RAVEN_TYPE_ID, 'Raven', 2, 2),
                $this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 10, 10),
            ],
            [$this->sale(self::RAVEN_TYPE_ID, 2)],
        );
        $this->comparisonReturns([
            self::SABRE_TYPE_ID => ['structure' => $this->row('capital', self::SELL_STRUCTURE_NAME, null, null), 'jita' => $this->row('jita', self::JITA_NAME, null, null)],
            self::HAMMERHEAD_TYPE_ID => ['structure' => $this->row('capital', self::SELL_STRUCTURE_NAME, null, null), 'jita' => $this->row('jita', self::JITA_NAME, null, null)],
        ]);

        $forecast = $this->forecastOf($project);

        self::assertSame(
            [[self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 10], [self::SABRE_TYPE_ID, 'Sabre', 3]],
            array_map(static fn (ForecastedProduct $product): array => [$product->typeId, $product->typeName, $product->quantity], $forecast->products),
        );
    }

    public function testQuantityIsTheRemainingUnitsNotTheRunsOfTheLine(): void
    {
        $project = $this->projectSellingAt(
            [$this->finalJobLine(self::SABRE_TYPE_ID, 'Sabre', 5, 15)],
            [$this->sale(self::SABRE_TYPE_ID, 4)],
        );
        $this->comparisonReturns([
            self::SABRE_TYPE_ID => [
                'structure' => $this->row('capital', self::SELL_STRUCTURE_NAME, 1_300_000.0, 1_235_000.0),
                'jita' => $this->row('jita', self::JITA_NAME, 1_250_000.0, 1_180_000.0),
            ],
        ]);

        $product = $this->forecastOf($project)->products[0];

        self::assertSame(11, $product->quantity);
        self::assertSame(13_585_000.0, $product->structureNetRevenue());
    }

    #[Group('issue-483')]
    public function testProfitIsTheNetRevenueMinusTheRemainingCostOfEachColumn(): void
    {
        $project = $this->projectSellingAt(
            [
                $this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 4, 4),
                $this->materialLine(self::TRITANIUM_TYPE_ID, 10, 4, 100.0),
                $this->materialLine(self::PYERITE_TYPE_ID, 3, 0, 1_000.0),
            ],
            [],
        );
        $this->comparisonReturns([
            self::HAMMERHEAD_TYPE_ID => [
                'structure' => $this->row('capital', self::SELL_STRUCTURE_NAME, 1_200_000.0, 1_142_940.0),
                'jita' => $this->row('jita', self::JITA_NAME, 1_050_000.0, 1_000_000.0),
            ],
        ]);

        $forecast = $this->forecastOf($project);

        self::assertSame(3_600.0, $forecast->remainingCost);
        self::assertSame(0, $forecast->remainingCostUnknownLineCount);
        self::assertSame(4_568_160.0, $forecast->structureProfit);
        self::assertSame(3_996_400.0, $forecast->jitaProfit);
    }

    #[Group('issue-483')]
    public function testProfitIsTheWholeNetRevenueWhenEveryMaterialIsFulfilled(): void
    {
        $project = $this->projectSellingAt(
            [
                $this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 4, 4),
                $this->materialLine(self::TRITANIUM_TYPE_ID, 10, 10, 100.0),
            ],
            [],
        );
        $this->comparisonReturns([
            self::HAMMERHEAD_TYPE_ID => [
                'structure' => $this->row('capital', self::SELL_STRUCTURE_NAME, 1_200_000.0, 1_142_940.0),
                'jita' => $this->row('jita', self::JITA_NAME, 1_050_000.0, 1_000_000.0),
            ],
        ]);

        $forecast = $this->forecastOf($project);

        self::assertSame(0.0, $forecast->remainingCost);
        self::assertSame(4_571_760.0, $forecast->structureProfit);
        self::assertSame(4_000_000.0, $forecast->jitaProfit);
    }

    #[Group('issue-483')]
    public function testProfitCanBeNegativeWhenTheRemainingCostExceedsTheNetRevenue(): void
    {
        $project = $this->projectSellingAt(
            [
                $this->finalJobLine(self::SABRE_TYPE_ID, 'Sabre', 3, 3),
                $this->materialLine(self::PYERITE_TYPE_ID, 2_000_000, 0, 5.5),
            ],
            [],
        );
        $this->comparisonReturns([
            self::SABRE_TYPE_ID => [
                'structure' => $this->row('capital', self::SELL_STRUCTURE_NAME, 1_300_000.0, 1_235_000.0),
                'jita' => $this->row('jita', self::JITA_NAME, 1_250_000.0, 1_180_000.0),
            ],
        ]);

        $forecast = $this->forecastOf($project);

        self::assertSame(11_000_000.0, $forecast->remainingCost);
        self::assertSame(3_705_000.0 - 11_000_000.0, $forecast->structureProfit);
        self::assertSame(3_540_000.0 - 11_000_000.0, $forecast->jitaProfit);
    }

    #[Group('issue-483')]
    public function testProfitsAreUnknownWhenTheRemainingCostIsUnknownAndTheUnpricedLinesAreCounted(): void
    {
        $project = $this->projectSellingAt(
            [
                $this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 4, 4),
                $this->materialLine(self::TRITANIUM_TYPE_ID, 10, 4, 100.0),
                $this->materialLine(self::PYERITE_TYPE_ID, 3, 0, null),
            ],
            [],
        );
        $this->comparisonReturns([
            self::HAMMERHEAD_TYPE_ID => [
                'structure' => $this->row('capital', self::SELL_STRUCTURE_NAME, 1_200_000.0, 1_142_940.0),
                'jita' => $this->row('jita', self::JITA_NAME, 1_050_000.0, 1_000_000.0),
            ],
        ]);

        $forecast = $this->forecastOf($project);

        self::assertNull($forecast->remainingCost);
        self::assertSame(1, $forecast->remainingCostUnknownLineCount);
        self::assertNull($forecast->structureProfit);
        self::assertNull($forecast->jitaProfit);
        self::assertSame(4_571_760.0, $forecast->structureNetRevenue);
    }

    #[Group('issue-483')]
    public function testStructureProfitIsUnknownWhenTheStructureRevenueIsUnknownWhileTheJitaProfitIsComputed(): void
    {
        $project = $this->projectSellingAt(
            [
                $this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 4, 4),
                $this->finalJobLine(self::SABRE_TYPE_ID, 'Sabre', 3, 3),
                $this->materialLine(self::TRITANIUM_TYPE_ID, 10, 4, 100.0),
                $this->materialLine(self::PYERITE_TYPE_ID, 3, 0, 1_000.0),
            ],
            [],
        );
        $this->comparisonReturns([
            self::HAMMERHEAD_TYPE_ID => [
                'structure' => $this->row('capital', self::SELL_STRUCTURE_NAME, null, null),
                'jita' => $this->row('jita', self::JITA_NAME, 1_050_000.0, 1_000_000.0),
            ],
            self::SABRE_TYPE_ID => [
                'structure' => $this->row('capital', self::SELL_STRUCTURE_NAME, 1_300_000.0, 1_235_000.0),
                'jita' => $this->row('jita', self::JITA_NAME, 1_250_000.0, 1_180_000.0),
            ],
        ]);

        $forecast = $this->forecastOf($project);

        self::assertNull($forecast->structureProfit);
        self::assertSame(3_600.0, $forecast->remainingCost);
        self::assertSame(7_540_000.0 - 3_600.0, $forecast->jitaProfit);
    }

    #[Group('issue-483')]
    public function testJitaProfitIsUnknownWhenTheJitaRevenueIsUnknownWhileTheStructureProfitIsComputed(): void
    {
        $project = $this->projectSellingAt(
            [
                $this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 4, 4),
                $this->materialLine(self::TRITANIUM_TYPE_ID, 10, 4, 100.0),
                $this->materialLine(self::PYERITE_TYPE_ID, 3, 0, 1_000.0),
            ],
            [],
        );
        $this->comparisonReturns([
            self::HAMMERHEAD_TYPE_ID => [
                'structure' => $this->row('capital', self::SELL_STRUCTURE_NAME, 1_200_000.0, 1_142_940.0),
                'jita' => $this->row('jita', self::JITA_NAME, null, null),
            ],
        ]);

        $forecast = $this->forecastOf($project);

        self::assertSame(4_568_160.0, $forecast->structureProfit);
        self::assertNull($forecast->jitaProfit);
    }

    #[Group('issue-483')]
    public function testProjectWithoutSellStructureHasNoStructureProfitAndAJitaProfit(): void
    {
        $project = $this->projectWithoutSellStructure([
            $this->finalJobLine(self::SABRE_TYPE_ID, 'Sabre', 3, 3),
            $this->materialLine(self::TRITANIUM_TYPE_ID, 10, 4, 100.0),
            $this->materialLine(self::PYERITE_TYPE_ID, 3, 0, 1_000.0),
        ]);
        $this->comparisonReturns([
            self::SABRE_TYPE_ID => [
                'structure' => $this->row('capital', self::SELL_STRUCTURE_NAME, 1.0, 1.0),
                'jita' => $this->row('jita', self::JITA_NAME, 1_250_000.0, 1_190_550.0),
            ],
        ]);

        $forecast = $this->forecastOf($project);

        self::assertNull($forecast->structureProfit);
        self::assertSame(3_600.0, $forecast->remainingCost);
        self::assertSame(3_571_650.0 - 3_600.0, $forecast->jitaProfit);
    }

    #[Group('issue-483')]
    public function testNothingLeftToSellHasNoProfitButKeepsTheRemainingCost(): void
    {
        $project = $this->projectSellingAt(
            [
                $this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 10, 10),
                $this->materialLine(self::TRITANIUM_TYPE_ID, 10, 4, 100.0),
                $this->materialLine(self::PYERITE_TYPE_ID, 3, 0, 1_000.0),
            ],
            [$this->sale(self::HAMMERHEAD_TYPE_ID, 10)],
        );

        $forecast = $this->forecastOf($project);

        self::assertSame([], $forecast->products);
        self::assertSame(3_600.0, $forecast->remainingCost);
        self::assertSame(0, $forecast->remainingCostUnknownLineCount);
        self::assertNull($forecast->structureProfit);
        self::assertNull($forecast->jitaProfit);
    }

    private function forecastOf(IndustryProject $project): SellForecast
    {
        return (new ProjectSellForecast($this->comparison, new UnsoldProductsOfProject(), new RemainingBomCost()))->forecast($project, $this->today);
    }

    /**
     * @param array<int, array{structure: SellMarketRow, jita: SellMarketRow}> $rowsByTypeId
     */
    private function comparisonReturns(array $rowsByTypeId): void
    {
        $this->comparison->method('compareAt')->willReturnCallback(
            fn (User $seller, ?SellStructure $structure, int $typeId, int $quantity): SellMarketComparisonResult => $this->comparisonResultOf(
                $typeId,
                $quantity,
                null === $structure ? [$rowsByTypeId[$typeId]['jita']] : [$rowsByTypeId[$typeId]['jita'], $rowsByTypeId[$typeId]['structure']],
            ),
        );
    }

    /**
     * @param list<SellMarketRow> $rows
     */
    private function comparisonResultOf(int $typeId, int $quantity, array $rows): SellMarketComparisonResult
    {
        return new SellMarketComparisonResult($typeId, $quantity, $rows, new ImmediateSale(null, null, null));
    }

    private function row(string $key, string $name, ?float $unitPrice, ?float $netUnitPrice, ?float $feeRate = 0.04755, ?float $daysToSell = null): SellMarketRow
    {
        return new SellMarketRow($key, $name, $unitPrice, null, null, 'region_history', $feeRate, $netUnitPrice, $daysToSell);
    }

    /**
     * @param list<IndustryProjectBomLine> $lines
     * @param list<IndustryProjectSale> $sales
     */
    private function projectSellingAt(array $lines, array $sales): IndustryProject
    {
        return $this->projectWithoutSellStructure($lines, $sales)->setSellStructure(self::SELL_STRUCTURE_ID, self::SELL_STRUCTURE_NAME);
    }

    /**
     * @param list<IndustryProjectBomLine> $lines
     * @param list<IndustryProjectSale> $sales
     */
    private function projectWithoutSellStructure(array $lines, array $sales = []): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($this->owner);
        foreach ($lines as $line) {
            $project->addBomItem($line);
        }
        foreach ($sales as $sale) {
            $project->addSale($sale);
        }

        return $project;
    }

    private function finalJobLine(int $typeId, string $typeName, int $runs, int $requiredQuantity): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($requiredQuantity)
            ->setIsJob(true)
            ->setJobGroup('final')
            ->setRuns($runs);
    }

    private function materialLine(int $typeId, int $requiredQuantity, int $fulfilledQuantity, ?float $estimatedPrice): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName('Material ' . $typeId)
            ->setRequiredQuantity($requiredQuantity)
            ->setFulfilledQuantity($fulfilledQuantity)
            ->setEstimatedPrice($estimatedPrice)
            ->setIsJob(false);
    }

    private function sale(int $typeId, int $quantity): IndustryProjectSale
    {
        return (new IndustryProjectSale())
            ->setTypeId($typeId)
            ->setTypeName('Sold type')
            ->setQuantity($quantity)
            ->setUnitPrice(1_000.0)
            ->setTotalPrice($quantity * 1_000.0)
            ->setSoldAt(new \DateTimeImmutable('2026-11-01 10:00:00'));
    }
}
