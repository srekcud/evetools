<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryStockpileTarget;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryStockpileImportRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\Service\Industry\StockpileService;
use App\Service\Industry\StockpileStartingStockReducer;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StockpileService::class)]
#[CoversClass(StockpileStartingStockReducer::class)]
final class StockpileStatusIntermediateStockTest extends TestCase
{
    use BuildsStockpileOnWidgetSde;

    private const int ONE_RUN = 1;
    private const int TWO_RUNS = 2;
    private const int ME_ZERO = 0;

    private const int WIDGET_TARGET = 1;
    private const int FRAME_TARGET = 10;
    private const int ORE_TARGET = 700;
    private const int GADGET_TARGET = 1;

    private const array JITA_PRICES = [
        self::WIDGET => 1000.0,
        self::FRAME => 50.0,
        self::ORE => 2.0,
        self::GADGET => 1500.0,
    ];

    private const string WIDGET_FAILURE_REASON = 'No blueprint or reaction found for product type ID 3001';

    private array $targets = [];
    private array $imports = [];
    private array $stockByTypeId = [];

    private StockpileService $service;
    private User $pilot;

    protected function setUp(): void
    {
        $this->useWidgetRecipes();
        $this->pilot = $this->createStub(User::class);

        $targetRepository = $this->createStub(IndustryStockpileTargetRepository::class);
        $targetRepository->method('findByUser')->willReturnCallback(fn (): array => $this->targets);
        $importRepository = $this->createStub(IndustryStockpileImportRepository::class);
        $importRepository->method('findByUserInCreationOrder')->willReturnCallback(fn (): array => $this->imports);
        $assetRepository = $this->createStub(CachedAssetRepository::class);
        $assetRepository->method('getQuantitiesByUserWithLocations')->willReturnCallback(
            fn (): array => array_map(static fn (int $stock): array => ['total' => $stock, 'locations' => []], $this->stockByTypeId),
        );
        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPricesWithFallback')->willReturnCallback(
            static fn (array $typeIds): array => array_intersect_key(self::JITA_PRICES, array_flip($typeIds)),
        );
        $treeService = $this->treeServiceOnWidgetSde();

