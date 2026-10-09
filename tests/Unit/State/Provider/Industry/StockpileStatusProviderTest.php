<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\Entity\IndustryStockpileTarget;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\Service\Industry\IndustryTreeService;
use App\Service\Industry\StockpileService;
use App\Service\JitaMarketService;
use App\State\Provider\Industry\StockpileStatusProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(StockpileStatusProvider::class)]
#[AllowMockObjectsWithoutExpectations]
final class StockpileStatusProviderTest extends TestCase
{
    private Security $security;
    private StockpileStatusProvider $provider;

    protected function setUp(): void
    {
        $ore = (new IndustryStockpileTarget())
            ->setTypeId(34)
            ->setTypeName('Tritanium')
            ->setTargetQuantity(1000)
            ->setStage('raw_material')
            ->setSourceProductTypeId(4051);
        $targetRepository = $this->createMock(IndustryStockpileTargetRepository::class);
        $targetRepository->method('findByUser')->willReturn([$ore]);
        $assetRepository = $this->createMock(CachedAssetRepository::class);
        $assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([34 => ['total' => 400, 'locations' => []]]);
        $jitaMarketService = $this->createMock(JitaMarketService::class);
        $jitaMarketService->method('getPrices')->willReturn([34 => 4.0]);

        $this->security = $this->createStub(Security::class);
        $this->provider = new StockpileStatusProvider($this->security, new StockpileService(
            $targetRepository,
            $assetRepository,
            $jitaMarketService,
            $this->createMock(IndustryTreeService::class),
            $this->createMock(EntityManagerInterface::class),
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
            'stock' => 400,
            'percent' => 40.0,
            'status' => 'critical',
            'unitPrice' => 4.0,
            'stockValue' => 1600.0,
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
    }

    public function testRejectsAnAnonymousRequest(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);

        $this->provider->provide(new Get());
    }
}
