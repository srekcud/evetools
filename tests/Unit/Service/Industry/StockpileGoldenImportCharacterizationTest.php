<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryStockpileTarget;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryTreeService;
use App\Service\Industry\StockpileService;
use App\Service\JitaMarketService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Characterization of the stockpile import (previewImport, importFromBlueprint) on the goldens,
 * before buildProductionTree() is replaced by src/Industry/Application (docs/specs/industry-engine.md, §7 rank 3).
 *
 * The real IndustryTreeService runs on the SDE extract of the goldens (tests/Fixtures/Golden/sde-blueprint-recipes.json);
 * only the SDE repositories, the structure lookup (no structure configured) and the Jita prices are stubbed.
 * Materials are read in the fixture order (ascending typeId), which decides the stage of an item met at several depths.
 */
#[CoversClass(StockpileService::class)]
#[AllowMockObjectsWithoutExpectations]
final class StockpileGoldenImportCharacterizationTest extends TestCase
{
    private const string GOLDEN_DIR = __DIR__.'/../../../Fixtures/Golden/';
    private const int BLUEPRINT_TYPE_ID_OFFSET = 1_000_000;

    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int NITROGEN_ISOTOPES = 17888;
    private const int ROBOTICS = 9848;
    private const int COOLANT = 9832;
    private const int OXYGEN = 3683;
    private const int FERNITE_CARBIDE = 16673;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int NOMAD = 28846;

    /** @var array{recipes: array<string, array{name: string, activity: string, outputPerRun: int, materials: array<string, int>}>, typeNames: array<string, string>} */
    private array $sde;

    /** @var array<int, float> */
    private array $jitaPrices = [];

    private bool $typeNamesKnown = true;

    private IndustryStockpileTargetRepository $targetRepository;
    private EntityManagerInterface $entityManager;
    private StockpileService $service;
    private User $pilot;

