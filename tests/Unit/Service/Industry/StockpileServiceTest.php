<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryStockpileImport;
use App\Entity\IndustryStockpileTarget;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Industry\Domain\SecurityClass;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryStockpileImportRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\StockpileService;
use App\Service\JitaMarketService;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(StockpileService::class)]
#[AllowMockObjectsWithoutExpectations]
class StockpileServiceTest extends TestCase
{
    use BuildsStockpileOnWidgetSde;

    private const int WIDGETS_PER_RUN = 2;
    private const int HULLS_PER_WIDGET_RUN = 2;
    private const int ORE_PER_WIDGET_RUN = 25;
    private const int HULLS_PER_RUN = 3;
    private const int GADGETS_PER_HULL_RUN = 4;
    private const int ORE_PER_HULL_RUN = 30;
    private const int GADGETS_PER_RUN = 2;
    private const int ORE_PER_GADGET_RUN = 70;
    private const int FIVE_RUNS = 5;
    private const int ME_ZERO = 0;

    private IndustryStockpileTargetRepository&MockObject $targetRepository;
    private CachedAssetRepository&MockObject $assetRepository;
    private JitaMarketService&MockObject $jitaMarketService;
    private EntityManagerInterface&MockObject $entityManager;
    private IndustryStockpileImportRepository&MockObject $importRepository;
    private StockpileService $service;
    private User&MockObject $user;

    protected function setUp(): void
    {
        $this->useHullRecipes();
        $this->targetRepository = $this->createMock(IndustryStockpileTargetRepository::class);
        $this->assetRepository = $this->createMock(CachedAssetRepository::class);
        $this->jitaMarketService = $this->createMock(JitaMarketService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $typeRepository = $this->typeRepositoryOnWidgetSde();
        $this->entityManager->method('getRepository')->willReturnCallback(
            static fn (string $className): ?InvTypeRepository => $className === InvType::class ? $typeRepository : null,
        );
        $this->importRepository = $this->createMock(IndustryStockpileImportRepository::class);

        $this->service = $this->serviceOnWidgetSde();

        $this->user = $this->createMock(User::class);
        $this->user->method('getId')->willReturn(Uuid::fromString(self::PILOT_ID));
    }

    private function serviceOnWidgetSde(): StockpileService
    {
        return new StockpileService(
            $this->targetRepository,
            $this->assetRepository,
            $this->jitaMarketService,
            $this->productionPlanningOnWidgetSde(),
            $this->userStructuresOfThePilot(),
            $this->productRepositoryOnWidgetSde(),
            $this->entityManager,
            $this->importRepository,
            $this->startingStockReducerOnWidgetSde(),
        );
    }

    private function useHullRecipes(): void
    {
        $this->recipes = [
            self::WIDGET => ['activity' => IndustryActivityType::Manufacturing, 'outputPerRun' => self::WIDGETS_PER_RUN, 'materials' => [self::HULL => self::HULLS_PER_WIDGET_RUN, self::ORE => self::ORE_PER_WIDGET_RUN]],
            self::HULL => ['activity' => IndustryActivityType::Manufacturing, 'outputPerRun' => self::HULLS_PER_RUN, 'materials' => [self::GADGET => self::GADGETS_PER_HULL_RUN, self::ORE => self::ORE_PER_HULL_RUN]],
            self::GADGET => ['activity' => IndustryActivityType::Reaction, 'outputPerRun' => self::GADGETS_PER_RUN, 'materials' => [self::ORE => self::ORE_PER_GADGET_RUN]],
        ];
    }

    #[Group('issue-69')]
    public function testStageOfAMaterialIsComponentWhenAJobOfTheImportedProductConsumesItEvenIfADeeperJobAlsoConsumesIt(): void
    {
        $this->recipes[self::WIDGET]['materials'] = [self::HULL => self::HULLS_PER_WIDGET_RUN, self::GADGET => 1, self::ORE => self::ORE_PER_WIDGET_RUN];
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([]);

        $preview = $this->service->previewImport($this->user, self::WIDGET, self::FIVE_RUNS, self::ME_ZERO);

        $this->assertSame([self::GADGET => 20, self::HULL => 10], self::sortedQuantitiesByTypeId($preview['stages']['component']));
        $this->assertSame([], $preview['stages']['intermediate']);
        $this->assertSame([self::ORE => 933], array_column($preview['stages']['raw_material'], 'quantity', 'typeId'));
    }

    #[Group('issue-69')]
    public function testPreviewFollowsTheStructuresOfThePilotForTheManufacturingJobsOnly(): void
    {
        $this->givenThePilotStructures(ProductionStructures::raitaru('pilot-raitaru', SecurityClass::NullSec));
        $service = $this->serviceOnWidgetSde();
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([]);

        $preview = $service->previewImport($this->user, self::WIDGET, self::FIVE_RUNS, self::ME_ZERO);

        $this->assertSame([self::ORE => 791], array_column($preview['stages']['raw_material'], 'quantity', 'typeId'));
        $this->assertSame([self::HULL => 10], array_column($preview['stages']['component'], 'quantity', 'typeId'));
        $this->assertSame([self::PILOT_ID], array_values(array_unique($this->userStructures->requestedUserIds)));
    }

    #[Group('issue-69')]
    public function testPreviewOfRecipesWithACycleThrowsARuntimeExceptionNamingTheProductNeededToBuildItself(): void
    {
        $this->recipes[self::GADGET]['materials'] = [self::HULL => 1];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Product 3005 is needed to build itself: the production graph has a cycle.');

        $this->service->previewImport($this->user, self::WIDGET, self::FIVE_RUNS, self::ME_ZERO);
    }

    public function testImportReplaceDeletesExisting(): void
    {
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([]);
        $this->targetRepository->expects($this->once())
            ->method('deleteAllForUser')
            ->with($this->user);
        $persistedTargets = [];
        $this->entityManager->expects($this->atLeastOnce())->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persistedTargets): void {
                if ($entity instanceof IndustryStockpileTarget) {
                    $persistedTargets[$entity->getTypeId()] = [$entity->getTargetQuantity(), $entity->getStage()];
                }
            },
        );
        $this->entityManager->expects($this->atLeastOnce())->method('flush');

