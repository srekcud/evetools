<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Enum\IndustryActivityType;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\SplitGroupMaterialNeeds;
use App\Service\TypeNameResolver;

trait BuildsSplitGroupMaterialNeeds
{
    private const int RECIPE_BLUEPRINT_TYPE_ID_OFFSET = 1_000_000;

    private const int QUANTUM_MICROPROCESSOR = 11540;
    private const int TITANIUM_CARBIDE = 16671;
    private const int PHENOLIC_COMPOSITES = 16680;
    private const int NANOTRANSISTORS = 16681;
    private const int NONLINEAR_METAMATERIALS = 33362;
    private const int SCORPION = 640;
    private const int NESTOR = 33472;
    private const int AUTO_INTEGRITY_PRESERVATION_SEAL = 57478;
    private const int CORE_TEMPERATURE_REGULATOR = 57479;
    private const int LIFE_SUPPORT_BACKUP_UNIT = 57486;
    private const string ADVANCED_COMPONENT_CATEGORY = 'advanced_component';

    private const array RECIPES = [
        self::QUANTUM_MICROPROCESSOR => ['activity' => 'manufacturing', 'outputPerRun' => 1, 'materials' => [self::TITANIUM_CARBIDE => 17, self::PHENOLIC_COMPOSITES => 6, self::NANOTRANSISTORS => 2, self::NONLINEAR_METAMATERIALS => 2]],
        self::TITANIUM_CARBIDE => ['activity' => 'reaction', 'outputPerRun' => 10_000, 'materials' => []],
        self::PHENOLIC_COMPOSITES => ['activity' => 'reaction', 'outputPerRun' => 2_200, 'materials' => []],
        self::NANOTRANSISTORS => ['activity' => 'reaction', 'outputPerRun' => 1_500, 'materials' => []],
        self::NONLINEAR_METAMATERIALS => ['activity' => 'reaction', 'outputPerRun' => 300, 'materials' => []],
        self::SCORPION => ['activity' => 'manufacturing', 'outputPerRun' => 1, 'materials' => [self::AUTO_INTEGRITY_PRESERVATION_SEAL => 112, self::CORE_TEMPERATURE_REGULATOR => 1, self::LIFE_SUPPORT_BACKUP_UNIT => 56]],
        self::NESTOR => ['activity' => 'manufacturing', 'outputPerRun' => 1, 'materials' => [self::AUTO_INTEGRITY_PRESERVATION_SEAL => 264, self::CORE_TEMPERATURE_REGULATOR => 1, self::LIFE_SUPPORT_BACKUP_UNIT => 132]],
        self::AUTO_INTEGRITY_PRESERVATION_SEAL => ['activity' => 'manufacturing', 'outputPerRun' => 3, 'materials' => []],
        self::LIFE_SUPPORT_BACKUP_UNIT => ['activity' => 'manufacturing', 'outputPerRun' => 3, 'materials' => []],
    ];

    private static function blueprintOfRecipe(int $productTypeId): int
    {
        return $productTypeId + self::RECIPE_BLUEPRINT_TYPE_ID_OFFSET;
    }

    private static function activityIdOfRecipe(int $productTypeId): int
    {
        return self::RECIPES[$productTypeId]['activity'] === 'reaction'
            ? IndustryActivityType::Reaction->value
            : IndustryActivityType::Manufacturing->value;
    }

    private function splitGroupMaterialNeedsOnRecipes(array $blueprintsMissingFromTheSde = []): SplitGroupMaterialNeeds
    {
        return new SplitGroupMaterialNeeds(
            $this->calculationServiceWithoutStructure(),
            $this->materialRepositoryOnRecipes(),
            $this->productRepositoryOnRecipes($blueprintsMissingFromTheSde),
        );
    }

    private function splitGroupMaterialNeedsOnRecipesInAStructure(float $baseMaterialBonus, float $rigMaterialBonus): SplitGroupMaterialNeeds
    {
        return new SplitGroupMaterialNeeds(
            $this->calculationServiceInAStructure($baseMaterialBonus, $rigMaterialBonus),
            $this->materialRepositoryOnRecipes(),
            $this->productRepositoryOnRecipes([]),
        );
    }

    private function recipeProductsByBlueprintAndActivity(array $blueprintsMissingFromTheSde = []): array
    {
        $products = [];
        foreach (self::RECIPES as $productTypeId => $recipe) {
            $blueprintTypeId = self::blueprintOfRecipe($productTypeId);
            if (in_array($blueprintTypeId, $blueprintsMissingFromTheSde, true)) {
                continue;
            }
            $activityId = self::activityIdOfRecipe($productTypeId);
            $products[$blueprintTypeId.'-'.$activityId] = (new IndustryActivityProduct())
                ->setTypeId($blueprintTypeId)
                ->setActivityId($activityId)
                ->setProductTypeId($productTypeId)
                ->setQuantity($recipe['outputPerRun']);
        }

        return $products;
    }

