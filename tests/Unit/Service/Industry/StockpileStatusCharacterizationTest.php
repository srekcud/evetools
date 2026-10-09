<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryStockpileTarget;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\Service\Industry\IndustryTreeService;
use App\Service\Industry\StockpileService;
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

        $this->service = new StockpileService(
            $targetRepository,
            $assetRepository,
            $jitaMarketService,
            $this->createMock(IndustryTreeService::class),
            $this->createMock(EntityManagerInterface::class),
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

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue à créer : le statut est calculé sur le
    // pourcentage brut, l'étape et l'affichage sur le pourcentage arrondi. 49,96 % s'affiche 50.0 en « critical » ;
    // 99,96 % s'affiche 100.0 en « partial », compte comme rempli dans healthPercent de l'étape mais pas dans
    // pipelineHealth, et reste le goulot (bottleneck) et une ligne de la liste d'achats.
    public function testPercentRoundedToABoundKeepsTheStatusOfTheRawPercentButCountsAsMetInTheStageHealth(): void
    {
        $this->addTarget(1, 'Rounded Up To Half', 10000, 'raw_material', stock: 4996);
        $this->addTarget(2, 'Rounded Up To Met', 10000, 'component', stock: 9996);
        $this->addTarget(3, 'Met', 10, 'raw_material', stock: 10);

        $status = $this->service->getStockpileStatus($this->pilot);

        $roundedToHalf = $status['stages']['raw_material']['items'][0];
        self::assertSame([50.0, 'critical'], [$roundedToHalf['percent'], $roundedToHalf['status']]);
        $roundedToMet = $status['stages']['component']['items'][0];
        self::assertSame([100.0, 'partial'], [$roundedToMet['percent'], $roundedToMet['status']]);
        self::assertSame(100.0, $status['stages']['component']['healthPercent']);
        self::assertSame(50.0, $status['stages']['raw_material']['healthPercent']);
        self::assertSame(33.3, $status['kpis']['pipelineHealth']);
        self::assertSame([1, 2], array_column($status['shoppingList'], 'typeId'));
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

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #20 (R9) : le stock partiel d'un intermédiaire
    // ne réduit pas les cibles de son sous-arbre. Avec 5 Frames sur 10 en stock, les 700 Ore nécessaires aux 10 Frames
    // restent dus en entier (deficitCost 1400.0) ; le goulot est le produit final, premier à 0 %.
    public function testPartialStockOfAnIntermediateDoesNotReduceTheTargetsBelowItIssue20(): void
    {
        $this->addTarget(self::WIDGET_SOURCE, 'Widget', 1, 'final_product', stock: 0, unitPrice: 1000.0, source: self::WIDGET_SOURCE);
        $this->addTarget(2, 'Frame', 10, 'intermediate', stock: 5, unitPrice: 50.0, source: self::WIDGET_SOURCE);
        $this->addTarget(1, 'Ore', 700, 'raw_material', stock: 0, unitPrice: 2.0, source: self::WIDGET_SOURCE);

        $status = $this->service->getStockpileStatus($this->pilot);

        $ore = $status['stages']['raw_material']['items'][0];
        self::assertSame([700, 0.0, 'critical', 1400.0], [$ore['targetQuantity'], $ore['percent'], $ore['status'], $ore['deficitCost']]);
        $frame = $status['stages']['intermediate']['items'][0];
        self::assertSame([50.0, 'partial', 250.0, 250.0], [$frame['percent'], $frame['status'], $frame['stockValue'], $frame['deficitCost']]);
        self::assertSame([self::WIDGET_SOURCE, 1, 2], array_column($status['shoppingList'], 'typeId'));
        self::assertSame(
            ['typeId' => self::WIDGET_SOURCE, 'typeName' => 'Widget', 'percent' => 0.0, 'blocksProducts' => 1],
            $status['kpis']['bottleneck'],
        );
        self::assertSame(['ready' => 0, 'total' => 1, 'readyNames' => []], $status['kpis']['estOutput']);
        self::assertSame(250.0, $status['kpis']['totalInvested']);
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

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue à créer : un produit final n'est « prêt »
    // (estOutput) que si sa propre cible est aussi en stock ; tous ses matériaux en stock ne suffisent pas.
    // Un produit final sans source compte dans le total sans jamais être prêt.
    public function testEstOutputCountsAFinalProductReadyOnlyWhenItsOwnTargetIsAlsoInStock(): void
    {
        $this->addTarget(self::WIDGET_SOURCE, 'Widget A', 1, 'final_product', stock: 0, source: self::WIDGET_SOURCE);
        $this->addTarget(1, 'Ore A', 10, 'raw_material', stock: 10, source: self::WIDGET_SOURCE);
        $this->addTarget(300, 'Manual Widget', 1, 'final_product', stock: 5);
        $this->addTarget(200, 'Widget B', 2, 'final_product', stock: 2, source: 200);
        $this->addTarget(2, 'Ore B', 0, 'raw_material', stock: 0, source: 200);
        $this->addTarget(400, 'Widget C', 1, 'final_product', stock: 1, source: 400);
        $this->targets[] = $this->target(3, 'Ore C Without Assets', 1, 'raw_material', 400);
        $this->addTarget(500, 'Widget D', 1, 'final_product', stock: 1, source: 500);
        $this->addTarget(4, 'Ore D', 10000, 'raw_material', stock: 9901, source: 500);

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(['ready' => 1, 'total' => 5, 'readyNames' => ['Widget B']], $status['kpis']['estOutput']);
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
