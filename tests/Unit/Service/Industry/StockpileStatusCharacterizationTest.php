<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryStockpileTarget;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryStockpileImportRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\Service\Industry\IndustryTreeService;
use App\Service\Industry\StockpileService;
use App\Service\Industry\StockpileStartingStockReducer;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Characterization of StockpileService::getStockpileStatus() before buildProductionTree() is replaced
 * (docs/specs/industry-engine.md, §7 rank 3). Complements StockpileServiceTest: status bounds, stock value,
 * deficitCost, missing price, partial stock of an intermediate, rounding of the percent.
 */
#[CoversClass(StockpileService::class)]
#[AllowMockObjectsWithoutExpectations]
final class StockpileStatusCharacterizationTest extends TestCase
{
    private const int WIDGET_SOURCE = 100;
    private const string JITA_LOCATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant';

    /** @var list<IndustryStockpileTarget> */
    private array $targets = [];

    /** @var array<int, array{total: int, locations: list<array<string, mixed>>}> */
    private array $stockByTypeId = [];

    /** @var array<int, float> */
    private array $jitaPrices = [];

    private int $jitaPriceRequests = 0;

    private StockpileService $service;
    private User $pilot;

    protected function setUp(): void
    {
        $targetRepository = $this->createMock(IndustryStockpileTargetRepository::class);
        $targetRepository->method('findByUser')->willReturnCallback(fn (): array => $this->targets);
        $assetRepository = $this->createMock(CachedAssetRepository::class);
        $assetRepository->method('getQuantitiesByUserWithLocations')->willReturnCallback(fn (): array => $this->stockByTypeId);
        $jitaMarketService = $this->createMock(JitaMarketService::class);
        $jitaMarketService->method('getPrices')->willReturnCallback(
            function (array $typeIds): array {
                ++$this->jitaPriceRequests;

                return array_intersect_key($this->jitaPrices, array_flip($typeIds));
            },
        );

        $treeService = $this->createMock(IndustryTreeService::class);
        $this->service = new StockpileService(
            $targetRepository,
            $assetRepository,
            $jitaMarketService,
            $treeService,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(IndustryStockpileImportRepository::class),
            new StockpileStartingStockReducer($treeService),
        );
        $this->pilot = $this->createMock(User::class);
    }

    public function testStatusIsPartialFromExactlyFiftyPercentAndMetFromOneHundredPercentWithThePercentCappedAtOneHundred(): void
    {
        $this->addTarget(1, 'Exactly Half', 100, 'raw_material', stock: 50);
        $this->addTarget(2, 'Just Under Half', 100, 'raw_material', stock: 49);
        $this->addTarget(3, 'Almost Met', 100, 'raw_material', stock: 99);
        $this->addTarget(4, 'Exactly Met', 100, 'raw_material', stock: 100);
        $this->addTarget(5, 'Overstocked', 100, 'raw_material', stock: 150);

        $status = $this->service->getStockpileStatus($this->pilot);

        $items = $status['stages']['raw_material']['items'];
        self::assertSame([1 => 'partial', 2 => 'critical', 3 => 'partial', 4 => 'met', 5 => 'met'], array_column($items, 'status', 'typeId'));
        self::assertSame([1 => 50.0, 2 => 49.0, 3 => 99.0, 4 => 100.0, 5 => 100.0], array_column($items, 'percent', 'typeId'));
        self::assertSame(40.0, $status['stages']['raw_material']['healthPercent']);
        self::assertSame(40.0, $status['kpis']['pipelineHealth']);
        self::assertSame(5, $status['targetCount']);
    }

    public function testShoppingListKeepsTheTargetsBelowTheirQuantityFromTheLowestPercentKeepingTheTargetOrderOnTies(): void
    {
        $this->addTarget(1, 'Half A', 100, 'raw_material', stock: 50);
        $this->addTarget(2, 'Third', 3, 'component', stock: 1);
        $this->addTarget(3, 'Met', 10, 'raw_material', stock: 10);
        $this->addTarget(4, 'Half B', 10, 'intermediate', stock: 5);
        $this->addTarget(5, 'Nothing In Stock', 7, 'raw_material', stock: 0);

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame([5, 2, 1, 4], array_column($status['shoppingList'], 'typeId'));
        self::assertSame([0.0, 33.3, 50.0, 50.0], array_column($status['shoppingList'], 'percent'));
        self::assertSame(20.0, $status['kpis']['pipelineHealth']);
        self::assertSame(33.3, $status['stages']['raw_material']['healthPercent']);
        self::assertSame(0.0, $status['stages']['component']['healthPercent']);
    }