    private function productRepositoryOnRecipes(array $blueprintsMissingFromTheSde): IndustryActivityProductRepository
    {
        $products = $this->recipeProductsByBlueprintAndActivity($blueprintsMissingFromTheSde);
        $repository = $this->createStub(IndustryActivityProductRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?IndustryActivityProduct => $products[($criteria['typeId'] ?? '').'-'.($criteria['activityId'] ?? '')] ?? null,
        );
        $repository->method('findProductsForBlueprints')->willReturnCallback(
            static fn (array $blueprintTypeIds, array $activityIds): array => array_filter(
                $products,
                static fn (IndustryActivityProduct $product): bool => in_array($product->getTypeId(), $blueprintTypeIds, true) && in_array($product->getActivityId(), $activityIds, true),
            ),
        );
        $repository->method('findBlueprintForProduct')->willReturnCallback(
            static function (int $productTypeId, int $activityId = 1) use ($products): ?IndustryActivityProduct {
                foreach ($products as $product) {
                    if ($product->getProductTypeId() === $productTypeId && $product->getActivityId() === $activityId) {
                        return $product;
                    }
                }

                return null;
            },
        );

        return $repository;
    }

    private function materialRepositoryOnRecipes(): IndustryActivityMaterialRepository
    {
        $materialsByBlueprintAndActivity = [];
        foreach (self::RECIPES as $productTypeId => $recipe) {
            $blueprintTypeId = self::blueprintOfRecipe($productTypeId);
            $activityId = self::activityIdOfRecipe($productTypeId);
            foreach ($recipe['materials'] as $materialTypeId => $baseQuantityPerRun) {
                $materialsByBlueprintAndActivity[$blueprintTypeId.'-'.$activityId][] = (new IndustryActivityMaterial())
                    ->setTypeId($blueprintTypeId)
                    ->setActivityId($activityId)
                    ->setMaterialTypeId($materialTypeId)
                    ->setQuantity($baseQuantityPerRun);
            }
        }

        $repository = $this->createStub(IndustryActivityMaterialRepository::class);
        $repository->method('findMaterialEntitiesForBlueprints')->willReturnCallback(
            static function (array $blueprintTypeIds, array $activityIds) use ($materialsByBlueprintAndActivity): array {
                $found = [];
                foreach (array_unique($blueprintTypeIds) as $blueprintTypeId) {
                    foreach (array_unique($activityIds) as $activityId) {
                        if (isset($materialsByBlueprintAndActivity[$blueprintTypeId.'-'.$activityId])) {
                            $found[$blueprintTypeId.'-'.$activityId] = $materialsByBlueprintAndActivity[$blueprintTypeId.'-'.$activityId];
                        }
                    }
                }

                return $found;
            },
        );
        $repository->method('findBy')->willReturnCallback(
            static fn (array $criteria): array => $materialsByBlueprintAndActivity[($criteria['typeId'] ?? '').'-'.($criteria['activityId'] ?? '')] ?? [],
        );
        $repository->method('findByBlueprintAndActivity')->willReturnCallback(
            static fn (int $typeId, int $activityId = 1): array => $materialsByBlueprintAndActivity[$typeId.'-'.$activityId] ?? [],
        );

        return $repository;
    }

    private function calculationServiceWithoutStructure(): IndustryCalculationService
    {
        $bonusService = $this->createStub(IndustryBonusService::class);
        $bonusService->method('findBestStructureForProduct')->willReturn(['structure' => null, 'bonus' => ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0], 'category' => null]);
        $bonusService->method('getCategoryForProduct')->willReturn(null);

        return $this->calculationServiceWith($bonusService);
    }

    private function calculationServiceInAStructure(float $baseMaterialBonus, float $rigMaterialBonus): IndustryCalculationService
    {
        $bonusService = $this->createStub(IndustryBonusService::class);
        $bonusService->method('getCategoryForProduct')->willReturn(self::ADVANCED_COMPONENT_CATEGORY);
        $bonusService->method('calculateStructureBonusForCategory')->willReturn(['total' => $baseMaterialBonus + $rigMaterialBonus, 'base' => $baseMaterialBonus, 'rig' => $rigMaterialBonus]);
        $bonusService->method('calculateStructureTimeBonusForCategory')->willReturn(0.0);

        return $this->calculationServiceWith($bonusService);
    }

    private function calculationServiceWith(IndustryBonusService $bonusService): IndustryCalculationService
    {
        $typeRepository = $this->createStub(InvTypeRepository::class);
        $settingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $settingsRepository->method('findOneBy')->willReturn(null);

        return new IndustryCalculationService(
            new TypeNameResolver($typeRepository),
            $bonusService,
            $this->createStub(IndustryStructureConfigRepository::class),
            $settingsRepository,
            $this->createStub(StaStationRepository::class),
            $this->createStub(CachedStructureRepository::class),
        );
    }
}
