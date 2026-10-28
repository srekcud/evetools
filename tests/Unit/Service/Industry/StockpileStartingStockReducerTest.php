<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Industry\Application\UserProductionStructures;
use App\Industry\Domain\SecurityClass;
use App\Service\Industry\StockpileStartingStockReducer;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\ErrorHandler\BufferingLogger;

#[CoversClass(StockpileStartingStockReducer::class)]
final class StockpileStartingStockReducerTest extends TestCase
{
    use BuildsStockpileOnWidgetSde;

    private const int ONE_RUN = 1;
    private const int TWO_RUNS = 2;
    private const int TEN_RUNS = 10;
    private const int WIDGETS_PER_RUN = 3;
    private const int ME_ZERO = 0;
    private const int ME_TEN = 10;
    private const int RAW_ORE_PER_WIDGET_RUN = 25;
    private const int HULLS_PER_WIDGET_RUN = 1;
    private const int FRAMES_PER_HULL_RUN = 4;

    private const string CYCLE_FAILURE = 'Product 3002 is needed to build itself: the production graph has a cycle.';

    private StockpileStartingStockReducer $reducer;
    private BufferingLogger $logger;
    private User $pilot;

    protected function setUp(): void
    {
        $this->useWidgetRecipes();
        $this->pilot = $this->pilotWithId();
        $this->logger = new BufferingLogger();
        $this->reducer = $this->startingStockReducerOnWidgetSde($this->logger);
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
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

    #[Group('issue-258')]
    public function testImportOfAProductWithoutBlueprintNorReactionReducesNothingConsumesNoStockAndIsReportedForNoBlueprint(): void
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
        self::assertSame([['typeId' => self::WIDGET, 'reason' => 'no_blueprint']], $reduction['failedProducts']);
    }

    #[Group('issue-258')]
    public function testImportOfAProductWithoutBlueprintNorReactionLogsAWarningWithItsTypeAndTheDetail(): void
    {
        $this->removeTheRecipeOf(self::WIDGET);

        $this->reducer->reduce(
            [
                $this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO),
                $this->stockpileImport($this->pilot, self::GADGET, self::ONE_RUN, self::ME_ZERO),
            ],
            [],
            $this->pilot,
        );