    public function testPercentJustBelowABoundIsTruncatedSoThatTheStatusAndTheStageHealthFollowTheDisplayedPercent(): void
    {
        $this->addTarget(1, 'Just Under Half', 10000, 'raw_material', stock: 4996);
        $this->addTarget(2, 'Just Under Met', 25000, 'component', stock: 24990);
        $this->addTarget(3, 'Exactly Met', 20000, 'component', stock: 20000);
        $this->addTarget(4, 'Met', 10, 'raw_material', stock: 10);

        $status = $this->service->getStockpileStatus($this->pilot);

        $justUnderHalf = $status['stages']['raw_material']['items'][0];
        self::assertSame([49.9, 'critical'], [$justUnderHalf['percent'], $justUnderHalf['status']]);
        $justUnderMet = $status['stages']['component']['items'][0];
        self::assertSame([99.9, 'partial'], [$justUnderMet['percent'], $justUnderMet['status']]);
        $exactlyMet = $status['stages']['component']['items'][1];
        self::assertSame([100.0, 'met'], [$exactlyMet['percent'], $exactlyMet['status']]);
        self::assertSame(50.0, $status['stages']['component']['healthPercent']);
        self::assertSame(50.0, $status['stages']['raw_material']['healthPercent']);
        self::assertSame(50.0, $status['kpis']['pipelineHealth']);
        self::assertSame([1, 2], array_column($status['shoppingList'], 'typeId'));
        self::assertSame(
            ['typeId' => 1, 'typeName' => 'Just Under Half', 'percent' => 49.9, 'blocksProducts' => 0],
            $status['kpis']['bottleneck'],
        );
    }

