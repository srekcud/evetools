<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\StockpileImportPreviewResource;
use App\ApiResource\Input\Industry\StockpileImportInput;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryStockpileImportRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\Service\Industry\IndustryTreeService;
use App\Service\Industry\StockpileService;
use App\Service\Industry\StockpileStartingStockReducer;
use App\Service\JitaMarketService;
use App\State\Processor\Industry\StockpileImportPreviewProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(StockpileImportPreviewProcessor::class)]
final class StockpileImportPreviewProcessorTest extends TestCase
{
    private const int WIDGET = 3001;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int TRITANIUM_QUANTITY = 500;
    private const int PYERITE_QUANTITY = 200;

    private array $jitaPrices = [];

    private StockpileImportPreviewProcessor $processor;

    protected function setUp(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createStub(User::class));

        $treeService = $this->createStub(IndustryTreeService::class);
        $treeService->method('buildProductionTree')->willReturn([
            'productTypeName' => 'Widget',
            'runs' => 1,
            'outputPerRun' => 1,
            'materials' => [
                ['typeId' => self::TRITANIUM, 'typeName' => 'Tritanium', 'quantity' => self::TRITANIUM_QUANTITY, 'isBuildable' => false],
                ['typeId' => self::PYERITE, 'typeName' => 'Pyerite', 'quantity' => self::PYERITE_QUANTITY, 'isBuildable' => false],
            ],
        ]);

        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPricesWithFallback')->willReturnCallback(function (array $typeIds): array {
            $prices = [];
            foreach ($typeIds as $typeId) {
                $prices[$typeId] = $this->jitaPrices[$typeId] ?? null;
            }

            return $prices;
        });

        $this->processor = new StockpileImportPreviewProcessor($security, new StockpileService(
            $this->createStub(IndustryStockpileTargetRepository::class),
            $this->createStub(CachedAssetRepository::class),
            $jitaMarketService,
            $treeService,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(IndustryStockpileImportRepository::class),
            new StockpileStartingStockReducer($treeService),
        ));
    }

    #[Group('issue-8')]
    public function testExposesTheEstimatedCostOfAFullyPricedPreviewWithoutMissingPrice(): void
    {
        $this->jitaPrices = [self::WIDGET => 1_000_000.0, self::TRITANIUM => 4.0, self::PYERITE => 9.5];

        $preview = $this->preview();

        self::assertSame(3, $preview->totalItems);
        self::assertSame(1_003_900.0, $preview->estimatedCost);
        self::assertSame([], $preview->missingPriceTypeIds);
    }

    #[Group('issue-8')]
    public function testExposesAnUnknownEstimatedCostAndTheTypesWithoutJitaPrice(): void
    {
        $this->jitaPrices = [self::WIDGET => 1_000_000.0, self::TRITANIUM => 4.0];

        $preview = $this->preview();

        self::assertSame(
            [self::TRITANIUM => 4.0, self::PYERITE => null],
            array_column($preview->stages['raw_material'], 'unitPrice', 'typeId'),
        );
        self::assertNull($preview->estimatedCost);
        self::assertSame([self::PYERITE], $preview->missingPriceTypeIds);
    }

    private function preview(): StockpileImportPreviewResource
    {
        $input = new StockpileImportInput();
        $input->typeId = self::WIDGET;
        $input->runs = 1;
        $input->me = 10;

        return $this->processor->process($input, new Post());
    }
}
