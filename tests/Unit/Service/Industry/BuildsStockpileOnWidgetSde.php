<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryStockpileImport;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryTreeService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

trait BuildsStockpileOnWidgetSde
{
    private const int WIDGET = 3001;
    private const int FRAME = 3002;
    private const int ORE = 3003;
    private const int GADGET = 3004;
    private const int HULL = 3005;
    private const int BLUEPRINT_TYPE_ID_OFFSET = 1_000_000;

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

    private function treeServiceOnWidgetSde(): IndustryTreeService
    {
        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('findBlueprintForProduct')->willReturnCallback(
            function (int $productTypeId, int $activityId): ?IndustryActivityProduct {
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

        $materialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $materialRepository->method('findBy')->willReturnCallback(
            function (array $criteria): array {
                $productTypeId = $criteria['typeId'] - self::BLUEPRINT_TYPE_ID_OFFSET;
                $materials = [];
                foreach ($this->recipes[$productTypeId]['materials'] as $materialTypeId => $baseQuantityPerRun) {
                    $materials[] = (new IndustryActivityMaterial())
                        ->setTypeId($criteria['typeId'])
                        ->setActivityId($criteria['activityId'])
                        ->setMaterialTypeId($materialTypeId)
                        ->setQuantity($baseQuantityPerRun);
                }

                return $materials;
            },
        );

        $typeRepository = $this->createStub(InvTypeRepository::class);
        $typeRepository->method('find')->willReturnCallback(
            static fn (int $typeId): ?InvType => isset(self::TYPE_NAMES[$typeId])
                ? (new InvType())->setTypeId($typeId)->setTypeName(self::TYPE_NAMES[$typeId])
                : null,
        );

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(0);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $bonusService = $this->createStub(IndustryBonusService::class);
        $bonusService->method('findBestStructureForProduct')->willReturn(
            ['structure' => null, 'bonus' => ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0], 'category' => null],
        );
        $bonusService->method('getCategoryForProduct')->willReturn(null);

        return new IndustryTreeService($productRepository, $materialRepository, $typeRepository, $entityManager, $bonusService);
    }
}