        $this->service->importFromBlueprint($this->user, self::WIDGET, self::FIVE_RUNS, self::ME_ZERO, 'replace');

        ksort($persistedTargets);
        $this->assertSame(
            [self::WIDGET => [10, 'final_product'], self::ORE => [793, 'raw_material'], self::GADGET => [15, 'intermediate'], self::HULL => [10, 'component']],
            $persistedTargets,
        );
    }

    public function testImportMergeAddsQuantities(): void
    {
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([]);
        $existingTarget = $this->createMock(IndustryStockpileTarget::class);
        $existingTarget->method('getTargetQuantity')->willReturn(300);
        $existingTarget->expects($this->once())
            ->method('setTargetQuantity')
            ->with(1093);
        $existingTarget->expects($this->once())
            ->method('setUpdatedAt');
        $this->targetRepository->method('findByUserAndTypeId')
            ->willReturnCallback(static fn (User $user, int $typeId): ?IndustryStockpileTarget => $typeId === self::ORE ? $existingTarget : null);
        $this->targetRepository->expects($this->never())->method('deleteAllForUser');
        $this->entityManager->expects($this->atLeastOnce())->method('flush');

        $this->service->importFromBlueprint($this->user, self::WIDGET, self::FIVE_RUNS, self::ME_ZERO, 'merge');
    }

    public function testImportInReplaceModeKeepsOnlyTheStockpileImportOfTheLastImport(): void
    {
        $recordedImports = $this->recordStockpileImports();
        $firstRuns = 2;
        $firstMe = 4;
        $secondRuns = 3;
        $secondMe = 6;
        $replacingRuns = 5;
        $replacingMe = 8;

        $this->service->importFromBlueprint($this->user, self::WIDGET, $firstRuns, $firstMe, 'merge');
        $this->service->importFromBlueprint($this->user, self::WIDGET, $secondRuns, $secondMe, 'merge');
        $this->service->importFromBlueprint($this->user, self::WIDGET, $replacingRuns, $replacingMe, 'replace');

        $this->assertSame([['pilot', self::WIDGET, 5, 8]], $this->describeStockpileImports($recordedImports));
    }

    public function testImportInMergeModeAddsOneStockpileImport(): void
    {
        $recordedImports = $this->recordStockpileImports();
        $replacingRuns = 5;
        $replacingMe = 8;
        $mergedRuns = 7;
        $mergedMe = 9;

        $this->service->importFromBlueprint($this->user, self::WIDGET, $replacingRuns, $replacingMe, 'replace');
        $this->service->importFromBlueprint($this->user, self::WIDGET, $mergedRuns, $mergedMe, 'merge');

        $this->assertSame([['pilot', self::WIDGET, 5, 8], ['pilot', self::WIDGET, 7, 9]], $this->describeStockpileImports($recordedImports));
    }

    public function testKpiPipelineHealth(): void
    {
        // 4 targets: 2 met (>=100%), 2 not met
        $targets = [
            $this->createTarget(1, 'Ore A', 100, 'raw_material', 100, 100),
            $this->createTarget(2, 'Ore B', 50, 'raw_material', 100, 100),
            $this->createTarget(3, 'Component', 100, 'component', 100, 100),
            $this->createTarget(4, 'Metal', 10, 'raw_material', 100, 100),
        ];

        $this->targetRepository->method('findByUser')->willReturn($targets);
        $this->assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([
            1 => ['total' => 100, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 100]]],
            2 => ['total' => 30, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 30]]],
            3 => ['total' => 200, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 200]]],
            4 => ['total' => 2, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 2]]],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
            1 => 10.0,
            2 => 20.0,
            3 => 100.0,
            4 => 50.0,
        ]);

        $status = $this->service->getStockpileStatus($this->user);

        // 2 out of 4 targets met = 50%
        $this->assertSame(50.0, $status['kpis']['pipelineHealth']);
    }

    public function testBottleneckIdentifiesLowestPercent(): void
    {
        $targets = [
            $this->createTarget(1, 'Ore A', 100, 'raw_material', 100, 100),
            $this->createTarget(2, 'Ore B', 100, 'raw_material', 100, 100),
            $this->createTarget(3, 'Worst Item', 100, 'component', 100, 100),
        ];

        $this->targetRepository->method('findByUser')->willReturn($targets);
        $this->assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([
            1 => ['total' => 80, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 80]]],
            2 => ['total' => 60, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 60]]],
            3 => ['total' => 5, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 5]]],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
            1 => 10.0,
            2 => 20.0,
            3 => 100.0,
        ]);

        $status = $this->service->getStockpileStatus($this->user);

        $this->assertSame(
            ['typeId' => 3, 'typeName' => 'Worst Item', 'percent' => 5.0, 'blocksProducts' => 0],
            $status['kpis']['bottleneck'],
        );
    }

    public function testEstOutputCountsBuildableAndInStockFinalProducts(): void
    {
        $targets = [
            $this->createTarget(100, 'Widget A', 10, 'final_product', 100, 100),
            $this->createTarget(1, 'Ore', 50, 'raw_material', 100, 100),
            $this->createTarget(2, 'Frame', 20, 'component', 100, 100),
            $this->createTarget(200, 'Widget B', 5, 'final_product', 200, 200),
            $this->createTarget(3, 'Metal', 100, 'raw_material', 200, 200),
        ];

        $this->targetRepository->method('findByUser')->willReturn($targets);
        $this->assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([
            100 => ['total' => 10, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 10]]],
            1 => ['total' => 50, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 50]]],
            2 => ['total' => 20, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 20]]],
            200 => ['total' => 5, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 5]]],
            3 => ['total' => 30, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 30]]],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
            100 => 1000.0,
            1 => 10.0,
            2 => 50.0,
            200 => 2000.0,
            3 => 20.0,
        ]);

        $status = $this->service->getStockpileStatus($this->user);

        $this->assertSame(2, $status['kpis']['estOutput']['ready']);
        $this->assertSame(2, $status['kpis']['estOutput']['total']);
        $this->assertSame(['Widget A', 'Widget B'], $status['kpis']['estOutput']['readyNames']);
    }

    public function testPreviewImportReturnsCorrectStagesAndEstimatedCost(): void
    {
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
            self::WIDGET => 2_500_000.0,
            self::HULL => 41_000.0,
            self::GADGET => 730.0,
            self::ORE => 6.5,
        ]);

        $result = $this->service->previewImport($this->user, self::WIDGET, self::FIVE_RUNS, self::ME_ZERO);

        $this->assertSame(['stages', 'totalItems', 'estimatedCost', 'missingPriceTypeIds'], array_keys($result));
        $this->assertSame([], $result['missingPriceTypeIds']);
        $this->assertSame(4, $result['totalItems']);
        $this->assertSame([
            'raw_material' => [['typeId' => self::ORE, 'typeName' => 'Ore', 'quantity' => 793, 'unitPrice' => 6.5]],
            'intermediate' => [['typeId' => self::GADGET, 'typeName' => 'Gadget', 'quantity' => 15, 'unitPrice' => 730.0]],
            'component' => [['typeId' => self::HULL, 'typeName' => 'Hull', 'quantity' => 10, 'unitPrice' => 41_000.0]],
            'final_product' => [['typeId' => self::WIDGET, 'typeName' => 'Widget', 'quantity' => 10, 'unitPrice' => 2_500_000.0]],
        ], $result['stages']);
        $this->assertSame(25_426_104.5, $result['estimatedCost']);
    }

    public function testGetStockpileStatusReturnsZeroKpisForEmptyTargets(): void
    {
        $this->targetRepository->method('findByUser')->willReturn([]);

        $status = $this->service->getStockpileStatus($this->user);

        $this->assertSame(0, $status['targetCount']);
        // All 4 stages must always be present, even when empty
        $this->assertCount(4, $status['stages']);
        foreach (['raw_material', 'intermediate', 'component', 'final_product'] as $stage) {
            $this->assertArrayHasKey($stage, $status['stages']);
            $this->assertSame([], $status['stages'][$stage]['items']);
            $this->assertSame(0.0, $status['stages'][$stage]['totalValue']);
            $this->assertSame(100.0, $status['stages'][$stage]['healthPercent']);
        }
        $this->assertSame(100.0, $status['kpis']['pipelineHealth']);
        $this->assertSame(0.0, $status['kpis']['totalInvested']);
        $this->assertNull($status['kpis']['bottleneck']);
        $this->assertSame(0, $status['kpis']['estOutput']['ready']);
        $this->assertSame(0, $status['kpis']['estOutput']['total']);
        $this->assertSame([], $status['kpis']['estOutput']['readyNames']);
        $this->assertSame([], $status['shoppingList']);
    }

    public function testStagesWithNoTargetsStillPresent(): void
    {
        // Targets only in raw_material and final_product — intermediate and component should still exist
        $targets = [
            $this->createTarget(1, 'Ore', 100, 'raw_material', 100, 1),
            $this->createTarget(100, 'Widget', 10, 'final_product', 100, 100),
        ];

        $this->targetRepository->method('findByUser')->willReturn($targets);
        $this->assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([
            1 => ['total' => 50, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 50]]],
            100 => ['total' => 10, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 10]]],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
            1 => 10.0,
            100 => 5000.0,
        ]);

        $status = $this->service->getStockpileStatus($this->user);

        // All 4 stages must be present
        $this->assertCount(4, $status['stages']);
        foreach (['raw_material', 'intermediate', 'component', 'final_product'] as $stage) {
            $this->assertArrayHasKey($stage, $status['stages']);
            $this->assertArrayHasKey('items', $status['stages'][$stage]);
            $this->assertArrayHasKey('totalValue', $status['stages'][$stage]);
            $this->assertArrayHasKey('healthPercent', $status['stages'][$stage]);
        }

        // Stages without targets should have empty defaults
        $this->assertSame([], $status['stages']['intermediate']['items']);
        $this->assertSame(0.0, $status['stages']['intermediate']['totalValue']);
        $this->assertSame(100.0, $status['stages']['intermediate']['healthPercent']);

        $this->assertSame([], $status['stages']['component']['items']);
        $this->assertSame(0.0, $status['stages']['component']['totalValue']);
        $this->assertSame(100.0, $status['stages']['component']['healthPercent']);

        // Stages with targets should have items
        $this->assertSame(
            [[1, 50, 50.0, 'partial', 500.0]],
            array_map(static fn (array $item): array => [$item['typeId'], $item['stock'], $item['percent'], $item['status'], $item['stockValue']], $status['stages']['raw_material']['items']),
        );
        $this->assertSame(
            [[100, 10, 100.0, 'met', 50000.0]],
            array_map(static fn (array $item): array => [$item['typeId'], $item['stock'], $item['percent'], $item['status'], $item['stockValue']], $status['stages']['final_product']['items']),
        );
    }

    public function testZeroQuantityTargetIsTreatedAsMet(): void
    {
        // A target with targetQuantity = 0 should be considered 100% met
        $targets = [
            $this->createTarget(1, 'Zero Qty Item', 0, 'raw_material', 100, 1),
            $this->createTarget(2, 'Normal Item', 50, 'raw_material', 100, 2),
        ];

        $this->targetRepository->method('findByUser')->willReturn($targets);
        $this->assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([
            1 => ['total' => 0, 'locations' => []],
            2 => ['total' => 50, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 50]]],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
            1 => 10.0,
            2 => 20.0,
        ]);

        $status = $this->service->getStockpileStatus($this->user);

        // Both targets met: zero-qty is auto-met, normal item is fully stocked
        $this->assertSame(100.0, $status['kpis']['pipelineHealth']);
        $this->assertNull($status['kpis']['bottleneck']); // No unmet targets
    }

    public function testAllTargetsMetGivesFullPipelineHealth(): void
    {
        $targets = [
            $this->createTarget(100, 'Widget', 10, 'final_product', 100, 100),
            $this->createTarget(1, 'Ore', 500, 'raw_material', 100, 1),
            $this->createTarget(2, 'Frame', 20, 'component', 100, 2),
            $this->createTarget(3, 'Metal', 100, 'raw_material', 100, 3),
        ];

        $this->targetRepository->method('findByUser')->willReturn($targets);
        $this->assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([
            100 => ['total' => 15, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 15]]],
            1 => ['total' => 500, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 500]]],
            2 => ['total' => 30, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 30]]],
            3 => ['total' => 200, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 200]]],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
            100 => 5000000.0,
            1 => 10.0,
            2 => 500.0,
            3 => 50.0,
        ]);

        $status = $this->service->getStockpileStatus($this->user);

        // All 4 targets are met
        $this->assertSame(100.0, $status['kpis']['pipelineHealth']);
        $this->assertNull($status['kpis']['bottleneck']);

        // Shopping list should be empty (no deficits)
        $this->assertSame([], $status['shoppingList']);

        // Estimated output: Widget (source 100) has all upstream met
        $this->assertSame(1, $status['kpis']['estOutput']['ready']);
        $this->assertSame(1, $status['kpis']['estOutput']['total']);
        $this->assertSame(['Widget'], $status['kpis']['estOutput']['readyNames']);

        // Total invested = sum of stock * unitPrice (capped at 100% for percent but stock value counts all)
        // 15 * 5000000 + 500 * 10 + 30 * 500 + 200 * 50 = 75000000 + 5000 + 15000 + 10000 = 75030000
        $this->assertSame(75030000.0, $status['kpis']['totalInvested']);
    }

    private static function sortedQuantitiesByTypeId(array $entries): array
    {
        $quantities = array_column($entries, 'quantity', 'typeId');
        ksort($quantities);

        return $quantities;
    }

    private function recordStockpileImports(): \ArrayObject
    {
        $recordedImports = new \ArrayObject();
        $this->importRepository->method('deleteAllForUser')->willReturnCallback(
            static function () use ($recordedImports): void {
                $recordedImports->exchangeArray([]);
            },
        );
        $this->entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use ($recordedImports): void {
                if ($entity instanceof IndustryStockpileImport) {
                    $recordedImports->append($entity);
                }
            },
        );

        return $recordedImports;
    }

    private function describeStockpileImports(\ArrayObject $recordedImports): array
    {
        return array_map(
            fn (IndustryStockpileImport $stockpileImport): array => [
                $stockpileImport->getUser() === $this->user ? 'pilot' : 'another user',
                $stockpileImport->getProductTypeId(),
                $stockpileImport->getRuns(),
                $stockpileImport->getMe(),
            ],
            $recordedImports->getArrayCopy(),
        );
    }

    public function testEstOutputCountsOnlyTheFinalProductsInStockOrWhoseUpstreamTargetsAreAllMet(): void
    {
        $this->targetRepository->method('findByUser')->willReturn([
            $this->createTarget(587, 'Rifter', 4, 'final_product', 587, 1),
            $this->createTarget(34, 'Tritanium', 3_000, 'raw_material', 587, 2),
            $this->createTarget(638, 'Raven', 2, 'final_product', 638, 3),
            $this->createTarget(36, 'Mexallon', 900, 'raw_material', 638, 4),
            $this->createTarget(16, 'Nocxium', 75, 'raw_material', 638, 5),
        ]);
        $this->assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([
            34 => ['total' => 3_000, 'locations' => []],
            638 => ['total' => 1, 'locations' => []],
            36 => ['total' => 900, 'locations' => []],
            16 => ['total' => 40, 'locations' => []],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([587 => 512_000.0, 34 => 4.75, 638 => 141_000_000.0, 36 => 68.0, 16 => 815.0]);

        $status = $this->service->getStockpileStatus($this->user);

        $this->assertSame(['ready' => 1, 'total' => 2, 'readyNames' => ['Rifter']], $status['kpis']['estOutput']);
    }

    public function testPipelineHealthIsTheShareOfMetTargetsRoundedToOneDecimal(): void
    {
        $this->targetRepository->method('findByUser')->willReturn([
            $this->createTarget(34, 'Tritanium', 900, 'raw_material', 587, 1),
            $this->createTarget(35, 'Pyerite', 400, 'raw_material', 587, 2),
            $this->createTarget(36, 'Mexallon', 250, 'raw_material', 587, 3),
        ]);
        $this->assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([
            34 => ['total' => 900, 'locations' => []],
            35 => ['total' => 120, 'locations' => []],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 4.75, 35 => 11.2, 36 => 68.0]);

        $status = $this->service->getStockpileStatus($this->user);

        $this->assertSame(33.3, $status['kpis']['pipelineHealth']);
        $this->assertSame(33.3, $status['stages']['raw_material']['healthPercent']);
    }

    public function testBottleneckBlocksOnlyTheFinalProductOfItsSourceNotTheOtherTargetsOfThatSource(): void
    {
        $this->targetRepository->method('findByUser')->willReturn([
            $this->createTarget(587, 'Rifter', 3, 'final_product', 587, 1),
            $this->createTarget(34, 'Tritanium', 1_000, 'raw_material', 587, 2),
            $this->createTarget(3828, 'Construction Blocks', 20, 'component', 587, 3),
        ]);
        $this->assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([
            587 => ['total' => 3, 'locations' => []],
            34 => ['total' => 50, 'locations' => []],
            3828 => ['total' => 15, 'locations' => []],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([587 => 512_000.0, 34 => 4.75, 3828 => 1_430.0]);

        $status = $this->service->getStockpileStatus($this->user);

        $this->assertSame(
            ['typeId' => 34, 'typeName' => 'Tritanium', 'percent' => 5.0, 'blocksProducts' => 1],
            $status['kpis']['bottleneck'],
        );
    }

    /**
     * Create a mock IndustryStockpileTarget with predictable values.
     */
    private function createTarget(int $typeId, string $typeName, int $targetQty, string $stage, int $sourceProductTypeId, int $selfId): IndustryStockpileTarget
    {
        $target = (new IndustryStockpileTarget())
            ->setUser($this->user)
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setTargetQuantity($targetQty)
            ->setStage($stage)
            ->setSourceProductTypeId($sourceProductTypeId);
        (new \ReflectionProperty(IndustryStockpileTarget::class, 'id'))
            ->setValue($target, Uuid::fromString(sprintf('00000000-0000-4000-8000-%012d', $selfId)));

        return $target;
    }
}
