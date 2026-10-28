<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\Entity\IndustryStockpileImport;
use App\Entity\IndustryStockpileTarget;
use App\Entity\User;
use App\Industry\Application\ProductionPlanning;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryStockpileImportRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\StockpileService;
use App\Service\Industry\StockpileStartingStockReducer;
use App\Service\JitaMarketService;
use App\State\Provider\Industry\StockpileStatusProvider;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(StockpileStatusProvider::class)]
#[AllowMockObjectsWithoutExpectations]
final class StockpileStatusProviderTest extends TestCase
{
    private const int TRITANIUM_TARGET = 1000;
    private const int PYERITE_TARGET = 600;

    private Security $security;
    private StockpileStatusProvider $provider;
    private array $targets = [];
    private array $imports = [];
    private array $jitaPrices = [34 => 4.0, 35 => 9.5];

    protected function setUp(): void
    {
        $this->targets = [(new IndustryStockpileTarget())
            ->setTypeId(34)
            ->setTypeName('Tritanium')
            ->setTargetQuantity(self::TRITANIUM_TARGET)
            ->setStage('raw_material')
            ->setSourceProductTypeId(4051)];
        $targetRepository = $this->createMock(IndustryStockpileTargetRepository::class);
        $targetRepository->method('findByUser')->willReturnCallback(fn (): array => $this->targets);
        $assetRepository = $this->createMock(CachedAssetRepository::class);
        $assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([
            34 => ['total' => 400, 'locations' => []],
            35 => ['total' => 150, 'locations' => []],
        ]);
        $jitaMarketService = $this->createMock(JitaMarketService::class);
        $jitaMarketService->method('getPricesWithFallback')->willReturnCallback(
            fn (array $typeIds): array => array_intersect_key($this->jitaPrices, array_flip($typeIds)),
        );
        $productionPlanning = new ProductionPlanning(new InMemoryBlueprintCatalog(), InMemorySystemCostIndices::none(), new InMemoryRigCategories());
        $userStructures = new InMemoryUserProductionStructures();
        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $importRepository = $this->createMock(IndustryStockpileImportRepository::class);
        $importRepository->method('findByUserInCreationOrder')->willReturnCallback(fn (): array => $this->imports);

        $this->security = $this->createStub(Security::class);
        $this->provider = new StockpileStatusProvider($this->security, new StockpileService(
            $targetRepository,
            $assetRepository,
            $jitaMarketService,
            $productionPlanning,
            $userStructures,
            $productRepository,
            $this->createMock(EntityManagerInterface::class),
            $importRepository,
            new StockpileStartingStockReducer($productionPlanning, $userStructures, $productRepository, new NullLogger()),
        ));
    }

    public function testExposesTheStockpileStatusOfTheConnectedPilot(): void
    {
        $this->security->method('getUser')->willReturn($this->createMock(User::class));

        $resource = $this->provider->provide(new Get());

        $tritanium = [
            'id' => null,
            'typeId' => 34,
            'typeName' => 'Tritanium',
            'targetQuantity' => 1000,
            'remainingTargetQuantity' => 1000,
            'stock' => 400,
            'percent' => 40.0,
            'status' => 'critical',
            'unitPrice' => 4.0,
            'stockValue' => 1600.0,
            'deficit' => 600,
            'deficitCost' => 2400.0,
            'stage' => 'raw_material',
            'sourceProductTypeId' => 4051,
            'locations' => [],
        ];
        self::assertSame(1, $resource->targetCount);
        self::assertSame(
            [
                'raw_material' => ['items' => [$tritanium], 'totalValue' => 1600.0, 'healthPercent' => 0.0],
                'intermediate' => ['items' => [], 'totalValue' => 0.0, 'healthPercent' => 100.0],
                'component' => ['items' => [], 'totalValue' => 0.0, 'healthPercent' => 100.0],
                'final_product' => ['items' => [], 'totalValue' => 0.0, 'healthPercent' => 100.0],
            ],
            $resource->stages,
        );
        self::assertSame(
            [
                'pipelineHealth' => 0.0,
                'totalInvested' => 1600.0,
                'bottleneck' => ['typeId' => 34, 'typeName' => 'Tritanium', 'percent' => 40.0, 'blocksProducts' => 0],
                'estOutput' => ['ready' => 0, 'total' => 0, 'readyNames' => []],
            ],
            $resource->kpis,
        );
        self::assertSame([$tritanium], $resource->shoppingList);
        self::assertSame([4051], $resource->importsWithoutPlan);
        self::assertSame([], $resource->failedProducts);
        self::assertSame([], $resource->missingPriceTypeIds);
    }

    #[Group('issue-8')]
    public function testExposesTheTypesWithoutJitaPriceAndTheUnknownAmountsOfTheConnectedPilot(): void
    {
        $this->targets[] = (new IndustryStockpileTarget())
            ->setTypeId(35)
            ->setTypeName('Pyerite')
            ->setTargetQuantity(self::PYERITE_TARGET)
            ->setStage('raw_material');
        $this->jitaPrices = [34 => 4.0];
        $this->security->method('getUser')->willReturn($this->createMock(User::class));

        $resource = $this->provider->provide(new Get());

        self::assertSame([35], $resource->missingPriceTypeIds);
        self::assertSame(
            [34 => 1600.0, 35 => null],
            array_column($resource->stages['raw_material']['items'], 'stockValue', 'typeId'),
        );
        self::assertNull($resource->stages['raw_material']['totalValue']);
        self::assertNull($resource->kpis['totalInvested']);
    }

    public function testExposesTheRemainingTargetEqualToTheTargetQuantityAndNoImportWarningWithoutReduction(): void
    {
        $this->targets = [(new IndustryStockpileTarget())
            ->setTypeId(35)
            ->setTypeName('Pyerite')
            ->setTargetQuantity(self::PYERITE_TARGET)
            ->setStage('raw_material')];
        $this->security->method('getUser')->willReturn($this->createMock(User::class));

        $resource = $this->provider->provide(new Get());

        self::assertSame(
            [35 => 600],
            array_column($resource->stages['raw_material']['items'], 'remainingTargetQuantity', 'typeId'),
        );
        self::assertSame([35 => 600], array_column($resource->shoppingList, 'remainingTargetQuantity', 'typeId'));
        self::assertSame([], $resource->importsWithoutPlan);
        self::assertSame([], $resource->failedProducts);
    }

    #[Group('issue-258')]
    public function testExposesTheReasonCodeOfAnImportedProductWithoutBlueprintNorReaction(): void
    {
        $pilot = $this->createMock(User::class);
        $this->imports = [new IndustryStockpileImport($pilot, 4051, 3, 10)];
        $this->security->method('getUser')->willReturn($pilot);

        $resource = $this->provider->provide(new Get());

        self::assertSame([], $resource->importsWithoutPlan);
        self::assertSame([['typeId' => 4051, 'reason' => 'no_blueprint']], $resource->failedProducts);
    }

    public function testRejectsAnAnonymousRequest(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);

        $this->provider->provide(new Get());
    }
}
