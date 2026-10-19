<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Service\Industry\StockpileStartingStockReducer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StockpileStartingStockReducer::class)]
final class StockpileStartingStockReducerTest extends TestCase
{
    use BuildsStockpileOnWidgetSde;

    private const int ONE_RUN = 1;
    private const int TWO_RUNS = 2;
    private const int ME_ZERO = 0;
    private const int ME_TEN = 10;
    private const int RAW_ORE_PER_WIDGET_RUN = 25;
    private const int HULLS_PER_WIDGET_RUN = 1;
    private const int FRAMES_PER_HULL_RUN = 4;

    private StockpileStartingStockReducer $reducer;
    private User $pilot;

    protected function setUp(): void
    {
        $this->useWidgetRecipes();
        $this->pilot = $this->createStub(User::class);
        $this->reducer = new StockpileStartingStockReducer($this->treeServiceOnWidgetSde());
    }

    public function testPartialStockOfAnIntermediateReducesTheMaterialsBelowItByTheCoveredPart(): void
    {
        $frameStock = 5;

        $reduction = $this->reducer->reduce(
            [$this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO)],
            [self::FRAME => $frameStock],
            $this->pilot,
        );

        self::assertSame([self::ORE => 350], self::nonZeroReductions($reduction));
        self::assertSame([], $reduction['failedProducts']);
    }

    public function testRemainderOfAPartlyCoveredIntermediateIsRoundedUpToWholeRuns(): void
    {
        $this->useTheTwoFrameFormula();
        $frameStock = 5;

        $reduction = $this->reducer->reduce(
            [$this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO)],
            [self::FRAME => $frameStock],
            $this->pilot,
        );

        self::assertSame([self::ORE => 280], self::nonZeroReductions($reduction));
    }

    public function testFullyCoveredProductLeavesNothingToBuildBelowIt(): void
    {
        $widgetStock = 1;

        $reduction = $this->reducer->reduce(
            [$this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO)],
            [self::WIDGET => $widgetStock],
            $this->pilot,
        );

        self::assertSame([self::FRAME => 10, self::ORE => 700], self::nonZeroReductions($reduction));
    }

    public function testPartlyCoveredProductRebuildsItsTreeForTheRemainder(): void
    {
        $widgetStock = 1;

        $reduction = $this->reducer->reduce(
            [$this->stockpileImport($this->pilot, self::WIDGET, self::TWO_RUNS, self::ME_ZERO)],
            [self::WIDGET => $widgetStock],
            $this->pilot,
        );

        self::assertSame([self::FRAME => 10, self::ORE => 700], self::nonZeroReductions($reduction));
    }

    public function testPartlyCoveredProductRebuildsItsTreeWithTheMeOfTheImport(): void
    {
        $widgetStock = 1;

        $reduction = $this->reducer->reduce(
            [$this->stockpileImport($this->pilot, self::WIDGET, self::TWO_RUNS, self::ME_TEN)],
            [self::WIDGET => $widgetStock],
            $this->pilot,
        );

        self::assertSame([self::FRAME => 9, self::ORE => 630], self::nonZeroReductions($reduction));
    }

    public function testStockConsumedByAnImportIsNoLongerAvailableToTheNextImport(): void
    {
        $frameStock = 5;

        $reduction = $this->reducer->reduce(
            [
                $this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO),
                $this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO),
            ],
            [self::FRAME => $frameStock],
            $this->pilot,
        );

        self::assertSame([self::ORE => 350], self::nonZeroReductions($reduction));
    }

    public function testStockLeftOverByAFullyCoveredIntermediateServesTheNextImport(): void
    {
        $frameStock = 15;

        $reduction = $this->reducer->reduce(
            [
                $this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO),
                $this->stockpileImport($this->pilot, self::GADGET, self::ONE_RUN, self::ME_ZERO),
            ],
            [self::FRAME => $frameStock],
            $this->pilot,
        );

        self::assertSame([self::ORE => 1050], self::nonZeroReductions($reduction));
    }

    public function testImportWhoseTreeCannotBeRebuiltReducesNothingConsumesNoStockAndIsReportedWithItsReason(): void
    {
        $this->removeTheRecipeOf(self::WIDGET);
        $frameStock = 10;

        $reduction = $this->reducer->reduce(
            [
                $this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO),
                $this->stockpileImport($this->pilot, self::GADGET, self::ONE_RUN, self::ME_ZERO),
            ],
            [self::FRAME => $frameStock],
            $this->pilot,
        );

        self::assertSame([self::ORE => 700], self::nonZeroReductions($reduction));
        self::assertSame(
            [['typeId' => self::WIDGET, 'reason' => 'No blueprint or reaction found for product type ID 3001']],
            $reduction['failedProducts'],
        );
    }

    public function testStockIsConsumedDepthFirstInTheOrderOfTheMaterials(): void
    {
        $hullsPerWidgetRun = 1;
        $framesPerHullRun = 3;
        $this->recipes[self::WIDGET]['materials'] = [self::HULL => $hullsPerWidgetRun, self::FRAME => self::FRAMES_PER_WIDGET_RUN];
        $this->recipes[self::HULL] = ['activity' => IndustryActivityType::Reaction, 'outputPerRun' => 1, 'materials' => [self::FRAME => $framesPerHullRun]];
        $this->useTheTwoFrameFormula();
        $frameStock = 3;

        $reduction = $this->reducer->reduce(
            [$this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO)],
            [self::FRAME => $frameStock],
            $this->pilot,
        );

        self::assertSame([self::ORE => 280], self::nonZeroReductions($reduction));
    }

    public function testRawMaterialDoesNotStopTheWalkOfItsSiblings(): void
    {
        $this->recipes[self::WIDGET]['materials'] = [self::ORE => self::RAW_ORE_PER_WIDGET_RUN, self::FRAME => self::FRAMES_PER_WIDGET_RUN];
        $frameStock = 5;

        $reduction = $this->reducer->reduce(
            [$this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO)],
            [self::FRAME => $frameStock],
            $this->pilot,
        );

        self::assertSame([self::ORE => 350], self::nonZeroReductions($reduction));
    }

    public function testMaterialFullyCoveredDoesNotStopTheWalkOfItsSiblings(): void
    {
        $this->recipes[self::WIDGET]['materials'] = [self::HULL => self::HULLS_PER_WIDGET_RUN, self::FRAME => self::FRAMES_PER_WIDGET_RUN];
        $this->recipes[self::HULL] = ['activity' => IndustryActivityType::Reaction, 'outputPerRun' => 1, 'materials' => [self::FRAME => self::FRAMES_PER_HULL_RUN]];
        $hullStock = 1;
        $frameStock = 5;

        $reduction = $this->reducer->reduce(
            [$this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO)],
            [self::HULL => $hullStock, self::FRAME => $frameStock],
            $this->pilot,
        );

        self::assertSame([self::FRAME => 4, self::ORE => 630], self::nonZeroReductions($reduction));
    }

    private static function nonZeroReductions(array $reduction): array
    {
        $nonZeroReductions = array_filter($reduction['reductionByTypeId'], static fn (int $quantity): bool => $quantity !== 0);
        ksort($nonZeroReductions);

        return $nonZeroReductions;
    }
}