    protected function setUp(): void
    {
        $this->sde = json_decode((string) file_get_contents(self::GOLDEN_DIR.'sde-blueprint-recipes.json'), true, flags: \JSON_THROW_ON_ERROR);

        $this->targetRepository = $this->createMock(IndustryStockpileTargetRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(0);
        $this->entityManager->method('getConnection')->willReturn($connection);

        $jitaMarketService = $this->createMock(JitaMarketService::class);
        $jitaMarketService->method('getPrices')->willReturnCallback(
            fn (array $typeIds): array => array_intersect_key($this->jitaPrices, array_flip($typeIds)),
        );

        $this->service = new StockpileService(
            $this->targetRepository,
            $this->createMock(CachedAssetRepository::class),
            $jitaMarketService,
            $this->treeServiceOnGoldenSde(),
            $this->entityManager,
        );
        $this->pilot = $this->createMock(User::class);
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #2 (R3, B1) : les runs saisis sont lus comme
    // une quantité. 25 runs de Nitrogen Fuel Block (40 unités par run) deviennent 1 run : cible 40 au lieu de 1000,
    // Nitrogen Isotopes 405 au lieu de 10125 (golden nitrogen-fuel-block-25runs-me10-npc, currentEvetoolsSimulated).
    public function testPreviewOfTwentyFiveRunsOfNitrogenFuelBlockReadsTheRunsAsAQuantityIssue2(): void
    {
        $preview = $this->service->previewImport($this->pilot, self::NITROGEN_FUEL_BLOCK, 25, 10);

        self::assertSame(
            [['typeId' => self::NITROGEN_FUEL_BLOCK, 'typeName' => 'Nitrogen Fuel Block', 'quantity' => 40, 'unitPrice' => null]],
            $preview['stages']['final_product'],
        );
        self::assertSame(
            [44 => 4, 3683 => 20, 3689 => 4, 9832 => 9, 9848 => 1, 16272 => 153, 16273 => 315, 16275 => 18, 17888 => 405],
            $this->quantitiesByTypeId($preview['stages']['raw_material']),
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
            $this->quantitiesByTypeId($preview['stages']['raw_material']),
        );
        self::assertSame(40, $preview['stages']['final_product'][0]['quantity']);
    }

    public function testPreviewOfFortyUnitsOfNitrogenFuelBlockAtMeTenRoundsEachMaterialUpWithAMinimumOfOnePerRun(): void
    {
        $preview = $this->service->previewImport($this->pilot, self::NITROGEN_FUEL_BLOCK, 41, 10);

        self::assertSame(80, $preview['stages']['final_product'][0]['quantity']);
        self::assertSame(
            [44 => 8, 3683 => 40, 3689 => 8, 9832 => 17, 9848 => 2, 16272 => 306, 16273 => 630, 16275 => 36, 17888 => 810],
            $this->quantitiesByTypeId($preview['stages']['raw_material']),
        );
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #8 (R10) : un item sans prix Jita est exclu
    // de estimatedCost, qui reste affiché comme un montant connu au lieu d'un coût inconnu.
    public function testPreviewEstimatedCostSilentlyLeavesOutTheItemsWithoutJitaPriceIssue8(): void
    {
        $this->jitaPrices = [
            self::NITROGEN_FUEL_BLOCK => 600.5,
            self::NITROGEN_ISOTOPES => 0.25,
            self::COOLANT => 150.0,
        ];

        $preview = $this->service->previewImport($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);

        self::assertSame(600.5, $preview['stages']['final_product'][0]['unitPrice']);
        $robotics = $this->entryOf($preview['stages']['raw_material'], self::ROBOTICS);
        self::assertSame(['typeId' => self::ROBOTICS, 'typeName' => 'Robotics', 'quantity' => 1, 'unitPrice' => null], $robotics);
        self::assertSame(['typeId' => self::OXYGEN, 'typeName' => 'Oxygen', 'quantity' => 20, 'unitPrice' => null], $this->entryOf($preview['stages']['raw_material'], self::OXYGEN));
        // 40 × 600.5 + 405 × 0.25 + 9 × 150 = 24020 + 101.25 + 1350
        self::assertSame(25471.25, $preview['estimatedCost']);
    }

    public function testPreviewRoundsTheEstimatedCostToTwoDecimals(): void
    {
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 0.333];

        $preview = $this->service->previewImport($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);

        // 405 × 0.333 = 134.865
        self::assertSame(134.87, $preview['estimatedCost']);
    }

    public function testPreviewNamesAnItemMissingFromTheSdeTypesByItsTypeId(): void
    {
        $this->typeNamesKnown = false;

        $preview = $this->service->previewImport($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);

        self::assertSame('Type #4051', $preview['stages']['final_product'][0]['typeName']);
        self::assertSame('Type #9848', $this->entryOf($preview['stages']['raw_material'], self::ROBOTICS)['typeName']);
    }

    public function testPreviewOfAProductWithoutBlueprintNorReactionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No blueprint or reaction found for product type ID '.self::ROBOTICS);

        $this->service->previewImport($this->pilot, self::ROBOTICS, 1, 10);
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issues #2 et #16 : 10 runs de Fernite Carbide
    // (10000 unités par run) deviennent 1 run ; le Hydrogen Fuel Block, consommé par la réaction racine et par ses
    // deux réactions intermédiaires, additionne trois arrondis séparés (5 + 5 + 5) et garde l'étape « component »
    // de sa première rencontre. Le ME saisi (10) est ignoré, comme attendu pour une réaction (R2).
    public function testPreviewOfTenRunsOfFerniteCarbideReadsTheRunsAsAQuantityAndSumsTheFuelBlockPerBranchIssues2And16(): void
    {
        $preview = $this->service->previewImport($this->pilot, self::FERNITE_CARBIDE, 10, 10);

        self::assertSame(10000, $preview['stages']['final_product'][0]['quantity']);
        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 15, 16656 => 100, 16660 => 100],
            $this->quantitiesByTypeId($preview['stages']['component']),
        );
        self::assertSame([], $preview['stages']['intermediate']);
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #69 (R3) : la demande d'un intermédiaire n'est
    // pas agrégée entre branches avant l'arrondi en runs. Sur le golden Nomad (Ravworks, fait foi), 29 feuilles sur 63
    // et 19 intermédiaires sur 65 sont surestimés dans les cibles du stockpile (Oxygen Isotopes +50 %).
    public function testPreviewOfOneNomadOverestimatesTheNonAggregatedLeavesAndIntermediatesIssue69(): void
    {
        $golden = json_decode((string) file_get_contents(self::GOLDEN_DIR.'nomad-1run-me10-no-structure.ravworks.json'), true, flags: \JSON_THROW_ON_ERROR);
        $goldenLeaves = array_column($golden['expected']['leaves'], 'quantity', 'typeId');
        $goldenDemandOfIntermediates = array_column($golden['expected']['intermediates'], 'quantityNeeded', 'typeId');

        $preview = $this->service->previewImport($this->pilot, self::NOMAD, 1, 10);

        $currentLeaves = array_replace($goldenLeaves, [
            17887 => 26730, 16634 => 75100, 16633 => 89700, 44 => 2358, 3689 => 2358, 9832 => 5273, 3683 => 12713,
            16272 => 97920, 16273 => 201600, 9848 => 640, 16275 => 11520, 17888 => 46170, 16274 => 46575, 17889 => 139725,
            16652 => 6800, 16650 => 6000, 16643 => 10300, 16653 => 3600, 16651 => 11400, 16648 => 3900, 16646 => 12200,
            16647 => 5900, 16635 => 92900, 16649 => 17200, 16642 => 59400, 16644 => 40100, 16636 => 107500, 16639 => 55500,
            16641 => 23700,
        ]);
        $currentDemandOfIntermediates = array_replace($goldenDemandOfIntermediates, [
            16663 => 10100, 57453 => 25000, 16660 => 140000, 16668 => 8700, 16656 => 110400, 16669 => 3100, 17769 => 7100,
            4247 => 4265, 16665 => 45500, 4246 => 12960, 16666 => 3100, 16667 => 15200, 4051 => 4260, 57454 => 198,
            4312 => 1815, 16662 => 34100, 17960 => 10200, 16661 => 17000, 57455 => 25000,
        ]);
        $previewIntermediates = $this->quantitiesByTypeId([...$preview['stages']['component'], ...$preview['stages']['intermediate']]);

        $previewLeaves = $this->quantitiesByTypeId($preview['stages']['raw_material']);

        ksort($currentLeaves);
        ksort($previewLeaves);
        self::assertSame($currentLeaves, $previewLeaves);
        ksort($currentDemandOfIntermediates);
        ksort($previewIntermediates);
        self::assertSame($currentDemandOfIntermediates, $previewIntermediates);
        self::assertSame(
            [11478 => 54, 20189 => 1, 21025 => 27, 29043 => 607, 29045 => 951, 29049 => 1368, 29065 => 599, 29075 => 951, 29079 => 700, 29089 => 570],
            $this->quantitiesByTypeId($preview['stages']['component']),
        );
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
        $persisted = [];
        $this->entityManager->method('persist')->willReturnCallback(static function (object $target) use (&$persisted): void {
            $persisted[] = $target;
        });

        $this->service->importFromBlueprint($this->pilot, self::NITROGEN_FUEL_BLOCK, 25, 10, 'merge');

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
            ], $persisted),
        );
        self::assertSame($this->pilot, $persisted[0]->getUser());
    }

    public function testImportInReplaceModeDeletesTheTargetsOfThePilotBeforePersistingTheTree(): void
    {
        $calls = [];
        $this->targetRepository->method('deleteAllForUser')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'delete';
        });
        $this->targetRepository->expects(self::never())->method('findByUserAndTypeId');
        $this->entityManager->method('flush')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'flush';
        });
        $this->entityManager->method('persist')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'persist';
        });

        $this->service->importFromBlueprint($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10, 'replace');

        self::assertSame(['delete', 'flush', ...array_fill(0, 10, 'persist'), 'flush'], $calls);
    }

    public function testFlattenTreeReadsTheStageFromTheDepthOfEachBlueprintNodeAndDefaultsMissingFields(): void
    {
        $tree = [
            'productTypeName' => 'Widget',
            'runs' => 2,
            'outputPerRun' => 3,
            'materials' => [
                ['typeId' => 1, 'typeName' => 'Ore Without Buildable Flag', 'quantity' => 7],
                ['typeId' => 2, 'typeName' => 'Buildable Without Blueprint', 'quantity' => 4, 'isBuildable' => true],
                ['typeId' => 3, 'typeName' => 'Frame Without Depth', 'quantity' => 5, 'isBuildable' => true, 'blueprint' => [
                    'materials' => [['typeId' => 4, 'typeName' => 'Plate', 'quantity' => 6, 'isBuildable' => true, 'blueprint' => [
                        'depth' => 0,
                        'materials' => [['typeId' => 5, 'typeName' => 'Rivet', 'quantity' => 8, 'isBuildable' => true]],
                    ]]],
                ]],
            ],
        ];

        self::assertSame(
            [
                100 => ['typeName' => 'Widget', 'quantity' => 6, 'stage' => 'final_product'],
                1 => ['typeName' => 'Ore Without Buildable Flag', 'quantity' => 7, 'stage' => 'raw_material'],
                2 => ['typeName' => 'Buildable Without Blueprint', 'quantity' => 4, 'stage' => 'component'],
                3 => ['typeName' => 'Frame Without Depth', 'quantity' => 5, 'stage' => 'component'],
                4 => ['typeName' => 'Plate', 'quantity' => 6, 'stage' => 'intermediate'],
                5 => ['typeName' => 'Rivet', 'quantity' => 8, 'stage' => 'component'],
            ],
            $this->service->flattenTree($tree, 100),
        );
    }

    /**
     * @param list<array{typeId: int, quantity: int}> $entries
     * @return array<int, int>
     */
    private function quantitiesByTypeId(array $entries): array
    {
        return array_column($entries, 'quantity', 'typeId');
    }

    /**
     * @param list<array{typeId: int}> $entries
     * @return array<string, mixed>
     */
    private function entryOf(array $entries, int $typeId): array
    {
        foreach ($entries as $entry) {
            if ($entry['typeId'] === $typeId) {
                return $entry;
            }
        }
        self::fail("No entry for type {$typeId}");
    }

    private function treeServiceOnGoldenSde(): IndustryTreeService
    {
        $recipes = $this->sde['recipes'];

        $productRepository = $this->createMock(IndustryActivityProductRepository::class);
        $productRepository->method('findBlueprintForProduct')->willReturnCallback(
            static function (int $productTypeId, int $activityId) use ($recipes): ?IndustryActivityProduct {
                $recipe = $recipes[(string) $productTypeId] ?? null;
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

        $materialRepository = $this->createMock(IndustryActivityMaterialRepository::class);
        $materialRepository->method('findBy')->willReturnCallback(
            static function (array $criteria) use ($recipes): array {
                $productTypeId = $criteria['typeId'] - self::BLUEPRINT_TYPE_ID_OFFSET;
                $materials = [];
                foreach ($recipes[(string) $productTypeId]['materials'] as $materialTypeId => $baseQuantityPerRun) {
                    $materials[] = (new IndustryActivityMaterial())
                        ->setTypeId($criteria['typeId'])
                        ->setActivityId($criteria['activityId'])
                        ->setMaterialTypeId((int) $materialTypeId)
                        ->setQuantity($baseQuantityPerRun);
                }

                return $materials;
            },
        );

        $typeRepository = $this->createMock(InvTypeRepository::class);
        $typeRepository->method('find')->willReturnCallback(
            fn (int $typeId): ?InvType => $this->typeNamesKnown && isset($this->sde['typeNames'][(string) $typeId])
                ? (new InvType())->setTypeId($typeId)->setTypeName($this->sde['typeNames'][(string) $typeId])
                : null,
        );

        $noStructure = ['structure' => null, 'bonus' => ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0], 'category' => null];
        $bonusService = $this->createMock(IndustryBonusService::class);
        $bonusService->method('findBestStructureForProduct')->willReturn($noStructure);
        $bonusService->method('getCategoryForProduct')->willReturn(null);

        return new IndustryTreeService($productRepository, $materialRepository, $typeRepository, $this->entityManager, $bonusService);
    }

    private static function activityIdOf(string $activity): int
    {
        return $activity === 'reaction' ? IndustryActivityType::Reaction->value : IndustryActivityType::Manufacturing->value;
    }
}