        $this->service = new StockpileService(
            $targetRepository,
            $assetRepository,
            $jitaMarketService,
            $treeService,
            $this->createStub(EntityManagerInterface::class),
            $importRepository,
            new StockpileStartingStockReducer($treeService),
        );
    }

    public function testPartialStockOfAnIntermediateHalvesTheRemainingTargetOfTheOreBelowIt(): void
    {
        $this->importOneRunOfWidget();
        $this->stockByTypeId = [self::WIDGET => 0, self::FRAME => 5, self::ORE => 0];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            ['targetQuantity' => 700, 'remainingTargetQuantity' => 350, 'percent' => 0.0, 'status' => 'critical', 'deficitCost' => 700.0],
            $this->fieldsOf($status, self::ORE, ['targetQuantity', 'remainingTargetQuantity', 'percent', 'status', 'deficitCost']),
        );
        self::assertSame(
            ['remainingTargetQuantity' => 10, 'percent' => 50.0, 'status' => 'partial', 'deficitCost' => 250.0],
            $this->fieldsOf($status, self::FRAME, ['remainingTargetQuantity', 'percent', 'status', 'deficitCost']),
        );
        self::assertSame(['remainingTargetQuantity' => 1], $this->fieldsOf($status, self::WIDGET, ['remainingTargetQuantity']));
    }

    public function testOreCoveringItsRemainingTargetIsMetAndLeavesTheShoppingListWhileTheWidgetWaitsForItsFrames(): void
    {
        $this->importOneRunOfWidget();
        $this->stockByTypeId = [self::WIDGET => 0, self::FRAME => 5, self::ORE => 350];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            ['remainingTargetQuantity' => 350, 'percent' => 100.0, 'status' => 'met'],
            $this->fieldsOf($status, self::ORE, ['remainingTargetQuantity', 'percent', 'status']),
        );
        self::assertSame([self::WIDGET, self::FRAME], array_column($status['shoppingList'], 'typeId'));
        self::assertSame(['ready' => 0, 'total' => 1, 'readyNames' => []], $status['kpis']['estOutput']);
    }

    public function testFramesFullyInStockLeaveNoOreToBuyAndMakeTheWidgetReady(): void
    {
        $this->importOneRunOfWidget();
        $this->stockByTypeId = [self::WIDGET => 0, self::FRAME => 10, self::ORE => 0];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            ['remainingTargetQuantity' => 0, 'percent' => 100.0, 'status' => 'met', 'deficitCost' => 0.0],
            $this->fieldsOf($status, self::ORE, ['remainingTargetQuantity', 'percent', 'status', 'deficitCost']),
        );
        self::assertSame([self::WIDGET], array_column($status['shoppingList'], 'typeId'));
        self::assertSame(['ready' => 1, 'total' => 1, 'readyNames' => ['Widget']], $status['kpis']['estOutput']);
    }

    public function testWidgetInStockLeavesNothingToBuildBelowItAndAFullyHealthyPipeline(): void
    {
        $this->importOneRunOfWidget();
        $this->stockByTypeId = [self::WIDGET => 1, self::FRAME => 0, self::ORE => 0];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            ['remainingTargetQuantity' => 0, 'status' => 'met'],
            $this->fieldsOf($status, self::FRAME, ['remainingTargetQuantity', 'status']),
        );
        self::assertSame(
            ['remainingTargetQuantity' => 0, 'status' => 'met'],
            $this->fieldsOf($status, self::ORE, ['remainingTargetQuantity', 'status']),
        );
        self::assertSame(100.0, $status['kpis']['pipelineHealth']);
        self::assertNull($status['kpis']['bottleneck']);
    }

    public function testRemainderOfThePartlyCoveredFramesIsBuiltInWholeRuns(): void
    {
        $this->useTheTwoFrameFormula();
        $this->importOneRunOfWidget();
        $this->stockByTypeId = [self::FRAME => 5];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(['remainingTargetQuantity' => 420], $this->fieldsOf($status, self::ORE, ['remainingTargetQuantity']));
    }

    public function testEditedTargetKeepsItsGapAboveTheTree(): void
    {
        $editedOreTarget = 1000;
        $this->imports = [$this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO)];
        $this->addTarget(self::WIDGET, self::WIDGET_TARGET, 'final_product', self::WIDGET);
        $this->addTarget(self::FRAME, self::FRAME_TARGET, 'component', self::WIDGET);
        $this->addTarget(self::ORE, $editedOreTarget, 'raw_material', self::WIDGET);
        $this->stockByTypeId = [self::FRAME => 5];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            ['targetQuantity' => 1000, 'remainingTargetQuantity' => 650],
            $this->fieldsOf($status, self::ORE, ['targetQuantity', 'remainingTargetQuantity']),
        );
    }

    public function testTargetEditedBelowItsReductionHasARemainingTargetOfZero(): void
    {
        $editedOreTarget = 200;
        $this->imports = [$this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO)];
        $this->addTarget(self::WIDGET, self::WIDGET_TARGET, 'final_product', self::WIDGET);
        $this->addTarget(self::FRAME, self::FRAME_TARGET, 'component', self::WIDGET);
        $this->addTarget(self::ORE, $editedOreTarget, 'raw_material', self::WIDGET);
        $this->stockByTypeId = [self::FRAME => 5];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            ['remainingTargetQuantity' => 0, 'status' => 'met', 'deficitCost' => 0.0],
            $this->fieldsOf($status, self::ORE, ['remainingTargetQuantity', 'status', 'deficitCost']),
        );
    }

    public function testFramesConsumedByTheFirstOfTwoMergedImportsAreNotCountedAgainForTheSecond(): void
    {
        $mergedWidgetTarget = 2;
        $mergedFrameTarget = 20;
        $mergedOreTarget = 1400;
        $this->imports = [
            $this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO),
            $this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO),
        ];
        $this->addTarget(self::WIDGET, $mergedWidgetTarget, 'final_product', self::WIDGET);
        $this->addTarget(self::FRAME, $mergedFrameTarget, 'component', self::WIDGET);
        $this->addTarget(self::ORE, $mergedOreTarget, 'raw_material', self::WIDGET);
        $this->stockByTypeId = [self::FRAME => 5];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(['remainingTargetQuantity' => 1050], $this->fieldsOf($status, self::ORE, ['remainingTargetQuantity']));
        self::assertSame(['remainingTargetQuantity' => 20], $this->fieldsOf($status, self::FRAME, ['remainingTargetQuantity']));
    }

    public function testFramesLeftOverByTheWidgetImportReduceTheOreOfTheGadgetImport(): void
    {
        $mergedFrameTarget = 20;
        $mergedOreTarget = 1400;
        $this->imports = [
            $this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO),
            $this->stockpileImport($this->pilot, self::GADGET, self::ONE_RUN, self::ME_ZERO),
        ];
        $this->addTarget(self::WIDGET, self::WIDGET_TARGET, 'final_product', self::WIDGET);
        $this->addTarget(self::FRAME, $mergedFrameTarget, 'component', self::WIDGET);
        $this->addTarget(self::ORE, $mergedOreTarget, 'raw_material', self::WIDGET);
        $this->addTarget(self::GADGET, self::GADGET_TARGET, 'final_product', self::GADGET);
        $this->stockByTypeId = [self::FRAME => 15];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(['remainingTargetQuantity' => 350], $this->fieldsOf($status, self::ORE, ['remainingTargetQuantity']));
    }

    public function testTargetsImportedBeforeImportsWereRecordedAreNotReducedAndInviteToReimport(): void
    {
        $this->addWidgetTargets();
        $this->stockByTypeId = [self::FRAME => 5];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            ['targetQuantity' => 700, 'remainingTargetQuantity' => 700],
            $this->fieldsOf($status, self::ORE, ['targetQuantity', 'remainingTargetQuantity']),
        );
        self::assertSame(
            ['importsWithoutPlan' => [self::WIDGET], 'failedProducts' => []],
            self::importWarningsOf($status),
        );
    }

    public function testProductsWithoutRecordedImportAreListedOnceInTypeIdOrder(): void
    {
        $this->addTarget(self::GADGET, self::GADGET_TARGET, 'final_product', self::GADGET);
        $this->addTarget(self::FRAME, self::FRAME_TARGET, 'component', self::GADGET);
        $this->addTarget(self::WIDGET, self::WIDGET_TARGET, 'final_product', self::WIDGET);
        $this->addTarget(self::ORE, self::ORE_TARGET, 'raw_material', self::WIDGET);

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            ['importsWithoutPlan' => [self::WIDGET, self::GADGET], 'failedProducts' => []],
            self::importWarningsOf($status),
        );
    }

    public function testTargetOfAProductWithoutImportIsNotReducedByAnotherImportSharingItsType(): void
    {
        $this->imports = [$this->stockpileImport($this->pilot, self::GADGET, self::ONE_RUN, self::ME_ZERO)];
        $this->addTarget(self::GADGET, self::GADGET_TARGET, 'final_product', self::GADGET);
        $this->addTarget(self::ORE, self::ORE_TARGET, 'raw_material', self::WIDGET);
        $this->stockByTypeId = [self::FRAME => 5];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            ['targetQuantity' => 700, 'remainingTargetQuantity' => 700],
            $this->fieldsOf($status, self::ORE, ['targetQuantity', 'remainingTargetQuantity']),
        );
        self::assertSame(
            ['importsWithoutPlan' => [self::WIDGET], 'failedProducts' => []],
            self::importWarningsOf($status),
        );
    }

    public function testManualTargetIsNeverReducedNorListedAsAnImportWithoutPlan(): void
    {
        $manualOreTarget = 700;
        $this->imports = [$this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO)];
        $this->addTarget(self::WIDGET, self::WIDGET_TARGET, 'final_product', self::WIDGET);
        $this->addTarget(self::FRAME, self::FRAME_TARGET, 'component', self::WIDGET);
        $this->addTarget(self::ORE, $manualOreTarget, 'raw_material', null);
        $this->stockByTypeId = [self::FRAME => 5];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(['remainingTargetQuantity' => 700], $this->fieldsOf($status, self::ORE, ['remainingTargetQuantity']));
        self::assertSame(['importsWithoutPlan' => [], 'failedProducts' => []], self::importWarningsOf($status));
    }

    public function testImportWhoseTreeCannotBeRebuiltKeepsItsTargetsAndIsReportedWithItsReason(): void
    {
        $this->removeTheRecipeOf(self::WIDGET);
        $this->importOneRunOfWidget();
        $this->stockByTypeId = [self::FRAME => 5];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(['remainingTargetQuantity' => 700], $this->fieldsOf($status, self::ORE, ['remainingTargetQuantity']));
        self::assertSame(['remainingTargetQuantity' => 10], $this->fieldsOf($status, self::FRAME, ['remainingTargetQuantity']));
        self::assertSame(['remainingTargetQuantity' => 1], $this->fieldsOf($status, self::WIDGET, ['remainingTargetQuantity']));
        self::assertSame(
            ['importsWithoutPlan' => [], 'failedProducts' => [['typeId' => self::WIDGET, 'reason' => self::WIDGET_FAILURE_REASON]]],
            self::importWarningsOf($status),
        );
    }

    public function testFailedProductsAreListedOncePerProductInTypeIdOrder(): void
    {
        $this->removeTheRecipeOf(self::WIDGET);
        $this->removeTheRecipeOf(self::GADGET);
        $this->imports = [
            $this->stockpileImport($this->pilot, self::GADGET, self::ONE_RUN, self::ME_ZERO),
            $this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO),
            $this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO),
        ];
        $this->addTarget(self::GADGET, self::GADGET_TARGET, 'final_product', self::GADGET);
        $this->addTarget(self::WIDGET, self::WIDGET_TARGET, 'final_product', self::WIDGET);

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            [
                'importsWithoutPlan' => [],
                'failedProducts' => [
                    ['typeId' => self::WIDGET, 'reason' => self::WIDGET_FAILURE_REASON],
                    ['typeId' => self::GADGET, 'reason' => 'No blueprint or reaction found for product type ID 3004'],
                ],
            ],
            self::importWarningsOf($status),
        );
    }

    public function testWidgetPartlyInStockReducesTheTargetsBelowItToTheRemainingWidget(): void
    {
        $twoRunsWidgetTarget = 2;
        $twoRunsFrameTarget = 20;
        $twoRunsOreTarget = 1400;
        $this->imports = [$this->stockpileImport($this->pilot, self::WIDGET, self::TWO_RUNS, self::ME_ZERO)];
        $this->addTarget(self::WIDGET, $twoRunsWidgetTarget, 'final_product', self::WIDGET);
        $this->addTarget(self::FRAME, $twoRunsFrameTarget, 'component', self::WIDGET);
        $this->addTarget(self::ORE, $twoRunsOreTarget, 'raw_material', self::WIDGET);
        $this->stockByTypeId = [self::WIDGET => 1];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(['remainingTargetQuantity' => 10], $this->fieldsOf($status, self::FRAME, ['remainingTargetQuantity']));
        self::assertSame(['remainingTargetQuantity' => 700], $this->fieldsOf($status, self::ORE, ['remainingTargetQuantity']));
        self::assertSame(['remainingTargetQuantity' => 2], $this->fieldsOf($status, self::WIDGET, ['remainingTargetQuantity']));
    }

    public function testImportWithoutAnyTargetLeftConsumesNoStock(): void
    {
        $this->imports = [
            $this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO),
            $this->stockpileImport($this->pilot, self::GADGET, self::ONE_RUN, self::ME_ZERO),
        ];
        $this->addTarget(self::GADGET, self::GADGET_TARGET, 'final_product', self::GADGET);
        $this->addTarget(self::FRAME, self::FRAME_TARGET, 'component', self::GADGET);
        $this->addTarget(self::ORE, self::ORE_TARGET, 'raw_material', self::GADGET);
        $this->stockByTypeId = [self::FRAME => 10];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            ['remainingTargetQuantity' => 0, 'status' => 'met'],
            $this->fieldsOf($status, self::ORE, ['remainingTargetQuantity', 'status']),
        );
    }

    public function testImportWithoutAnyTargetLeftLeavesItsShareOfTheStockToTheNextImport(): void
    {
        $framesPerHullRun = 3;
        $this->recipes[self::HULL] = ['activity' => IndustryActivityType::Reaction, 'outputPerRun' => 1, 'materials' => [self::FRAME => $framesPerHullRun]];
        $this->useTheTwoFrameFormula();
        $this->imports = [
            $this->stockpileImport($this->pilot, self::HULL, self::ONE_RUN, self::ME_ZERO),
            $this->stockpileImport($this->pilot, self::GADGET, self::ONE_RUN, self::ME_ZERO),
        ];
        $this->addTarget(self::GADGET, self::GADGET_TARGET, 'final_product', self::GADGET);
        $this->addTarget(self::FRAME, self::FRAME_TARGET, 'component', self::GADGET);
        $this->addTarget(self::ORE, self::ORE_TARGET, 'raw_material', self::GADGET);
        $this->stockByTypeId = [self::FRAME => 1];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            ['targetQuantity' => 700, 'remainingTargetQuantity' => 700],
            $this->fieldsOf($status, self::ORE, ['targetQuantity', 'remainingTargetQuantity']),
        );
    }

    private function importOneRunOfWidget(): void
    {
        $this->imports = [$this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO)];
        $this->addWidgetTargets();
    }

    private function addWidgetTargets(): void
    {
        $this->addTarget(self::WIDGET, self::WIDGET_TARGET, 'final_product', self::WIDGET);
        $this->addTarget(self::FRAME, self::FRAME_TARGET, 'component', self::WIDGET);
        $this->addTarget(self::ORE, self::ORE_TARGET, 'raw_material', self::WIDGET);
    }

    private function addTarget(int $typeId, int $targetQuantity, string $stage, ?int $sourceProductTypeId): void
    {
        $this->targets[] = (new IndustryStockpileTarget())
            ->setUser($this->pilot)
            ->setTypeId($typeId)
            ->setTypeName(self::TYPE_NAMES[$typeId])
            ->setTargetQuantity($targetQuantity)
            ->setStage($stage)
            ->setSourceProductTypeId($sourceProductTypeId);
    }

    private function fieldsOf(array $status, int $typeId, array $keys): array
    {
        foreach ($status['stages'] as $stage) {
            foreach ($stage['items'] as $item) {
                if ($item['typeId'] === $typeId) {
                    return self::presentFields($item, $keys);
                }
            }
        }
        self::fail("No stockpile item for type {$typeId}");
    }

    private static function importWarningsOf(array $status): array
    {
        return self::presentFields($status, ['importsWithoutPlan', 'failedProducts']);
    }

    private static function presentFields(array $values, array $keys): array
    {
        $fields = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $values)) {
                $fields[$key] = $values[$key];
            }
        }

        return $fields;
    }
}
