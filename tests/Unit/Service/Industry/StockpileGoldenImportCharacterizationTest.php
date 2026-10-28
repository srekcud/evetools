<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryStockpileImport;
use App\Entity\IndustryStockpileTarget;
use App\Entity\Sde\IndustryActivity;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\IndustryBlueprint;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Exception\StockpileProductNotPlannableException;
use App\Exception\TypeMissingFromSdeException;
use App\Industry\Application\ProductionPlanning;
use App\Industry\Infrastructure\Sde\SdeBlueprintCatalog;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryStockpileImportRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\IndustryBlueprintRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\StockpileService;
use App\Service\Industry\StockpileStartingStockReducer;
use App\Service\JitaMarketService;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

#[CoversClass(StockpileService::class)]
#[CoversClass(StockpileStartingStockReducer::class)]
#[AllowMockObjectsWithoutExpectations]
final class StockpileGoldenImportCharacterizationTest extends TestCase
{
    private const string GOLDEN_DIR = __DIR__.'/../../../Fixtures/Golden/';
    private const int BLUEPRINT_TYPE_ID_OFFSET = 1_000_000;
    private const string PILOT_ID = '0190f3c2-7e44-7b19-a6d3-58c0e2f91b4d';

    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int NITROGEN_ISOTOPES = 17888;
    private const int ROBOTICS = 9848;
    private const int COOLANT = 9832;
    private const int OXYGEN = 3683;
    private const int ENRICHED_URANIUM = 44;
    private const int MECHANICAL_PARTS = 3689;
    private const int HEAVY_WATER = 16272;
    private const int LIQUID_OZONE = 16273;
    private const int STRONTIUM_CLATHRATES = 16275;
    private const float TWO_ISK = 2.0;
    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_ALLOY = 16656;
    private const int CERAMIC_POWDER = 16660;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int HYDROGEN_ISOTOPES = 17889;
    private const int SCANDIUM = 16639;
    private const int NOMAD = 28846;

    private array $sde;

    private array $jitaPrices = [];

    private array $esiFallbackPrices = [];

    private array $jitaPriceRequests = [];

    private array $typeIdsWithoutSdeName = [];

    private array $targets = [];

    private array $stockpileImports = [];

    private array $stockByTypeId = [];

    private IndustryStockpileTargetRepository $targetRepository;
    private IndustryStockpileImportRepository $importRepository;
    private EntityManagerInterface $entityManager;
    private StockpileService $service;
    private User $pilot;

    protected function setUp(): void
    {
        $this->sde = json_decode((string) file_get_contents(self::GOLDEN_DIR.'sde-blueprint-recipes.json'), true, flags: \JSON_THROW_ON_ERROR);

        $this->targetRepository = $this->createMock(IndustryStockpileTargetRepository::class);
        $this->targetRepository->method('findByUser')->willReturnCallback(fn (): array => $this->targets);
        $this->importRepository = $this->createMock(IndustryStockpileImportRepository::class);
        $this->importRepository->method('findByUserInCreationOrder')->willReturnCallback(fn (): array => $this->stockpileImports);
        $assetRepository = $this->createMock(CachedAssetRepository::class);
        $assetRepository->method('getQuantitiesByUserWithLocations')->willReturnCallback(
            fn (): array => array_map(static fn (int $stock): array => ['total' => $stock, 'locations' => []], $this->stockByTypeId),
        );
        $typeRepository = $this->typeRepositoryOnGoldenSde();
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getRepository')->willReturnCallback(
            static fn (string $className): ?InvTypeRepository => $className === InvType::class ? $typeRepository : null,
        );

        $jitaMarketService = $this->createMock(JitaMarketService::class);
        $jitaMarketService->method('getPrices')->willReturnCallback(function (array $typeIds): array {
            $this->jitaPriceRequests[] = ['cache', $typeIds];

            return $this->pricesOf($typeIds, $this->jitaPrices);
        });
        $jitaMarketService->method('getPricesWithFallback')->willReturnCallback(function (array $typeIds): array {
            $this->jitaPriceRequests[] = ['fallback', $typeIds];

            return $this->pricesOf($typeIds, $this->jitaPrices + $this->esiFallbackPrices);
        });