    public function testBottleneckIsTheFirstTargetWithTheLowestRoundedPercent(): void
    {
        $this->addTarget(1, 'First Empty', 10, 'raw_material', stock: 0);
        $this->addTarget(2, 'Second Empty', 10, 'raw_material', stock: 0);
        $this->addTarget(3, 'Slightly Above', 10000, 'raw_material', stock: 4);

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            ['typeId' => 1, 'typeName' => 'First Empty', 'percent' => 0.0, 'blocksProducts' => 0],
            $status['kpis']['bottleneck'],
        );
    }

    public function testBottleneckIsNullWhenEveryTargetIsMet(): void
    {
        $this->addTarget(1, 'Exactly Met', 10, 'raw_material', stock: 10);
        $this->addTarget(2, 'Overstocked', 10, 'component', stock: 20);

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertNull($status['kpis']['bottleneck']);
    }

    public function testStatusWithoutTargetsReturnsEmptyStagesAndFullHealth(): void
    {
        $emptyStage = ['items' => [], 'totalValue' => 0.0, 'healthPercent' => 100.0];

        self::assertSame(
            [
                'targetCount' => 0,
                'stages' => ['raw_material' => $emptyStage, 'intermediate' => $emptyStage, 'component' => $emptyStage, 'final_product' => $emptyStage],
                'kpis' => [
                    'pipelineHealth' => 100.0,
                    'totalInvested' => 0.0,
                    'bottleneck' => null,
                    'estOutput' => ['ready' => 0, 'total' => 0, 'readyNames' => []],
                ],
                'shoppingList' => [],
                'importsWithoutPlan' => [],
                'failedProducts' => [],
            ],
            $this->service->getStockpileStatus($this->pilot),
        );
        self::assertSame(0, $this->jitaPriceRequests);
    }

    public function testItemCarriesTheStockValueAndTheDeficitCostAtTheJitaPriceRoundedToTwoDecimals(): void
    {
        $this->addTarget(34, 'Tritanium', 120, 'intermediate', stock: 45, unitPrice: 0.125, source: self::WIDGET_SOURCE);

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            [
                'id' => '00000000-0000-4000-8000-000000000034',
                'typeId' => 34,
                'typeName' => 'Tritanium',
                'targetQuantity' => 120,
                'remainingTargetQuantity' => 120,
                'stock' => 45,
                'percent' => 37.5,
                'status' => 'critical',
                'unitPrice' => 0.125,
                'stockValue' => 5.63,
                'deficitCost' => 9.38,
                'stage' => 'intermediate',
                'sourceProductTypeId' => self::WIDGET_SOURCE,
                'locations' => [['locationId' => 60003760, 'locationName' => self::JITA_LOCATION_NAME, 'systemName' => 'Jita', 'quantity' => 45]],
            ],
            $status['stages']['intermediate']['items'][0],
        );
        self::assertSame($status['stages']['intermediate']['items'], $status['shoppingList']);
    }

    public function testOverstockedTargetHasNoDeficitCostAndItsWholeStockCountsInTheStockValue(): void
    {
        $this->addTarget(35, 'Pyerite', 100, 'raw_material', stock: 150, unitPrice: 2.5);

        $status = $this->service->getStockpileStatus($this->pilot);

        $item = $status['stages']['raw_material']['items'][0];
        self::assertSame([375.0, 0.0], [$item['stockValue'], $item['deficitCost']]);
        self::assertSame(375.0, $status['kpis']['totalInvested']);
    }

    public function testStageTotalValueSumsTheRoundedStockValuesWhileTotalInvestedRoundsTheRawSum(): void
    {
        $this->addTarget(34, 'Tritanium', 120, 'intermediate', stock: 45, unitPrice: 0.125);
        $this->addTarget(35, 'Pyerite', 120, 'intermediate', stock: 45, unitPrice: 0.375);
        $this->addTarget(36, 'Mexallon', 10, 'component', stock: 3, unitPrice: 0.0625);

        $status = $this->service->getStockpileStatus($this->pilot);

        // 5.63 + 16.88 (5.625 and 16.875 rounded per item)
        self::assertSame(22.51, $status['stages']['intermediate']['totalValue']);
        self::assertSame(0.19, $status['stages']['component']['totalValue']);
        self::assertSame(0.0, $status['stages']['raw_material']['totalValue']);
        // 5.625 + 16.875 + 0.1875 = 22.6875, whereas the rounded stock values add up to 22.70
        self::assertSame(22.69, $status['kpis']['totalInvested']);
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #8 (R10) : un item sans prix Jita vaut 0 ISK
    // (unitPrice 0.0, stockValue 0.0, deficitCost 0.0) au lieu d'un coût inconnu ; totalInvested l'ignore.
    public function testTargetWithoutJitaPriceIsValuedAtZeroIssue8(): void
    {
        $this->addTarget(57486, 'Unpriced Component', 100, 'component', stock: 40);
        $this->addTarget(36, 'Mexallon', 10, 'raw_material', stock: 4, unitPrice: 50.0);

        $status = $this->service->getStockpileStatus($this->pilot);

        $unpriced = $status['stages']['component']['items'][0];
        self::assertSame([0.0, 0.0, 0.0], [$unpriced['unitPrice'], $unpriced['stockValue'], $unpriced['deficitCost']]);
        self::assertSame(0.0, $status['stages']['component']['totalValue']);
        self::assertSame(200.0, $status['kpis']['totalInvested']);
    }

    public function testTargetWithoutAssetsHasNoStockNoLocationAndTheWholeTargetAsDeficit(): void
    {
        $this->targets[] = $this->target(37, 'Isogen', 30, 'raw_material', self::WIDGET_SOURCE);
        $this->jitaPrices[37] = 40.0;

        $status = $this->service->getStockpileStatus($this->pilot);

        $item = $status['stages']['raw_material']['items'][0];
        self::assertSame([0, [], 0.0, 'critical', 0.0, 1200.0], [$item['stock'], $item['locations'], $item['percent'], $item['status'], $item['stockValue'], $item['deficitCost']]);
    }

    public function testTargetNotYetPersistedHasNoId(): void
    {
        $this->targets[] = (new IndustryStockpileTarget())->setTypeId(38)->setTypeName('Nocxium')->setTargetQuantity(5)->setStage('raw_material');

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertNull($status['stages']['raw_material']['items'][0]['id']);
    }

    public function testBottleneckBlocksEveryFinalProductImportedFromTheSameSource(): void
    {
        $this->addTarget(1, 'Shared Ore', 10, 'raw_material', stock: 1, source: self::WIDGET_SOURCE);
        $this->addTarget(self::WIDGET_SOURCE, 'Widget', 1, 'final_product', stock: 1, source: self::WIDGET_SOURCE);
        $this->addTarget(101, 'Gadget', 1, 'final_product', stock: 1, source: self::WIDGET_SOURCE);
        $this->addTarget(201, 'Other Product', 1, 'final_product', stock: 1, source: 201);

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(2, $status['kpis']['bottleneck']['blocksProducts']);
    }

    public function testBottleneckWithoutSourceProductBlocksNothing(): void
    {
        $this->addTarget(1, 'Manual Ore', 10, 'raw_material', stock: 1);
        $this->addTarget(self::WIDGET_SOURCE, 'Widget', 1, 'final_product', stock: 1, source: self::WIDGET_SOURCE);
        $this->addTarget(300, 'Manual Widget', 1, 'final_product', stock: 1);

        self::assertSame(0, $this->service->getStockpileStatus($this->pilot)['kpis']['bottleneck']['blocksProducts']);
    }

    public function testEstOutputCountsAFinalProductReadyWhenEveryUpstreamTargetIsMetWithoutTheProductInStock(): void
    {
        $this->addTarget(110, 'Widget A', 1, 'final_product', stock: 0, source: 110);
        $this->addTarget(11, 'Ore A', 10, 'raw_material', stock: 10, source: 110);

        self::assertSame(['ready' => 1, 'total' => 1, 'readyNames' => ['Widget A']], $this->service->getStockpileStatus($this->pilot)['kpis']['estOutput']);
    }

    public function testEstOutputCountsAFinalProductReadyWhenItsOwnTargetIsMetDespiteAnIncompleteUpstreamTarget(): void
    {
        $this->addTarget(140, 'Widget D', 1, 'final_product', stock: 1, source: 140);
        $this->addTarget(14, 'Ore D', 10000, 'raw_material', stock: 9901, source: 140);

        self::assertSame(['ready' => 1, 'total' => 1, 'readyNames' => ['Widget D']], $this->service->getStockpileStatus($this->pilot)['kpis']['estOutput']);
    }

    public function testEstOutputCountsAFinalProductInStockReadyWhenItsUpstreamTypeHasNoAssets(): void
    {
        $this->addTarget(130, 'Widget C', 1, 'final_product', stock: 1, source: 130);
        $this->targets[] = $this->target(13, 'Ore C', 1, 'raw_material', 130);

        self::assertSame(['ready' => 1, 'total' => 1, 'readyNames' => ['Widget C']], $this->service->getStockpileStatus($this->pilot)['kpis']['estOutput']);
    }

    public function testEstOutputCountsAnUpstreamTargetOfZeroQuantityAsMet(): void
    {
        $this->addTarget(120, 'Widget B', 2, 'final_product', stock: 2, source: 120);
        $this->addTarget(12, 'Ore B', 0, 'raw_material', stock: 0, source: 120);

        self::assertSame(['ready' => 1, 'total' => 1, 'readyNames' => ['Widget B']], $this->service->getStockpileStatus($this->pilot)['kpis']['estOutput']);
    }

    public function testEstOutputExcludesAFinalProductWithoutSourceFromTheTotal(): void
    {
        $this->addTarget(300, 'Manual Widget', 1, 'final_product', stock: 5);

        self::assertSame(['ready' => 0, 'total' => 0, 'readyNames' => []], $this->service->getStockpileStatus($this->pilot)['kpis']['estOutput']);
    }

    public function testEstOutputCountsAFinalProductNotReadyWhenBothItsOwnStockAndItsUpstreamAreIncomplete(): void
    {
        $this->addTarget(150, 'Widget E', 2, 'final_product', stock: 1, source: 150);
        $this->addTarget(15, 'Ore E', 10, 'raw_material', stock: 9, source: 150);

        self::assertSame(['ready' => 0, 'total' => 1, 'readyNames' => []], $this->service->getStockpileStatus($this->pilot)['kpis']['estOutput']);
    }

    public function testEstOutputCountsAFinalProductWithASourceButNoUpstreamTargetReady(): void
    {
        $this->addTarget(160, 'Widget F', 1, 'final_product', stock: 0, source: 160);

        self::assertSame(['ready' => 1, 'total' => 1, 'readyNames' => ['Widget F']], $this->service->getStockpileStatus($this->pilot)['kpis']['estOutput']);
    }

    public function testEstOutputCountsAFinalProductNotReadyWhenAnyOfItsUpstreamTargetsIsIncomplete(): void
    {
        $this->addTarget(170, 'Widget G', 1, 'final_product', stock: 0, source: 170);
        $this->addTarget(171, 'Ore G1', 10, 'raw_material', stock: 10, source: 170);
        $this->addTarget(172, 'Ore G2', 5, 'raw_material', stock: 4, source: 170);

        self::assertSame(['ready' => 0, 'total' => 1, 'readyNames' => []], $this->service->getStockpileStatus($this->pilot)['kpis']['estOutput']);
    }

    public function testEstOutputCountsEveryImportedFinalProductBuildableOrInStockInTargetOrder(): void
    {
        $this->addTarget(500, 'Widget D', 1, 'final_product', stock: 1, source: 500);
        $this->addTarget(4, 'Ore D', 10000, 'raw_material', stock: 9901, source: 500);
        $this->addTarget(200, 'Widget B', 2, 'final_product', stock: 2, source: 200);
        $this->addTarget(2, 'Ore B', 0, 'raw_material', stock: 0, source: 200);
        $this->addTarget(300, 'Manual Widget', 1, 'final_product', stock: 5);
        $this->addTarget(self::WIDGET_SOURCE, 'Widget A', 1, 'final_product', stock: 0, source: self::WIDGET_SOURCE);
        $this->addTarget(1, 'Ore A', 10, 'raw_material', stock: 10, source: self::WIDGET_SOURCE);
        $this->addTarget(400, 'Widget C', 1, 'final_product', stock: 1, source: 400);
        $this->targets[] = $this->target(3, 'Ore C', 1, 'raw_material', 400);

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(['ready' => 4, 'total' => 4, 'readyNames' => ['Widget D', 'Widget B', 'Widget A', 'Widget C']], $status['kpis']['estOutput']);
    }

    public function testEstOutputCountsAFinalProductNotReadyWhenAnUpstreamTypeHasNoAssets(): void
    {
        $this->addTarget(190, 'Widget', 1, 'final_product', stock: 0, source: 190);
        $this->targets[] = $this->target(19, 'Ore', 1, 'raw_material', 190);

        self::assertSame(['ready' => 0, 'total' => 1, 'readyNames' => []], $this->service->getStockpileStatus($this->pilot)['kpis']['estOutput']);
    }

    public function testEstOutputCountsAFinalProductWithoutAssetsNotReadyWhenItsUpstreamIsIncomplete(): void
    {
        $this->targets[] = $this->target(220, 'Widget I', 1, 'final_product', 220);
        $this->addTarget(22, 'Ore I', 10, 'raw_material', stock: 9, source: 220);

        self::assertSame(['ready' => 0, 'total' => 1, 'readyNames' => []], $this->service->getStockpileStatus($this->pilot)['kpis']['estOutput']);
    }

    public function testEstOutputCountsAFinalProductOfZeroQuantityWithoutAssetsReadyDespiteAnIncompleteUpstream(): void
    {
        $this->targets[] = $this->target(230, 'Widget J', 0, 'final_product', 230);
        $this->addTarget(23, 'Ore J', 15, 'raw_material', stock: 14, source: 230);

        self::assertSame(['ready' => 1, 'total' => 1, 'readyNames' => ['Widget J']], $this->service->getStockpileStatus($this->pilot)['kpis']['estOutput']);
    }

    public function testEstOutputCountsAnUpstreamTargetOfZeroQuantityWithoutAssetsAsMet(): void
    {
        $this->addTarget(210, 'Widget', 1, 'final_product', stock: 0, source: 210);
        $this->targets[] = $this->target(21, 'Ore', 0, 'raw_material', 210);

        self::assertSame(['ready' => 1, 'total' => 1, 'readyNames' => ['Widget']], $this->service->getStockpileStatus($this->pilot)['kpis']['estOutput']);
    }

    public function testEstOutputCountsAFinalProductJustBelowItsTargetNotReadyWhenItsUpstreamIsIncomplete(): void
    {
        $this->addTarget(180, 'Widget H', 1000, 'final_product', stock: 999, source: 180);
        $this->addTarget(18, 'Ore H', 20, 'raw_material', stock: 19, source: 180);

        self::assertSame(['ready' => 0, 'total' => 1, 'readyNames' => []], $this->service->getStockpileStatus($this->pilot)['kpis']['estOutput']);
    }

    public function testEstOutputWithoutTargetsIsEmpty(): void
    {
        self::assertSame(['ready' => 0, 'total' => 0, 'readyNames' => []], $this->service->getStockpileStatus($this->pilot)['kpis']['estOutput']);
    }

    private function addTarget(int $typeId, string $typeName, int $targetQuantity, string $stage, int $stock, ?float $unitPrice = null, ?int $source = null): void
    {
        $this->targets[] = $this->target($typeId, $typeName, $targetQuantity, $stage, $source);
        $this->stockByTypeId[$typeId] = [
            'total' => $stock,
            'locations' => [['locationId' => 60003760, 'locationName' => self::JITA_LOCATION_NAME, 'systemName' => 'Jita', 'quantity' => $stock]],
        ];
        if ($unitPrice !== null) {
            $this->jitaPrices[$typeId] = $unitPrice;
        }
    }

    private function target(int $typeId, string $typeName, int $targetQuantity, string $stage, ?int $source): IndustryStockpileTarget
    {
        $target = (new IndustryStockpileTarget())
            ->setUser($this->pilot)
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setTargetQuantity($targetQuantity)
            ->setStage($stage)
            ->setSourceProductTypeId($source);
        (new \ReflectionProperty(IndustryStockpileTarget::class, 'id'))
            ->setValue($target, Uuid::fromString(sprintf('00000000-0000-4000-8000-%012d', $typeId)));

        return $target;
    }
}
