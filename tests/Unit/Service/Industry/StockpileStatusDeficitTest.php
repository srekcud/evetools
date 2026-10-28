<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryStockpileTarget;
use App\Entity\User;
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
final class StockpileStatusDeficitTest extends TestCase
{
    use BuildsStockpileOnWidgetSde;

    private const int ONE_RUN = 1;
    private const int ME_ZERO = 0;

    private const int WIDGET_TARGET = 1;
    private const int FRAME_TARGET = 10;
    private const int ORE_TARGET = 700;
    private const int MANUAL_ORE_TARGET = 10;
    private const int MANUAL_FRAME_TARGET = 7;
    private const int MANUAL_GADGET_TARGET = 3;

    private const array JITA_PRICES = [
        self::WIDGET => 1000.0,
        self::FRAME => 50.0,
        self::ORE => 2.0,
    ];

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

    public function testTargetWithoutAnyAssetHasTheWholeRemainingTargetAsDeficitAndIsInTheShoppingList(): void
    {
        $this->addManualTarget(self::ORE, self::MANUAL_ORE_TARGET, 'raw_material');

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame([self::ORE => 10], self::deficitByTypeIdInStages($status));
        self::assertSame([self::ORE => 10], self::deficitByTypeIdInShoppingList($status));
    }

    public function testPartlyStockedTargetsHaveTheirRemainingTargetMinusTheirStockAsDeficit(): void
    {
        $this->addManualTarget(self::ORE, self::MANUAL_ORE_TARGET, 'raw_material');
        $this->addManualTarget(self::FRAME, self::MANUAL_FRAME_TARGET, 'component');
        $this->stockByTypeId = [self::ORE => 4, self::FRAME => 2];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame([self::ORE => 6, self::FRAME => 5], self::deficitByTypeIdInStages($status));
        self::assertSame([self::FRAME => 5, self::ORE => 6], self::deficitByTypeIdInShoppingList($status));
        self::assertSame(
            ['deficit' => 6, 'deficitCost' => 12.0],
            $this->fieldsOf($status, self::ORE, ['deficit', 'deficitCost']),
        );
    }

    public function testTargetOneUnitShortOfItsRemainingTargetHasADeficitOfOne(): void
    {
        $this->addManualTarget(self::ORE, self::MANUAL_ORE_TARGET, 'raw_material');
        $this->stockByTypeId = [self::ORE => 9];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame([self::ORE => 1], self::deficitByTypeIdInStages($status));
        self::assertSame([self::ORE => 1], self::deficitByTypeIdInShoppingList($status));
    }

    public function testMetTargetHasNoDeficitAndLeavesTheShoppingList(): void
    {
        $this->addManualTarget(self::ORE, self::MANUAL_ORE_TARGET, 'raw_material');
        $this->stockByTypeId = [self::ORE => 10];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame([self::ORE => 0], self::deficitByTypeIdInStages($status));
        self::assertSame([], $status['shoppingList']);
    }

    public function testOverstockedTargetHasADeficitOfZeroRatherThanANegativeOne(): void
    {
        $this->addManualTarget(self::ORE, self::MANUAL_ORE_TARGET, 'raw_material');
        $this->stockByTypeId = [self::ORE => 25];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame([self::ORE => 0], self::deficitByTypeIdInStages($status));
        self::assertSame([], $status['shoppingList']);
    }

    public function testDeficitOfTheOreBelowAPartlyStockedIntermediateIsMeasuredAgainstItsRemainingTarget(): void
    {
        $this->imports = [$this->stockpileImport($this->pilot, self::WIDGET, self::ONE_RUN, self::ME_ZERO)];
        $this->addTarget(self::WIDGET, self::WIDGET_TARGET, 'final_product', self::WIDGET);
        $this->addTarget(self::FRAME, self::FRAME_TARGET, 'component', self::WIDGET);
        $this->addTarget(self::ORE, self::ORE_TARGET, 'raw_material', self::WIDGET);
        $this->stockByTypeId = [self::FRAME => 5, self::ORE => 100];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(
            ['targetQuantity' => 700, 'remainingTargetQuantity' => 350, 'stock' => 100, 'deficit' => 250, 'deficitCost' => 500.0],
            $this->fieldsOf($status, self::ORE, ['targetQuantity', 'remainingTargetQuantity', 'stock', 'deficit', 'deficitCost']),
        );
        self::assertSame([self::ORE => 250, self::FRAME => 5, self::WIDGET => 1], self::deficitByTypeIdInStages($status));
        self::assertSame([self::WIDGET => 1, self::ORE => 250, self::FRAME => 5], self::deficitByTypeIdInShoppingList($status));
    }

    public function testTargetWithoutJitaPriceStillHasItsQuantityAsDeficit(): void
    {
        $this->addManualTarget(self::GADGET, self::MANUAL_GADGET_TARGET, 'final_product');
        $this->stockByTypeId = [self::GADGET => 1];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame([self::GADGET => 2], self::deficitByTypeIdInStages($status));
        self::assertSame([self::GADGET => 2], self::deficitByTypeIdInShoppingList($status));
    }

    public function testTargetOfQuantityZeroHasNoDeficitAndStaysOutOfTheShoppingList(): void
    {
        $this->addManualTarget(self::ORE, 0, 'raw_material');

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame([self::ORE => 0], self::deficitByTypeIdInStages($status));
        self::assertSame([], $status['shoppingList']);
    }

    private function addManualTarget(int $typeId, int $targetQuantity, string $stage): void
    {
        $this->addTarget($typeId, $targetQuantity, $stage, null);
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

    private static function deficitByTypeIdInStages(array $status): array
    {
        $items = array_merge(...array_column($status['stages'], 'items'));

        return array_column($items, 'deficit', 'typeId');
    }

    private static function deficitByTypeIdInShoppingList(array $status): array
    {
        return array_column($status['shoppingList'], 'deficit', 'typeId');
    }

    private function fieldsOf(array $status, int $typeId, array $keys): array
    {
        foreach ($status['stages'] as $stage) {
            foreach ($stage['items'] as $item) {
                if ($item['typeId'] === $typeId) {
                    return array_intersect_key(array_merge(array_flip($keys), $item), $item, array_flip($keys));
                }
            }
        }
        self::fail("No stockpile item for type {$typeId}");
    }
}
