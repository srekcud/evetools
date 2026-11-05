<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Enum\IndustryActivityType;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

trait BuildsProjectOnGoldenSde
{
    private const string GOLDEN_DIR = __DIR__.'/../../../Fixtures/Golden/';
    private const int SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET = 1_000_000;
    private const int UNCATEGORIZED_GROUP_ID = 999_999_001;

    private array $typeIdsMissingFromSde = [];

    private function entityManagerWithoutRows(): EntityManagerInterface
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(0);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        return $entityManager;
    }

    private function typeRepositoryOnGoldenSde(): InvTypeRepository
    {
        $knownTypes = [];
        foreach (self::goldenTypeNames() as $typeId => $typeName) {
            if (!\in_array($typeId, $this->typeIdsMissingFromSde, true)) {
                $knownTypes[$typeId] = self::invType($typeId, $typeName);
            }
        }

        $typeRepository = $this->createStub(InvTypeRepository::class);
        $typeRepository->method('find')->willReturnCallback(
            static fn (mixed $typeId): ?InvType => \is_int($typeId) ? ($knownTypes[$typeId] ?? null) : null,
        );
        $typeRepository->method('findByTypeIds')->willReturnCallback(
            static fn (array $typeIds): array => array_intersect_key($knownTypes, array_flip($typeIds)),
        );

        return $typeRepository;
    }

    private function productRepositoryOnGoldenSde(): IndustryActivityProductRepository
    {
        $recipes = self::goldenRecipes();

        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('findBlueprintForProduct')->willReturnCallback(
            static function (int $productTypeId, int $activityId = 1) use ($recipes): ?IndustryActivityProduct {
                $recipe = $recipes[$productTypeId] ?? null;
                if ($recipe === null || self::activityIdOf($recipe['activity']) !== $activityId) {
                    return null;
                }

                return self::activityProduct($productTypeId, $recipe);
            },
        );
        $productRepository->method('findProductsForBlueprints')->willReturnCallback(
            static function (array $blueprintTypeIds, array $activityIds) use ($recipes): array {
                $products = [];
                foreach ($blueprintTypeIds as $blueprintTypeId) {
                    $productTypeId = $blueprintTypeId - self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET;
                    $recipe = $recipes[$productTypeId] ?? null;
                    if ($recipe !== null && \in_array(self::activityIdOf($recipe['activity']), $activityIds, true)) {
                        $products[$blueprintTypeId.'-'.self::activityIdOf($recipe['activity'])] = self::activityProduct($productTypeId, $recipe);
                    }
                }

                return $products;
            },
        );

        return $productRepository;
    }

    private function materialRepositoryOnGoldenSde(): IndustryActivityMaterialRepository
    {
        $materialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $materialRepository->method('findBy')->willReturnCallback(
            static fn (array $criteria): array => self::activityMaterials((int) $criteria['typeId'], (int) $criteria['activityId']),
        );
        $materialRepository->method('findMaterialEntitiesForBlueprints')->willReturnCallback(
            static function (array $blueprintTypeIds, array $activityIds): array {
                $materialsByBlueprint = [];
                foreach (array_unique($blueprintTypeIds) as $blueprintTypeId) {
                    foreach (array_unique($activityIds) as $activityId) {
                        $materials = self::activityMaterials($blueprintTypeId, $activityId);
                        if ($materials !== []) {
                            $materialsByBlueprint[$blueprintTypeId.'-'.$activityId] = $materials;
                        }
                    }
                }

                return $materialsByBlueprint;
            },
        );

        return $materialRepository;
    }

    private static function activityMaterials(int $blueprintTypeId, int $activityId): array
    {
        $recipe = self::goldenRecipes()[$blueprintTypeId - self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET] ?? null;
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
    }

    private static function activityProduct(int $productTypeId, array $recipe): IndustryActivityProduct
    {
        return (new IndustryActivityProduct())
            ->setTypeId($productTypeId + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET)
            ->setActivityId(self::activityIdOf($recipe['activity']))
            ->setProductTypeId($productTypeId)
            ->setQuantity($recipe['outputPerRun']);
    }

    private static function activityIdOf(string $activity): int
    {
        return $activity === 'reaction' ? IndustryActivityType::Reaction->value : IndustryActivityType::Manufacturing->value;
    }

    private static function invType(int $typeId, string $typeName): InvType
    {
        return (new InvType())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setGroup((new InvGroup())->setGroupId(self::UNCATEGORIZED_GROUP_ID)->setGroupName('Uncategorized'));
    }

    private static function goldenRecipes(): array
    {
        static $recipes = null;
        if ($recipes === null) {
            $recipes = [];
            foreach (self::sdeFixture()['recipes'] as $productTypeId => $recipe) {
                $recipes[(int) $productTypeId] = $recipe;
            }
        }

        return $recipes;
    }

    private static function goldenTypeNames(): array
    {
        $typeNames = [];
        foreach (self::sdeFixture()['typeNames'] as $typeId => $typeName) {
            $typeNames[(int) $typeId] = $typeName;
        }

        return $typeNames;
    }

    private static function sdeFixture(): array
    {
        return json_decode((string) file_get_contents(self::GOLDEN_DIR.'sde-blueprint-recipes.json'), true, flags: \JSON_THROW_ON_ERROR);
    }
}
