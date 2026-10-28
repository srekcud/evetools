<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryStockpileImport;
use App\Entity\Sde\IndustryActivity;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\IndustryBlueprint;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Industry\Application\ProductionPlanning;
use App\Industry\Application\ProductionStructure;
use App\Industry\Infrastructure\Sde\SdeBlueprintCatalog;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\IndustryBlueprintRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\StockpileStartingStockReducer;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

trait BuildsStockpileOnWidgetSde
{
    private const int WIDGET = 3001;
    private const int FRAME = 3002;
    private const int ORE = 3003;
    private const int GADGET = 3004;
    private const int HULL = 3005;
    private const int BLUEPRINT_TYPE_ID_OFFSET = 1_000_000;
    private const int WIDGET_SDE_MAX_PRODUCTION_LIMIT = 1_000;
    private const int WIDGET_SDE_BASE_TIME_SECONDS = 3_600;
    private const string PILOT_ID = '0190f3c2-5b7e-7a41-9d2c-4e8b1f6a3c75';

    private const int FRAMES_PER_WIDGET_RUN = 10;
    private const int FRAMES_PER_GADGET_RUN = 10;
    private const int ORE_PER_FRAME_RUN = 70;
    private const int FRAMES_PER_RUN_OF_THE_TWO_FRAME_FORMULA = 2;
    private const int ORE_PER_RUN_OF_THE_TWO_FRAME_FORMULA = 140;

    private const array TYPE_NAMES = [
        self::WIDGET => 'Widget',
        self::FRAME => 'Frame',
        self::ORE => 'Ore',
        self::GADGET => 'Gadget',
        self::HULL => 'Hull',
    ];

    private array $recipes = [];

    private ?InMemoryUserProductionStructures $userStructures = null;

    private function useWidgetRecipes(): void
    {
        $this->recipes = [
            self::WIDGET => ['activity' => IndustryActivityType::Manufacturing, 'outputPerRun' => 1, 'materials' => [self::FRAME => self::FRAMES_PER_WIDGET_RUN]],
            self::FRAME => ['activity' => IndustryActivityType::Reaction, 'outputPerRun' => 1, 'materials' => [self::ORE => self::ORE_PER_FRAME_RUN]],
            self::GADGET => ['activity' => IndustryActivityType::Manufacturing, 'outputPerRun' => 1, 'materials' => [self::FRAME => self::FRAMES_PER_GADGET_RUN]],
        ];
    }

    private function useTheTwoFrameFormula(): void
    {
        $this->recipes[self::FRAME] = [
            'activity' => IndustryActivityType::Reaction,
            'outputPerRun' => self::FRAMES_PER_RUN_OF_THE_TWO_FRAME_FORMULA,
            'materials' => [self::ORE => self::ORE_PER_RUN_OF_THE_TWO_FRAME_FORMULA],
        ];
    }

    private function removeTheRecipeOf(int $productTypeId): void
    {
        unset($this->recipes[$productTypeId]);
    }

    private function stockpileImport(User $pilot, int $productTypeId, int $runs, int $me): IndustryStockpileImport
    {
        return new IndustryStockpileImport($pilot, $productTypeId, $runs, $me);
    }

    private function pilotWithId(): User
    {
        $pilot = $this->createStub(User::class);
        $pilot->method('getId')->willReturn(Uuid::fromString(self::PILOT_ID));

        return $pilot;
    }

    private function productionPlanningOnWidgetSde(): ProductionPlanning
    {
        return new ProductionPlanning(
            new SdeBlueprintCatalog(
                $this->productRepositoryOnWidgetSde(),
                $this->activityRepositoryOnWidgetSde(),
                $this->materialRepositoryOnWidgetSde(),
                $this->blueprintRepositoryOnWidgetSde(),
            ),
            InMemorySystemCostIndices::none(),
            new InMemoryRigCategories(),
        );
    }

    private function givenThePilotStructures(ProductionStructure ...$structures): void
    {
        $this->userStructures = new InMemoryUserProductionStructures([self::PILOT_ID => $structures]);
    }

