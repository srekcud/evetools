<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryShoppingListBuilder;
use App\Service\Industry\IndustryTreeService;
use App\Service\TypeNameResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Wires the real IndustryShoppingListBuilder, IndustryTreeService, IndustryBonusService, IndustryCalculationService
 * and IndustryBlacklistService on the SDE extract of the goldens (tests/Fixtures/Golden/sde-blueprint-recipes.json).
 * Stubbed boundaries only: SDE repositories, structure configs, user settings, database connection.
 *
 * The extract has no blueprint typeId: each recipe gets a synthetic one (product typeId + offset).
 * Every type sits in a group without rig category, so a structure only brings its base bonus.
 */
trait BuildsShoppingListOnGoldenSde
{
    private const int SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET = 900_000_000;
    private const string GOLDEN_DIR = __DIR__.'/../../../Fixtures/Golden/';
    private const int UNCATEGORIZED_GROUP_ID = 999_999_001;

    /** @var list<IndustryStructureConfig> */
    private array $pilotStructures = [];

    private array $typeIdsMissingFromSde = [];

    private function shoppingListBuilderOnGoldenSde(): IndustryShoppingListBuilder
    {
        $recipes = self::goldenRecipes();
        $typeNames = self::goldenTypeNames();

        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturnCallback(
            fn (int $typeId): ?InvType => isset($typeNames[$typeId]) && !\in_array($typeId, $this->typeIdsMissingFromSde, true)
                ? self::invType($typeId, $typeNames[$typeId])
                : null,
        );

        $activityProductRepository = $this->createStub(IndustryActivityProductRepository::class);
        $activityProductRepository->method('findBlueprintForProduct')->willReturnCallback(
            static function (int $productTypeId, int $activityId = 1) use ($recipes): ?IndustryActivityProduct {
                $recipe = $recipes[$productTypeId] ?? null;
                if ($recipe === null || self::activityIdOf($recipe['activity']) !== $activityId) {
                    return null;
                }

                return (new IndustryActivityProduct())
                    ->setTypeId($productTypeId + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET)
                    ->setActivityId($activityId)
                    ->setProductTypeId($productTypeId)
                    ->setQuantity($recipe['outputPerRun']);
            },
        );

        $activityMaterialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $activityMaterialRepository->method('findBy')->willReturnCallback(
            static function (array $criteria) use ($recipes): array {
                $recipe = $recipes[$criteria['typeId'] - self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET] ?? null;
                if ($recipe === null || self::activityIdOf($recipe['activity']) !== $criteria['activityId']) {
                    return [];
                }
                $materials = [];
                foreach ($recipe['materials'] as $materialTypeId => $baseQuantity) {
                    $materials[] = (new IndustryActivityMaterial())
                        ->setTypeId($criteria['typeId'])
                        ->setActivityId($criteria['activityId'])
                        ->setMaterialTypeId((int) $materialTypeId)
                        ->setQuantity($baseQuantity);
                }

                return $materials;
            },
        );

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(0);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $rigCategoryRepository = $this->createStub(IndustryRigCategoryRepository::class);
        $rigCategoryRepository->method('buildGroupCategoryMap')->willReturn([]);
        $structureConfigRepository = $this->createStub(IndustryStructureConfigRepository::class);
        $structureConfigRepository->method('findByUser')->willReturnCallback(fn (): array => $this->pilotStructures);

        $bonusService = new IndustryBonusService($rigCategoryRepository, $structureConfigRepository, $invTypeRepository, $activityProductRepository);
        $treeService = new IndustryTreeService($activityProductRepository, $activityMaterialRepository, $invTypeRepository, $entityManager, $bonusService);
        $calculationService = new IndustryCalculationService(
            $invTypeRepository,
            new TypeNameResolver($invTypeRepository),
            $bonusService,
            $structureConfigRepository,
            $this->createStub(IndustryUserSettingsRepository::class),
            $entityManager,
            $this->createStub(StaStationRepository::class),
            $this->createStub(CachedStructureRepository::class),
        );

        return new IndustryShoppingListBuilder($treeService, new IndustryBlacklistService($entityManager), $bonusService, $calculationService);
    }

    private static function projectOf(User $pilot, int $productTypeId, int $runs, int $meLevel): IndustryProject
    {
        return (new IndustryProject())
            ->setUser($pilot)
            ->setProductTypeId($productTypeId)
            ->setRuns($runs)
            ->setMeLevel($meLevel);
    }

    private static function addStep(IndustryProject $project, int $productTypeId, int $runs, int $depth, string $activityType, int $meLevel = 10): IndustryProjectStep
    {
        $step = (new IndustryProjectStep())
            ->setProductTypeId($productTypeId)
            ->setBlueprintTypeId($productTypeId + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET)
            ->setRuns($runs)
            ->setDepth($depth)
            ->setActivityType($activityType)
            ->setMeLevel($meLevel);
        $project->addStep($step);

        return $step;
    }

    private static function structure(User $pilot, string $structureType): IndustryStructureConfig
    {
        return (new IndustryStructureConfig())
            ->setUser($pilot)
            ->setName(ucfirst($structureType))
            ->setStructureType($structureType)
            ->setSecurityType('nullsec')
            ->setRigs([]);
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

    /** @return array<int, array{activity: string, outputPerRun: int, materials: array<int|string, int>}> */
    private static function goldenRecipes(): array
    {
        $recipes = [];
        foreach (self::sdeFixture()['recipes'] as $productTypeId => $recipe) {
            $recipes[(int) $productTypeId] = $recipe;
        }

        return $recipes;
    }

    /** @return array<int, string> */
    private static function goldenTypeNames(): array
    {
        $typeNames = [];
        foreach (self::sdeFixture()['typeNames'] as $typeId => $typeName) {
            $typeNames[(int) $typeId] = $typeName;
        }

        return $typeNames;
    }

    /** @return array{recipes: array<string, mixed>, typeNames: array<string, string>} */
    private static function sdeFixture(): array
    {
        return json_decode((string) file_get_contents(self::GOLDEN_DIR.'sde-blueprint-recipes.json'), true, flags: \JSON_THROW_ON_ERROR);
    }
}