        self::assertSame(
            [[LogLevel::WARNING, self::WIDGET, 'Type 3001 has no blueprint nor reaction', null]],
            $this->loggedFailures(),
        );
    }

    public function testStockOfAnIntermediateIsConsumedOnTheDemandAggregatedOverItsConsumers(): void
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

    public function testRawMaterialAlsoConsumedByTheProductIsReducedOnlyByThePartBelowThePartlyCoveredIntermediate(): void
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

    public function testIntermediateFullyCoveredByStockRemovesItsShareOfTheDemandOfTheIntermediateBelowIt(): void
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

    #[Group('issue-69')]
    public function testStockOfARawMaterialNeverReducesItsOwnNeedNextToAPartlyCoveredIntermediate(): void
    {
        $oreStock = 500;
        $frameStock = 5;

        $reduction = $this->reducer->reduce(
            [$this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO)],
            [self::ORE => $oreStock, self::FRAME => $frameStock],
            $this->pilot,
        );

        self::assertSame([self::ORE => 350], self::nonZeroReductions($reduction));
    }

    #[Group('issue-2')]
    public function testPartlyCoveredProductOfSeveralUnitsPerRunPlansTheRunsOfTheUnitsLeft(): void
    {
        $this->recipes[self::WIDGET]['outputPerRun'] = self::WIDGETS_PER_RUN;
        $widgetStock = 4;

        $reduction = $this->reducer->reduce(
            [$this->stockpileImport($this->pilot, self::WIDGET, self::TWO_RUNS, self::ME_ZERO)],
            [self::WIDGET => $widgetStock],
            $this->pilot,
        );

        self::assertSame([self::FRAME => 10, self::ORE => 700], self::nonZeroReductions($reduction));
    }

    #[Group('issue-69')]
    public function testProductStockConsumedByAnImportIsNoLongerAvailableToTheNextImport(): void
    {
        $widgetStock = 1;

        $reduction = $this->reducer->reduce(
            [
                $this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO),
                $this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO),
            ],
            [self::WIDGET => $widgetStock],
            $this->pilot,
        );

        self::assertSame([self::FRAME => 10, self::ORE => 700], self::nonZeroReductions($reduction));
    }

    #[Group('issue-69')]
    public function testBothPlansOfAnImportFollowTheStructuresOfThePilot(): void
    {
        $this->givenThePilotStructures(ProductionStructures::raitaru('pilot-raitaru', SecurityClass::NullSec));
        $reducer = $this->startingStockReducerOnWidgetSde();
        $widgetStock = 5;

        $reduction = $reducer->reduce(
            [$this->stockpileImport($this->pilot, self::WIDGET, self::TEN_RUNS, self::ME_ZERO)],
            [self::WIDGET => $widgetStock],
            $this->pilot,
        );

        self::assertSame([self::FRAME => 49, self::ORE => 3430], self::nonZeroReductions($reduction));
        self::assertSame([self::PILOT_ID], array_values(array_unique($this->userStructures->requestedUserIds)));
    }

    #[Group('issue-69')]
    #[Group('issue-258')]
    public function testImportWhoseRecipesHaveACycleReducesNothingAndIsReportedForPlanFailed(): void
    {
        $hullsPerFrameRun = 2;
        $this->recipes[self::FRAME] = ['activity' => IndustryActivityType::Reaction, 'outputPerRun' => 1, 'materials' => [self::HULL => $hullsPerFrameRun]];
        $this->recipes[self::HULL] = ['activity' => IndustryActivityType::Reaction, 'outputPerRun' => 1, 'materials' => [self::FRAME => self::FRAMES_PER_HULL_RUN]];
        $frameStock = 5;

        $reduction = $this->reducer->reduce(
            [$this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO)],
            [self::FRAME => $frameStock],
            $this->pilot,
        );

        self::assertSame([], self::nonZeroReductions($reduction));
        self::assertSame([['typeId' => self::WIDGET, 'reason' => 'plan_failed']], $reduction['failedProducts']);
    }

    #[Group('issue-258')]
    public function testImportWhoseRecipesHaveACycleLogsAWarningWithItsTypeAndTheProductNeededToBuildItself(): void
    {
        $hullsPerFrameRun = 2;
        $this->recipes[self::FRAME] = ['activity' => IndustryActivityType::Reaction, 'outputPerRun' => 1, 'materials' => [self::HULL => $hullsPerFrameRun]];
        $this->recipes[self::HULL] = ['activity' => IndustryActivityType::Reaction, 'outputPerRun' => 1, 'materials' => [self::FRAME => self::FRAMES_PER_HULL_RUN]];

        $this->reducer->reduce(
            [$this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO)],
            [],
            $this->pilot,
        );

        self::assertSame(
            [[LogLevel::WARNING, self::WIDGET, 'Type 3001 cannot be planned', self::CYCLE_FAILURE]],
            $this->loggedFailures(),
        );
    }

    #[Group('issue-258')]
    public function testFailureToReadTheStructuresOfThePilotPropagatesInsteadOfBeingReportedAsAFailedProduct(): void
    {
        $structureFailure = new \UnexpectedValueException('Structure 0190f3c2-7e4b-7d10-9a3c-5f2e8b1d4a96 has the unsupported type "Keepstar".');
        $reducer = new StockpileStartingStockReducer(
            $this->productionPlanningOnWidgetSde(),
            new class($structureFailure) implements UserProductionStructures {
                public function __construct(private readonly \UnexpectedValueException $structureFailure)
                {
                }

                public function configuredBy(string $userId): array
                {
                    throw $this->structureFailure;
                }
            },
            $this->productRepositoryOnWidgetSde(),
            new NullLogger(),
        );

        $this->expectExceptionObject($structureFailure);

        $reducer->reduce([$this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO)], [], $this->pilot);
    }

    private function loggedFailures(): array
    {
        return array_map(
            static fn (array $log): array => [
                $log[0],
                $log[2]['typeId'] ?? null,
                isset($log[2]['exception']) ? $log[2]['exception']->getMessage() : null,
                isset($log[2]['exception']) ? $log[2]['exception']->getPrevious()?->getMessage() : null,
            ],
            $this->logger->cleanLogs(),
        );
    }

    private static function nonZeroReductions(array $reduction): array
    {
        $nonZeroReductions = array_filter($reduction['reductionByTypeId'], static fn (int $quantity): bool => $quantity !== 0);
        ksort($nonZeroReductions);

        return $nonZeroReductions;
    }
}