    private function userStructuresOfThePilot(): InMemoryUserProductionStructures
    {
        return $this->userStructures ??= new InMemoryUserProductionStructures([self::PILOT_ID => []]);
    }

    private function startingStockReducerOnWidgetSde(LoggerInterface $logger = new NullLogger()): StockpileStartingStockReducer
    {
        return new StockpileStartingStockReducer(
            $this->productionPlanningOnWidgetSde(),
            $this->userStructuresOfThePilot(),
            $this->productRepositoryOnWidgetSde(),
            $logger,
        );
    }

    private function productRepositoryOnWidgetSde(): IndustryActivityProductRepository
    {
        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('findBlueprintForProduct')->willReturnCallback(
            function (int $productTypeId, int $activityId = 1): ?IndustryActivityProduct {
                $recipe = $this->recipes[$productTypeId] ?? null;
                if ($recipe === null || $recipe['activity']->value !== $activityId) {
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

    private function materialRepositoryOnWidgetSde(): IndustryActivityMaterialRepository
    {
        $materialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $materialRepository->method('findByBlueprintAndActivity')->willReturnCallback(
            function (int $blueprintTypeId, int $activityId = 1): array {
                $recipe = $this->recipes[$blueprintTypeId - self::BLUEPRINT_TYPE_ID_OFFSET] ?? null;
                if ($recipe === null || $recipe['activity']->value !== $activityId) {
                    return [];
                }
                $materials = [];
                foreach ($recipe['materials'] as $materialTypeId => $baseQuantityPerRun) {
                    $materials[] = (new IndustryActivityMaterial())
                        ->setTypeId($blueprintTypeId)
                        ->setActivityId($activityId)
                        ->setMaterialTypeId($materialTypeId)
                        ->setQuantity($baseQuantityPerRun);
                }

                return $materials;
            },
        );

        return $materialRepository;
    }

    private function blueprintRepositoryOnWidgetSde(): IndustryBlueprintRepository
    {
        $blueprintRepository = $this->createStub(IndustryBlueprintRepository::class);
        $blueprintRepository->method('find')->willReturnCallback(
            function (mixed $blueprintTypeId): ?IndustryBlueprint {
                $recipe = \is_int($blueprintTypeId) ? ($this->recipes[$blueprintTypeId - self::BLUEPRINT_TYPE_ID_OFFSET] ?? null) : null;

                return $recipe === null ? null : (new IndustryBlueprint())
                    ->setTypeId($blueprintTypeId)
                    ->setMaxProductionLimit($recipe['maxProductionLimit'] ?? self::WIDGET_SDE_MAX_PRODUCTION_LIMIT);
            },
        );

        return $blueprintRepository;
    }

    private function activityRepositoryOnWidgetSde(): IndustryActivityRepository
    {
        $activityRepository = $this->createStub(IndustryActivityRepository::class);
        $activityRepository->method('findOneBy')->willReturnCallback(
            function (array $criteria): ?IndustryActivity {
                $recipe = $this->recipes[$criteria['typeId'] - self::BLUEPRINT_TYPE_ID_OFFSET] ?? null;
                if ($recipe === null || $recipe['activity']->value !== $criteria['activityId']) {
                    return null;
                }

                return (new IndustryActivity())
                    ->setTypeId($criteria['typeId'])
                    ->setActivityId($criteria['activityId'])
                    ->setTime(self::WIDGET_SDE_BASE_TIME_SECONDS);
            },
        );

        return $activityRepository;
    }

    private function typeRepositoryOnWidgetSde(): InvTypeRepository
    {
        $typeOf = static fn (int $typeId): ?InvType => isset(self::TYPE_NAMES[$typeId])
            ? (new InvType())->setTypeId($typeId)->setTypeName(self::TYPE_NAMES[$typeId])
            : null;

        $typeRepository = $this->createStub(InvTypeRepository::class);
        $typeRepository->method('find')->willReturnCallback($typeOf);
        $typeRepository->method('findBy')->willReturnCallback(
            static fn (array $criteria): array => array_values(array_filter(array_map($typeOf, (array) $criteria['typeId']))),
        );

        return $typeRepository;
    }
}