        $productRepository = $this->productRepositoryOnGoldenSde();
        $productionPlanning = new ProductionPlanning(
            new SdeBlueprintCatalog($productRepository, $this->activityRepositoryOnGoldenSde(), $this->materialRepositoryOnGoldenSde(), $this->blueprintRepositoryOnGoldenSde()),
            InMemorySystemCostIndices::none(),
            new InMemoryRigCategories(),
        );
        $userStructures = new InMemoryUserProductionStructures([self::PILOT_ID => []]);
        $this->service = new StockpileService(
            $this->targetRepository,
            $assetRepository,
            $jitaMarketService,
            $productionPlanning,
            $userStructures,
            $productRepository,
            $this->entityManager,
            $this->importRepository,
            new StockpileStartingStockReducer($productionPlanning, $userStructures, $productRepository, new NullLogger()),
        );
        $this->pilot = $this->createMock(User::class);
        $this->pilot->method('getId')->willReturn(Uuid::fromString(self::PILOT_ID));
    }

    #[Group('issue-2')]
    public function testPreviewOfTwentyFiveRunsOfNitrogenFuelBlockReadsTwentyFiveRunsOfFortyUnits(): void
    {
        $preview = $this->service->previewImport($this->pilot, self::NITROGEN_FUEL_BLOCK, 25, 10);

        self::assertSame(
            [['typeId' => self::NITROGEN_FUEL_BLOCK, 'typeName' => 'Nitrogen Fuel Block', 'quantity' => 1000, 'unitPrice' => null]],
            $preview['stages']['final_product'],
        );
        self::assertSame(
            [44 => 90, 3683 => 495, 3689 => 90, 9832 => 203, 9848 => 25, 16272 => 3825, 16273 => 7875, 16275 => 450, 17888 => 10125],
            $this->sortedQuantitiesByTypeId($preview['stages']['raw_material']),
        );
        self::assertSame([], $preview['stages']['intermediate']);
        self::assertSame([], $preview['stages']['component']);
        self::assertSame(['raw_material', 'intermediate', 'component', 'final_product'], array_keys($preview['stages']));
        self::assertSame(10, $preview['totalItems']);
    }

    public function testPreviewOfOneRunOfNitrogenFuelBlockAtMeZeroKeepsTheBaseQuantities(): void
    {
        $preview = $this->service->previewImport($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 0);

        self::assertSame(
            [44 => 4, 3683 => 22, 3689 => 4, 9832 => 9, 9848 => 1, 16272 => 170, 16273 => 350, 16275 => 20, 17888 => 450],
            $this->sortedQuantitiesByTypeId($preview['stages']['raw_material']),
        );
        self::assertSame(40, $preview['stages']['final_product'][0]['quantity']);
    }

    #[Group('issue-2')]
    public function testPreviewOfFortyOneRunsOfNitrogenFuelBlockAtMeTenReadsThemAsRuns(): void
    {
        $preview = $this->service->previewImport($this->pilot, self::NITROGEN_FUEL_BLOCK, 41, 10);

        self::assertSame([1640], array_column($preview['stages']['final_product'], 'quantity'));
        self::assertSame(
            [44 => 148, 3683 => 812, 3689 => 148, 9832 => 333, 9848 => 41, 16272 => 6273, 16273 => 12915, 16275 => 738, 17888 => 16605],
            $this->sortedQuantitiesByTypeId($preview['stages']['raw_material']),
        );
    }

    #[Group('issue-69')]
    public function testPreviewOfTwoHundredFiftyRunsOfNitrogenFuelBlockReadsTheLeavesOfTwoJobsSplitUnderTheMaxProductionLimit(): void
    {
        $preview = $this->service->previewImport($this->pilot, self::NITROGEN_FUEL_BLOCK, 250, 10);

        self::assertSame([10000], array_column($preview['stages']['final_product'], 'quantity'));
        self::assertSame(
            [44 => 900, 3683 => 4950, 3689 => 900, 9832 => 2026, 9848 => 250, 16272 => 38250, 16273 => 78750, 16275 => 4500, 17888 => 101250],
            $this->sortedQuantitiesByTypeId($preview['stages']['raw_material']),
        );
    }

    #[Group('issue-8')]
    public function testPreviewEstimatedCostIsUnknownWhenSomeItemsHaveNoJitaPrice(): void
    {
        $this->jitaPrices = [
            self::NITROGEN_FUEL_BLOCK => 600.5,
            self::NITROGEN_ISOTOPES => 0.25,
            self::COOLANT => 150.0,
        ];

        $preview = $this->service->previewImport($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);

        self::assertSame(600.5, $preview['stages']['final_product'][0]['unitPrice']);
        self::assertSame(0.25, $this->entryOf($preview['stages']['raw_material'], self::NITROGEN_ISOTOPES)['unitPrice']);
        self::assertSame(150.0, $this->entryOf($preview['stages']['raw_material'], self::COOLANT)['unitPrice']);
        self::assertSame(['typeId' => self::ROBOTICS, 'typeName' => 'Robotics', 'quantity' => 1, 'unitPrice' => null], $this->entryOf($preview['stages']['raw_material'], self::ROBOTICS));
        self::assertSame(['typeId' => self::OXYGEN, 'typeName' => 'Oxygen', 'quantity' => 20, 'unitPrice' => null], $this->entryOf($preview['stages']['raw_material'], self::OXYGEN));
        self::assertNull($preview['estimatedCost']);
        self::assertSame(
            [self::ENRICHED_URANIUM, self::OXYGEN, self::MECHANICAL_PARTS, self::ROBOTICS, self::HEAVY_WATER, self::LIQUID_OZONE, self::STRONTIUM_CLATHRATES],
            $preview['missingPriceTypeIds'],
        );
    }

    #[Group('issue-8')]
    public function testPreviewEstimatedCostSumsEveryItemWhenEveryItemHasAJitaPrice(): void
    {
        $this->jitaPrices = $this->everyNitrogenFuelBlockItemPriced();

        $preview = $this->service->previewImport($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);

        self::assertSame(26501.25, $preview['estimatedCost']);
        self::assertSame([], $preview['missingPriceTypeIds']);
    }

    #[Group('issue-8')]
    public function testPreviewRoundsTheEstimatedCostToTwoDecimalsCountingAZeroPriceAsKnown(): void
    {
        $this->jitaPrices = array_replace(
            array_fill_keys([self::NITROGEN_FUEL_BLOCK, self::ENRICHED_URANIUM, self::OXYGEN, self::MECHANICAL_PARTS, self::COOLANT, self::ROBOTICS, self::HEAVY_WATER, self::LIQUID_OZONE, self::STRONTIUM_CLATHRATES], 0.0),
            [self::NITROGEN_ISOTOPES => 0.333],
        );

        $preview = $this->service->previewImport($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);

        self::assertSame(134.87, $preview['estimatedCost']);
        self::assertSame([], $preview['missingPriceTypeIds']);
    }

    #[Group('issue-8')]
    public function testPreviewEstimatedCostIsUnknownWhenASingleMaterialHasNoJitaPrice(): void
    {
        $this->jitaPrices = $this->everyNitrogenFuelBlockItemPriced();
        unset($this->jitaPrices[self::ROBOTICS]);

        $preview = $this->service->previewImport($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);

        self::assertNull($this->entryOf($preview['stages']['raw_material'], self::ROBOTICS)['unitPrice']);
        self::assertSame(self::TWO_ISK, $this->entryOf($preview['stages']['raw_material'], self::HEAVY_WATER)['unitPrice']);
        self::assertNull($preview['estimatedCost']);
        self::assertSame([self::ROBOTICS], $preview['missingPriceTypeIds']);
    }

    #[Group('issue-8')]
    public function testPreviewPricesAnItemMissingFromTheJitaCacheWithTheEsiFallbackInASingleRequest(): void
    {
        $this->jitaPrices = $this->everyNitrogenFuelBlockItemPriced();
        unset($this->jitaPrices[self::ROBOTICS]);
        $this->esiFallbackPrices = [self::ROBOTICS => 5000.0];

        $preview = $this->service->previewImport($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);

        self::assertSame(5000.0, $this->entryOf($preview['stages']['raw_material'], self::ROBOTICS)['unitPrice']);
        self::assertSame(31499.25, $preview['estimatedCost']);
        self::assertSame([], $preview['missingPriceTypeIds']);
        self::assertCount(1, $this->jitaPriceRequests);
        self::assertSame('fallback', $this->jitaPriceRequests[0][0]);
        self::assertEqualsCanonicalizing(
            [self::NITROGEN_FUEL_BLOCK, self::ENRICHED_URANIUM, self::OXYGEN, self::MECHANICAL_PARTS, self::COOLANT, self::ROBOTICS, self::HEAVY_WATER, self::LIQUID_OZONE, self::STRONTIUM_CLATHRATES, self::NITROGEN_ISOTOPES],
            $this->jitaPriceRequests[0][1],
        );
    }

    #[Group('issue-8')]
    public function testPreviewWithoutAnyJitaPriceListsEveryItemOnceInAscendingTypeIdOrder(): void
    {
        $preview = $this->service->previewImport($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);

        self::assertNull($preview['estimatedCost']);
        self::assertSame(
            [self::ENRICHED_URANIUM, self::OXYGEN, self::MECHANICAL_PARTS, self::NITROGEN_FUEL_BLOCK, self::COOLANT, self::ROBOTICS, self::HEAVY_WATER, self::LIQUID_OZONE, self::STRONTIUM_CLATHRATES, self::NITROGEN_ISOTOPES],
            $preview['missingPriceTypeIds'],
        );
    }

    public function testPreviewOfATreeWithTypesMissingFromTheSdeTypesThrows(): void
    {
        $this->typeIdsWithoutSdeName = [self::ROBOTICS, self::NITROGEN_FUEL_BLOCK];

        try {
            $this->service->previewImport($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);
            self::fail('Expected a TypeMissingFromSdeException');
        } catch (TypeMissingFromSdeException $exception) {
            self::assertSame([self::NITROGEN_FUEL_BLOCK, self::ROBOTICS], $exception->getMissingTypeIds());
        }
    }

    #[Group('issue-258')]
    public function testPreviewOfAProductWithoutBlueprintNorReactionIsNotPlannable(): void
    {
        $this->expectException(StockpileProductNotPlannableException::class);
        $this->expectExceptionMessage('Type '.self::ROBOTICS.' has no blueprint nor reaction');

        $this->service->previewImport($this->pilot, self::ROBOTICS, 1, 10);
    }

    #[Group('issue-69')]
    #[Group('issue-258')]
    public function testImportOfAProductWithoutBlueprintNorReactionIsNotPlannableAndWritesNothing(): void
    {
        $this->targetRepository->expects(self::never())->method('deleteAllForUser');
        $this->importRepository->expects(self::never())->method('deleteAllForUser');
        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');
        $this->expectException(StockpileProductNotPlannableException::class);
        $this->expectExceptionMessage('Type '.self::ROBOTICS.' has no blueprint nor reaction');

        $this->service->importFromBlueprint($this->pilot, self::ROBOTICS, 1, 10, 'replace');
    }

    #[Group('issue-2')]
    public function testPreviewOfTenRunsOfFerniteCarbideReadsThemAsRunsAndBuildsThreeRunsOfHydrogenFuelBlock(): void
    {
        $preview = $this->service->previewImport($this->pilot, self::FERNITE_CARBIDE, 10, 10);

        self::assertSame([100000], array_column($preview['stages']['final_product'], 'quantity'));
        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 100, self::FERNITE_ALLOY => 1000, self::CERAMIC_POWDER => 1000],
            $this->sortedQuantitiesByTypeId($preview['stages']['component']),
        );
        self::assertSame([], $preview['stages']['intermediate']);
        self::assertSame(1215, $this->entryOf($preview['stages']['raw_material'], self::HYDROGEN_ISOTOPES)['quantity']);
    }

    public function testPreviewOfFerniteCarbideIgnoresTheMe(): void
    {
        $previewAtMeZero = $this->service->previewImport($this->pilot, self::FERNITE_CARBIDE, 1, 0);
        $previewAtMeTen = $this->service->previewImport($this->pilot, self::FERNITE_CARBIDE, 1, 10);

        self::assertSame($previewAtMeZero['stages'], $previewAtMeTen['stages']);
        self::assertSame(17, $previewAtMeTen['totalItems']);
    }

    #[Group('issue-69')]
    public function testPreviewOfOneRunOfFerniteCarbideBuildsTheHydrogenFuelBlockOnceForItsThreeConsumers(): void
    {
        $preview = $this->service->previewImport($this->pilot, self::FERNITE_CARBIDE, 1, 10);

        self::assertSame(
            [44 => 4, 3683 => 20, 3689 => 4, 9832 => 9, 9848 => 1, 16272 => 153, 16273 => 315, 16275 => 18, 16635 => 100, 16636 => 100, 16639 => 100, 16642 => 100, 17889 => 405],
            $this->sortedQuantitiesByTypeId($preview['stages']['raw_material']),
        );
        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 15, self::FERNITE_ALLOY => 100, self::CERAMIC_POWDER => 100],
            $this->sortedQuantitiesByTypeId($preview['stages']['component']),
        );
        self::assertSame([], $preview['stages']['intermediate']);
        self::assertSame(
            [['typeId' => self::FERNITE_CARBIDE, 'typeName' => 'Fernite Carbide', 'quantity' => 10000, 'unitPrice' => null]],
            $preview['stages']['final_product'],
        );
        self::assertSame(17, $preview['totalItems']);
    }

    #[Group('issue-69')]
    public function testPreviewOfOneNomadReadsTheLeavesAndDemandsOfRavworksButTheFiveTypesOfJobsSplitUnderTheirMaxProductionLimit(): void
    {
        $golden = json_decode((string) file_get_contents(self::GOLDEN_DIR.'nomad-1run-me10-no-structure.ravworks.json'), true, flags: \JSON_THROW_ON_ERROR);
        $goldenLeaves = array_column($golden['expected']['leaves'], 'quantity', 'typeId');
        $goldenDemandOfIntermediates = array_column($golden['expected']['intermediates'], 'quantityNeeded', 'typeId');
        $nomadJobMaterials = [11478 => 54, 20189 => 1, 21025 => 27, 29043 => 607, 29045 => 951, 29049 => 1368, 29065 => 599, 29075 => 951, 29079 => 700, 29089 => 570];

        $preview = $this->service->previewImport($this->pilot, self::NOMAD, 1, 10);

        $expectedLeaves = array_replace($goldenLeaves, [self::COOLANT => 4693]);
        ksort($expectedLeaves);
        $expectedDemandOfIntermediates = array_replace($goldenDemandOfIntermediates, [
            self::FERNITE_CARBIDE => 9719983, 16679 => 950906, 16681 => 34177, 16678 => 2538073,
        ]);
        ksort($expectedDemandOfIntermediates);
        $expectedIntermediateStageTypeIds = array_keys(array_diff_key($expectedDemandOfIntermediates, $nomadJobMaterials));
        self::assertSame($expectedLeaves, $this->sortedQuantitiesByTypeId($preview['stages']['raw_material']));
        self::assertSame(
            $expectedDemandOfIntermediates,
            $this->sortedQuantitiesByTypeId([...$preview['stages']['component'], ...$preview['stages']['intermediate']]),
        );
        self::assertSame($nomadJobMaterials, $this->sortedQuantitiesByTypeId($preview['stages']['component']));
        self::assertSame($expectedIntermediateStageTypeIds, array_keys($this->sortedQuantitiesByTypeId($preview['stages']['intermediate'])));
        self::assertSame([['typeId' => self::NOMAD, 'typeName' => 'Nomad', 'quantity' => 1, 'unitPrice' => null]], $preview['stages']['final_product']);
        self::assertSame(129, $preview['totalItems']);
    }

    public function testImportOfNitrogenFuelBlockInMergeModeAddsTheTreeQuantitiesToTheExistingTargetAndPersistsTheOthers(): void
    {
        $existingCoolant = new IndustryStockpileTarget();
        $existingCoolant->setTypeId(self::COOLANT)->setTypeName('Coolant')->setTargetQuantity(1000)->setStage('raw_material');
        $this->targetRepository->method('findByUserAndTypeId')->willReturnCallback(
            static fn (User $user, int $typeId): ?IndustryStockpileTarget => $typeId === self::COOLANT ? $existingCoolant : null,
        );
        $this->targetRepository->expects(self::never())->method('deleteAllForUser');
        $this->importRepository->expects(self::never())->method('deleteAllForUser');
        $persisted = [];
        $this->entityManager->method('persist')->willReturnCallback(static function (object $target) use (&$persisted): void {
            $persisted[] = $target;
        });

        $this->service->importFromBlueprint($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10, 'merge');

        $persistedTargets = self::persistedOfClass($persisted, IndustryStockpileTarget::class);
        self::assertSame(1009, $existingCoolant->getTargetQuantity());
        self::assertNotNull($existingCoolant->getUpdatedAt());
        self::assertSame(
            [
                [4051, 'Nitrogen Fuel Block', 40, 'final_product', 4051],
                [44, 'Enriched Uranium', 4, 'raw_material', 4051],
                [3683, 'Oxygen', 20, 'raw_material', 4051],
                [3689, 'Mechanical Parts', 4, 'raw_material', 4051],
                [9848, 'Robotics', 1, 'raw_material', 4051],
                [16272, 'Heavy Water', 153, 'raw_material', 4051],
                [16273, 'Liquid Ozone', 315, 'raw_material', 4051],
                [16275, 'Strontium Clathrates', 18, 'raw_material', 4051],
                [17888, 'Nitrogen Isotopes', 405, 'raw_material', 4051],
            ],
            array_map(static fn (IndustryStockpileTarget $target): array => [
                $target->getTypeId(), $target->getTypeName(), $target->getTargetQuantity(), $target->getStage(), $target->getSourceProductTypeId(),
            ], $persistedTargets),
        );
        self::assertSame($this->pilot, $persistedTargets[0]->getUser());
        self::assertSame(
            [['pilot', self::NITROGEN_FUEL_BLOCK, 1, 10]],
            array_map(fn (IndustryStockpileImport $stockpileImport): array => [
                $stockpileImport->getUser() === $this->pilot ? 'pilot' : 'another user',
                $stockpileImport->getProductTypeId(),
                $stockpileImport->getRuns(),
                $stockpileImport->getMe(),
            ], self::persistedOfClass($persisted, IndustryStockpileImport::class)),
        );
    }

    #[Group('issue-69')]
    public function testImportOfOneRunOfFerniteCarbideInMergeModeAddsTheAggregatedHydrogenIsotopesToTheExistingTarget(): void
    {
        $existingHydrogenIsotopes = new IndustryStockpileTarget();
        $existingHydrogenIsotopes->setTypeId(self::HYDROGEN_ISOTOPES)->setTypeName('Hydrogen Isotopes')->setTargetQuantity(1000)->setStage('raw_material');
        $this->targetRepository->method('findByUserAndTypeId')->willReturnCallback(
            static fn (User $user, int $typeId): ?IndustryStockpileTarget => $typeId === self::HYDROGEN_ISOTOPES ? $existingHydrogenIsotopes : null,
        );
        $persisted = [];
        $this->entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->service->importFromBlueprint($this->pilot, self::FERNITE_CARBIDE, 1, 10, 'merge');

        $persistedTargets = self::targetsByTypeId(self::persistedOfClass($persisted, IndustryStockpileTarget::class));
        self::assertSame(1405, $existingHydrogenIsotopes->getTargetQuantity());
        self::assertCount(16, $persistedTargets);
        self::assertSame(['Hydrogen Fuel Block', 15, 'component', self::FERNITE_CARBIDE], $persistedTargets[self::HYDROGEN_FUEL_BLOCK]);
        self::assertSame(['Scandium', 100, 'raw_material', self::FERNITE_CARBIDE], $persistedTargets[self::SCANDIUM] ?? null);
    }

    public function testImportInReplaceModeDeletesTheTargetsAndTheStockpileImportsOfThePilotBeforePersistingTheTreeAndItsImport(): void
    {
        $calls = [];
        $this->targetRepository->method('deleteAllForUser')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'delete targets';
        });
        $this->importRepository->method('deleteAllForUser')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'delete imports';
        });
        $this->targetRepository->expects(self::never())->method('findByUserAndTypeId');
        $this->entityManager->method('flush')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'flush';
        });
        $this->entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$calls): void {
            $calls[] = $entity instanceof IndustryStockpileImport ? 'persist import' : 'persist target';
        });

        $this->service->importFromBlueprint($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10, 'replace');

        self::assertEqualsCanonicalizing(['delete targets', 'delete imports'], array_slice($calls, 0, 2));
        self::assertSame(['flush'], array_slice($calls, 2, 1));
        self::assertEqualsCanonicalizing(['persist import', ...array_fill(0, 10, 'persist target')], array_slice($calls, 3, 11));
        self::assertSame(['flush'], array_slice($calls, 14));
    }

    #[Group('issue-69')]
    #[DataProvider('importedProductProvider')]
    public function testImportInReplaceModePersistsEveryItemOfThePreviewAsATargetOfTheImportedProduct(int $productTypeId, int $runs, int $me, int $expectedTargetCount): void
    {
        $preview = $this->service->previewImport($this->pilot, $productTypeId, $runs, $me);
        $persisted = [];
        $this->entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->service->importFromBlueprint($this->pilot, $productTypeId, $runs, $me, 'replace');

        $previewTargets = [];
        foreach ($preview['stages'] as $stage => $entries) {
            foreach ($entries as $entry) {
                $previewTargets[$entry['typeId']] = [$entry['typeName'], $entry['quantity'], $stage, $productTypeId];
            }
        }
        ksort($previewTargets);
        $persistedTargets = self::targetsByTypeId(self::persistedOfClass($persisted, IndustryStockpileTarget::class));
        ksort($persistedTargets);
        self::assertCount($expectedTargetCount, $persistedTargets);
        self::assertSame($previewTargets, $persistedTargets);
    }

    public static function importedProductProvider(): iterable
    {
        yield 'one run of Fernite Carbide' => [self::FERNITE_CARBIDE, 1, 10, 17];
        yield 'one Nomad' => [self::NOMAD, 1, 10, 129];
        yield 'twenty-five runs of Nitrogen Fuel Block' => [self::NITROGEN_FUEL_BLOCK, 25, 10, 10];
        yield 'forty-one runs of Nitrogen Fuel Block' => [self::NITROGEN_FUEL_BLOCK, 41, 10, 10];
        yield 'ten runs of Fernite Carbide' => [self::FERNITE_CARBIDE, 10, 10, 17];
    }

    #[Group('issue-69')]
    public function testStatusOfOneRunOfFerniteCarbideWithFiveHydrogenFuelBlocksInStockStillBuildsOneRunOfHydrogenFuelBlock(): void
    {
        $this->importOneRunOfFerniteCarbide();
        $this->stockByTypeId = [self::HYDROGEN_FUEL_BLOCK => 5];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(405, $this->statusItemOf($status, self::HYDROGEN_ISOTOPES)['remainingTargetQuantity']);
        self::assertSame(4, $this->statusItemOf($status, self::ENRICHED_URANIUM)['remainingTargetQuantity']);
        self::assertSame(
            ['remainingTargetQuantity' => 15, 'stock' => 5, 'percent' => 33.3, 'status' => 'critical'],
            array_intersect_key($this->statusItemOf($status, self::HYDROGEN_FUEL_BLOCK), array_flip(['remainingTargetQuantity', 'stock', 'percent', 'status'])),
        );
        self::assertSame([], $status['failedProducts']);
    }

    #[Group('issue-69')]
    public function testStatusOfOneRunOfFerniteCarbideWithFifteenHydrogenFuelBlocksInStockLeavesNoMaterialOfTheFuelBlockToBuy(): void
    {
        $this->importOneRunOfFerniteCarbide();
        $this->stockByTypeId = [self::HYDROGEN_FUEL_BLOCK => 15];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(0, $this->statusItemOf($status, self::HYDROGEN_ISOTOPES)['remainingTargetQuantity']);
        self::assertSame(0, $this->statusItemOf($status, self::ENRICHED_URANIUM)['remainingTargetQuantity']);
        self::assertSame(100, $this->statusItemOf($status, self::SCANDIUM)['remainingTargetQuantity']);
        self::assertSame(100, $this->statusItemOf($status, self::FERNITE_ALLOY)['remainingTargetQuantity']);
        self::assertSame(
            ['remainingTargetQuantity' => 15, 'stock' => 15, 'status' => 'met'],
            array_intersect_key($this->statusItemOf($status, self::HYDROGEN_FUEL_BLOCK), array_flip(['remainingTargetQuantity', 'stock', 'status'])),
        );
    }

    #[Group('issue-2')]
    public function testStatusOfTwentyFiveRunsOfNitrogenFuelBlockWithOneHundredThirtyInStockPlansTheTwentyTwoRunsOfTheUnitsLeft(): void
    {
        $this->stockpileImports = [new IndustryStockpileImport($this->pilot, self::NITROGEN_FUEL_BLOCK, 25, 10)];
        $this->addTarget(self::NITROGEN_FUEL_BLOCK, 1000, 'final_product', self::NITROGEN_FUEL_BLOCK);
        $this->addTarget(self::NITROGEN_ISOTOPES, 10125, 'raw_material', self::NITROGEN_FUEL_BLOCK);
        $this->addTarget(self::COOLANT, 203, 'raw_material', self::NITROGEN_FUEL_BLOCK);
        $this->stockByTypeId = [self::NITROGEN_FUEL_BLOCK => 130];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame(8910, $this->statusItemOf($status, self::NITROGEN_ISOTOPES)['remainingTargetQuantity']);
        self::assertSame(179, $this->statusItemOf($status, self::COOLANT)['remainingTargetQuantity']);
        self::assertSame(
            ['remainingTargetQuantity' => 1000, 'stock' => 130, 'percent' => 13.0, 'status' => 'critical'],
            array_intersect_key($this->statusItemOf($status, self::NITROGEN_FUEL_BLOCK), array_flip(['remainingTargetQuantity', 'stock', 'percent', 'status'])),
        );
    }

    private function importOneRunOfFerniteCarbide(): void
    {
        $this->stockpileImports = [new IndustryStockpileImport($this->pilot, self::FERNITE_CARBIDE, 1, 10)];
        $this->addTarget(self::FERNITE_CARBIDE, 10000, 'final_product', self::FERNITE_CARBIDE);
        $this->addTarget(self::HYDROGEN_FUEL_BLOCK, 15, 'component', self::FERNITE_CARBIDE);
        $this->addTarget(self::FERNITE_ALLOY, 100, 'component', self::FERNITE_CARBIDE);
        $this->addTarget(self::HYDROGEN_ISOTOPES, 405, 'raw_material', self::FERNITE_CARBIDE);
        $this->addTarget(self::ENRICHED_URANIUM, 4, 'raw_material', self::FERNITE_CARBIDE);
        $this->addTarget(self::SCANDIUM, 100, 'raw_material', self::FERNITE_CARBIDE);
    }

    private function addTarget(int $typeId, int $targetQuantity, string $stage, int $sourceProductTypeId): void
    {
        $this->targets[] = (new IndustryStockpileTarget())
            ->setUser($this->pilot)
            ->setTypeId($typeId)
            ->setTypeName($this->sde['typeNames'][(string) $typeId])
            ->setTargetQuantity($targetQuantity)
            ->setStage($stage)
            ->setSourceProductTypeId($sourceProductTypeId);
    }

    private function statusItemOf(array $status, int $typeId): array
    {
        foreach ($status['stages'] as $stage) {
            foreach ($stage['items'] as $item) {
                if ($item['typeId'] === $typeId) {
                    return $item;
                }
            }
        }
        self::fail("No status item for type {$typeId}");
    }

    private function sortedQuantitiesByTypeId(array $entries): array
    {
        $quantities = array_column($entries, 'quantity', 'typeId');
        ksort($quantities);

        return $quantities;
    }

    private function entryOf(array $entries, int $typeId): array
    {
        foreach ($entries as $entry) {
            if ($entry['typeId'] === $typeId) {
                return $entry;
            }
        }
        self::fail("No entry for type {$typeId}");
    }

    private function everyNitrogenFuelBlockItemPriced(): array
    {
        return [
            self::NITROGEN_FUEL_BLOCK => 600.5,
            self::NITROGEN_ISOTOPES => 0.25,
            self::COOLANT => 150.0,
            self::ENRICHED_URANIUM => self::TWO_ISK,
            self::OXYGEN => self::TWO_ISK,
            self::MECHANICAL_PARTS => self::TWO_ISK,
            self::ROBOTICS => self::TWO_ISK,
            self::HEAVY_WATER => self::TWO_ISK,
            self::LIQUID_OZONE => self::TWO_ISK,
            self::STRONTIUM_CLATHRATES => self::TWO_ISK,
        ];
    }

    private function pricesOf(array $typeIds, array $knownPrices): array
    {
        $prices = [];
        foreach ($typeIds as $typeId) {
            $prices[$typeId] = $knownPrices[$typeId] ?? null;
        }

        return $prices;
    }

    private static function persistedOfClass(array $persisted, string $className): array
    {
        return array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof $className));
    }

    private static function targetsByTypeId(array $targets): array
    {
        $targetsByTypeId = [];
        foreach ($targets as $target) {
            $targetsByTypeId[$target->getTypeId()] = [$target->getTypeName(), $target->getTargetQuantity(), $target->getStage(), $target->getSourceProductTypeId()];
        }

        return $targetsByTypeId;
    }

    private function productRepositoryOnGoldenSde(): IndustryActivityProductRepository
    {
        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('findBlueprintForProduct')->willReturnCallback(
            function (int $productTypeId, int $activityId = 1): ?IndustryActivityProduct {
                $recipe = $this->sde['recipes'][(string) $productTypeId] ?? null;
                if ($recipe === null || self::activityIdOf($recipe['activity']) !== $activityId) {
                    return null;
                }

                return (new IndustryActivityProduct())
                    ->setTypeId($productTypeId + self::BLUEPRINT_TYPE_ID_OFFSET)
                    ->setActivityId($activityId)
                    ->setProductTypeId($productTypeId)
                    ->setQuantity($recipe['outputPerRun']);
            },
        );

        return $productRepository;
    }

    private function materialRepositoryOnGoldenSde(): IndustryActivityMaterialRepository
    {
        $materialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $materialRepository->method('findByBlueprintAndActivity')->willReturnCallback(
            function (int $blueprintTypeId, int $activityId = 1): array {
                $recipe = $this->sde['recipes'][(string) ($blueprintTypeId - self::BLUEPRINT_TYPE_ID_OFFSET)] ?? null;
                if ($recipe === null || self::activityIdOf($recipe['activity']) !== $activityId) {
                    return [];
                }
                $materials = [];
                foreach ($recipe['materials'] as $materialTypeId => $baseQuantityPerRun) {
                    $materials[] = (new IndustryActivityMaterial())
                        ->setTypeId($blueprintTypeId)
                        ->setActivityId($activityId)
                        ->setMaterialTypeId((int) $materialTypeId)
                        ->setQuantity($baseQuantityPerRun);
                }

                return $materials;
            },
        );

        return $materialRepository;
    }

    private function blueprintRepositoryOnGoldenSde(): IndustryBlueprintRepository
    {
        $blueprintRepository = $this->createStub(IndustryBlueprintRepository::class);
        $blueprintRepository->method('find')->willReturnCallback(
            function (mixed $blueprintTypeId): ?IndustryBlueprint {
                $recipe = \is_int($blueprintTypeId) ? ($this->sde['recipes'][(string) ($blueprintTypeId - self::BLUEPRINT_TYPE_ID_OFFSET)] ?? null) : null;

                return $recipe === null ? null : (new IndustryBlueprint())
                    ->setTypeId($blueprintTypeId)
                    ->setMaxProductionLimit($recipe['maxProductionLimit']);
            },
        );

        return $blueprintRepository;
    }

    private function activityRepositoryOnGoldenSde(): IndustryActivityRepository
    {
        $activityRepository = $this->createStub(IndustryActivityRepository::class);
        $activityRepository->method('findOneBy')->willReturnCallback(
            function (array $criteria): ?IndustryActivity {
                $recipe = $this->sde['recipes'][(string) ($criteria['typeId'] - self::BLUEPRINT_TYPE_ID_OFFSET)] ?? null;
                if ($recipe === null || self::activityIdOf($recipe['activity']) !== $criteria['activityId']) {
                    return null;
                }

                return (new IndustryActivity())
                    ->setTypeId($criteria['typeId'])
                    ->setActivityId($criteria['activityId'])
                    ->setTime($recipe['baseTimeSeconds']);
            },
        );

        return $activityRepository;
    }

    private function typeRepositoryOnGoldenSde(): InvTypeRepository
    {
        $typeOf = fn (int $typeId): ?InvType => !in_array($typeId, $this->typeIdsWithoutSdeName, true) && isset($this->sde['typeNames'][(string) $typeId])
            ? (new InvType())->setTypeId($typeId)->setTypeName($this->sde['typeNames'][(string) $typeId])
            : null;

        $typeRepository = $this->createStub(InvTypeRepository::class);
        $typeRepository->method('find')->willReturnCallback($typeOf);
        $typeRepository->method('findBy')->willReturnCallback(
            static fn (array $criteria): array => array_values(array_filter(array_map($typeOf, (array) $criteria['typeId']))),
        );

        return $typeRepository;
    }

    private static function activityIdOf(string $activity): int
    {
        return $activity === 'reaction' ? IndustryActivityType::Reaction->value : IndustryActivityType::Manufacturing->value;
    }
}
