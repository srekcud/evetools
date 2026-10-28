<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\StockpileImportPreviewResource;
use App\ApiResource\Input\Industry\StockpileImportInput;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Exception\StockpileProductNotPlannableException;
use App\Industry\Application\ProductionPlanning;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryStockpileImportRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\StockpileService;
use App\Service\Industry\StockpileStartingStockReducer;
use App\Service\JitaMarketService;
use App\State\Processor\Industry\StockpileImportPreviewProcessor;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

#[CoversClass(StockpileImportPreviewProcessor::class)]
final class StockpileImportPreviewProcessorTest extends TestCase
{
    private const int WIDGET = 3001;
    private const int WIDGET_BLUEPRINT = 1_003_001;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int TRITANIUM_PER_RUN = 500;
    private const int PYERITE_PER_RUN = 200;
    private const int WIDGET_MAX_PRODUCTION_LIMIT = 300;
    private const int WIDGET_BASE_TIME_SECONDS = 3_600;
    private const int TYPE_WITHOUT_BLUEPRINT = 99999;
    private const string PILOT_ID = '0190f3c2-6d1a-7c55-8e20-2b9d4f7a1e63';
    private const array TYPE_NAMES = [self::WIDGET => 'Widget', self::TRITANIUM => 'Tritanium', self::PYERITE => 'Pyerite'];

    private array $jitaPrices = [];

    private StockpileImportPreviewProcessor $processor;

    protected function setUp(): void
    {
        $pilot = $this->createStub(User::class);
        $pilot->method('getId')->willReturn(Uuid::fromString(self::PILOT_ID));
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($pilot);

        $productionPlanning = new ProductionPlanning(
            new InMemoryBlueprintCatalog(new Recipe(
                self::WIDGET,
                ActivityKind::Manufacturing,
                new OutputPerRun(1),
                [new RecipeMaterial(self::TRITANIUM, new Quantity(self::TRITANIUM_PER_RUN)), new RecipeMaterial(self::PYERITE, new Quantity(self::PYERITE_PER_RUN))],
                new Runs(self::WIDGET_MAX_PRODUCTION_LIMIT),
                self::WIDGET_BASE_TIME_SECONDS,
            )),
            InMemorySystemCostIndices::none(),
            new InMemoryRigCategories(),
        );
        $userStructures = new InMemoryUserProductionStructures([self::PILOT_ID => []]);
        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('findBlueprintForProduct')->willReturnCallback(
            static fn (int $productTypeId, int $activityId = 1): ?IndustryActivityProduct => $productTypeId === self::WIDGET && $activityId === IndustryActivityType::Manufacturing->value
                ? (new IndustryActivityProduct())->setTypeId(self::WIDGET_BLUEPRINT)->setActivityId($activityId)->setProductTypeId(self::WIDGET)->setQuantity(1)
                : null,
        );

        $typeRepository = $this->createStub(InvTypeRepository::class);
        $typeRepository->method('findBy')->willReturnCallback(
            static fn (array $criteria): array => array_values(array_map(
                static fn (int $typeId): InvType => (new InvType())->setTypeId($typeId)->setTypeName(self::TYPE_NAMES[$typeId]),
                array_filter((array) $criteria['typeId'], static fn (int $typeId): bool => isset(self::TYPE_NAMES[$typeId])),
            )),
        );
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $className): ?InvTypeRepository => $className === InvType::class ? $typeRepository : null,
        );

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
            $productionPlanning,
            $userStructures,
            $productRepository,
            $entityManager,
            $this->createStub(IndustryStockpileImportRepository::class),
            new StockpileStartingStockReducer($productionPlanning, $userStructures, $productRepository, new NullLogger()),
        ));
    }

    #[Group('issue-8')]
    public function testExposesTheEstimatedCostOfAFullyPricedPreviewWithoutMissingPrice(): void
    {
        $this->jitaPrices = [self::WIDGET => 1_000_000.0, self::TRITANIUM => 4.0, self::PYERITE => 9.5];

        $preview = $this->preview();

        self::assertSame(3, $preview->totalItems);
        self::assertSame(1_003_510.0, $preview->estimatedCost);
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

    #[Group('issue-258')]
    public function testPreviewOfATypeWithoutBlueprintNorReactionIsRejectedAsNotPlannableWithAStableMessage(): void
    {
        $this->expectException(StockpileProductNotPlannableException::class);
        $this->expectExceptionMessage('Type 99999 has no blueprint nor reaction');

        $this->preview(self::TYPE_WITHOUT_BLUEPRINT);
    }

    private function preview(int $typeId = self::WIDGET): StockpileImportPreviewResource
    {
        $input = new StockpileImportInput();
        $input->typeId = $typeId;
        $input->runs = 1;
        $input->me = 10;

        return $this->processor->process($input, new Post());
    }
}
