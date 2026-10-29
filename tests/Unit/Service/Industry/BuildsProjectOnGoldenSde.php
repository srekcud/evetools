<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\Sde\IndustryActivity;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\IndustryBlueprint;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Enum\IndustryActivityType;
use App\Industry\Application\InventionCatalog;
use App\Industry\Application\InventionRecipe;
use App\Industry\Domain\Runs;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\IndustryBlueprintRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryProjectFactory;
use App\Service\Industry\IndustryTreeService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\TypeNameResolver;
use App\Tests\Unit\Industry\Application\Double\InMemoryInventionCatalog;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;

trait BuildsProjectOnGoldenSde
{
    use AutowiresByType;

    private const string GOLDEN_DIR = __DIR__.'/../../../Fixtures/Golden/';
    private const int SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET = 1_000_000;
    private const int UNCATEGORIZED_GROUP_ID = 999_999_001;
    private const array T1_BLUEPRINT_AND_T1_PRODUCT_OF_EACH_INVENTED_GOLDEN_PRODUCT = [
        22436 => [1_000_640, 640],
        28846 => [1_020_189, 20189],
        12779 => [2_000_000_001, 2_000_000_002],
    ];

    private array $typeIdsMissingFromSde = [];

    private array $typeIdsWithoutBaseTime = [];

    private bool $goldenBlueprintsHaveTheirCopyActivity = false;

    private array $typeIdsWithoutIndustryBlueprint = [];

    private array $typeIdsWithZeroMaxProductionLimit = [];

    private bool $goldenT2ProductsAreInvented = true;

    private function projectFactoryOnGoldenSde(IndustryBonusService $bonusService, IndustryCalculationService $calculationService, ?EntityManagerInterface $projectEntityManager = null, ?CachedCharacterSkillRepository $skillRepository = null, ?HubInterface $mercureHub = null): IndustryProjectFactory
    {
        $typeRepository = $this->typeRepositoryOnGoldenSde();
        $materialRepository = $this->materialRepositoryOnGoldenSde();

        return self::autowired(IndustryProjectFactory::class, [
            new IndustryTreeService($this->productRepositoryOnGoldenSde(), $materialRepository, $typeRepository, $this->entityManagerWithCopyActivitiesOfGoldenSde(), $bonusService),
            new IndustryBlacklistService($this->entityManagerWithoutRows()),
            $bonusService,
            $calculationService,
            $skillRepository ?? $this->createStub(CachedCharacterSkillRepository::class),
            $typeRepository,
            $materialRepository,
            $this->activityRepositoryOnGoldenSde(),
            $this->blueprintRepositoryOnGoldenSde(),
            $projectEntityManager ?? $this->entityManagerWithoutRows(),
            new MercurePublisherService($mercureHub ?? $this->createStub(HubInterface::class), new NullLogger()),
            $this->inventionCatalogOfGoldenSde(),
        ]);
    }

    private function inventionCatalogOfGoldenSde(): InventionCatalog
    {
        if (!$this->goldenT2ProductsAreInvented) {
            return new InMemoryInventionCatalog();
        }

        return new InMemoryInventionCatalog(array_map(
            static fn (array $t1BlueprintAndT1Product): InventionRecipe => new InventionRecipe($t1BlueprintAndT1Product[0], $t1BlueprintAndT1Product[1], null, new Runs(1), []),
            self::T1_BLUEPRINT_AND_T1_PRODUCT_OF_EACH_INVENTED_GOLDEN_PRODUCT,
        ));
    }

    private function calculationServiceOnGoldenSde(IndustryBonusService $bonusService, IndustryStructureConfigRepository $structureConfigRepository, ?CachedCharacterSkillRepository $skillRepository = null): IndustryCalculationService
    {
        $settingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $settingsRepository->method('findOneBy')->willReturn(null);

        return new IndustryCalculationService(
            new TypeNameResolver($this->typeRepositoryOnGoldenSde()),
            $bonusService,
            $structureConfigRepository,
            $settingsRepository,
            $this->entityManagerWithoutRows(),
            $this->createStub(StaStationRepository::class),
            $this->createStub(CachedStructureRepository::class),
            $this->activityRepositoryOnGoldenSde(),
            $skillRepository ?? $this->createStub(CachedCharacterSkillRepository::class),
        );
    }

    private function entityManagerWithoutRows(): EntityManagerInterface
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(0);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        return $entityManager;
    }

    private function entityManagerWithCopyActivitiesOfGoldenSde(): EntityManagerInterface
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(
            fn (string $sql, array $params = []): int => $this->goldenBlueprintsHaveTheirCopyActivity
                && ($params[1] ?? null) === IndustryActivityType::Copying->value
                && (self::goldenRecipes()[$params[0] - self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET]['hasCopyActivity'] ?? false) ? 1 : 0,
        );
        $connection->method('fetchAllAssociative')->willReturn([]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        return $entityManager;
    }

    private function blueprintRepositoryOnGoldenSde(): IndustryBlueprintRepository
    {
        $blueprintsOf = fn (array $blueprintTypeIds): array => array_values(array_filter(array_map($this->industryBlueprintOnGoldenSde(...), $blueprintTypeIds)));

        $blueprintRepository = $this->createStub(IndustryBlueprintRepository::class);
        $blueprintRepository->method('find')->willReturnCallback(
            fn (mixed $blueprintTypeId): ?IndustryBlueprint => \is_int($blueprintTypeId) ? $this->industryBlueprintOnGoldenSde($blueprintTypeId) : null,
        );
        $blueprintRepository->method('findBy')->willReturnCallback(
            static fn (array $criteria): array => $blueprintsOf((array) ($criteria['typeId'] ?? [])),
        );
        $blueprintRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?IndustryBlueprint => $blueprintsOf((array) ($criteria['typeId'] ?? []))[0] ?? null,
        );

        return $blueprintRepository;
    }

    private function industryBlueprintOnGoldenSde(int $blueprintTypeId): ?IndustryBlueprint
    {
        $productTypeId = $blueprintTypeId - self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET;
        $recipe = self::goldenRecipes()[$productTypeId] ?? null;
        if ($recipe === null || \in_array($productTypeId, $this->typeIdsWithoutIndustryBlueprint, true)) {
            return null;
        }

        return (new IndustryBlueprint())
            ->setTypeId($blueprintTypeId)
            ->setMaxProductionLimit(\in_array($productTypeId, $this->typeIdsWithZeroMaxProductionLimit, true) ? 0 : $recipe['maxProductionLimit']);
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

    private function activityRepositoryOnGoldenSde(): IndustryActivityRepository
    {
        $activityRepository = $this->createStub(IndustryActivityRepository::class);
        $activityRepository->method('findByTypeIdsAndActivityIds')->willReturnCallback(
            function (array $blueprintTypeIds, array $activityIds): array {
                $recipes = self::goldenRecipes();
                $activities = [];
                foreach ($blueprintTypeIds as $blueprintTypeId) {
                    $productTypeId = $blueprintTypeId - self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET;
                    $recipe = $recipes[$productTypeId] ?? null;
                    if ($recipe === null || \in_array($productTypeId, $this->typeIdsWithoutBaseTime, true)) {
                        continue;
                    }
                    $activityId = self::activityIdOf($recipe['activity']);
                    if (\in_array($activityId, $activityIds, true)) {
                        $activities[$blueprintTypeId.'-'.$activityId] = (new IndustryActivity())
                            ->setTypeId($blueprintTypeId)
                            ->setActivityId($activityId)
                            ->setTime($recipe['baseTimeSeconds']);
                    }
                }

                return $activities;
            },
        );

        return $activityRepository;
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
