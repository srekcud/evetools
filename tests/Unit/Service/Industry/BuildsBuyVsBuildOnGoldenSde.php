<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Enum\IndustryActivityType;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ESI\EsiClient;
use App\Service\Industry\BuyVsBuildService;
use App\Service\Industry\EsiCostIndexService;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryTreeService;
use App\Service\Industry\InventionService;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\Service\TypeNameResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Wires the real BuyVsBuildService, IndustryTreeService, IndustryBonusService and EsiCostIndexService
 * on the SDE extract of the goldens (tests/Fixtures/Golden/sde-blueprint-recipes.json).
 * Stubbed boundaries only: SDE repositories, Jita and structure markets, ESI (adjusted prices, cost indices).
 *
 * The extract has no blueprint typeId: each recipe gets a synthetic one (product typeId + offset).
 */
trait BuildsBuyVsBuildOnGoldenSde
{
    private const int SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET = 900_000_000;
    private const string SDE_RECIPES_FIXTURE = __DIR__.'/../../../Fixtures/Golden/sde-blueprint-recipes.json';
    private const int UNCATEGORIZED_GROUP_ID = 999_999_001;

    /**
     * @param array<int, float>              $jitaPrices      typeId => Jita sell price, an absent typeId has no price
     * @param array<int, array<int, float>>  $structurePrices structureId => (typeId => lowest sell price)
     * @param array<int, float>              $adjustedPrices  typeId => ESI adjusted price
     * @param array<int, array<string, float>> $costIndices   solarSystemId => (activity => cost index)
     */
    private function buyVsBuildServiceOnGoldenSde(
        array $jitaPrices,
        array $structurePrices,
        array $adjustedPrices,
        array $costIndices,
        bool $isT2 = false,
    ): BuyVsBuildService {
        $recipes = self::goldenRecipes();
        $typeNames = self::goldenTypeNames();

        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturnCallback(
            static fn (int $typeId): ?InvType => isset($typeNames[$typeId]) ? self::invType($typeId, $typeNames[$typeId]) : null,
        );

        $activityProductRepository = $this->createStub(IndustryActivityProductRepository::class);
        $activityProductRepository->method('findBlueprintForProduct')->willReturnCallback(
            static function (int $productTypeId, int $activityId = 1) use ($recipes): ?IndustryActivityProduct {
                $recipe = $recipes[$productTypeId] ?? null;
                if ($recipe === null || self::activityIdOf($recipe['activity']) !== $activityId) {
                    return null;
                }

                return (new IndustryActivityProduct())
                    ->setTypeId(self::blueprintTypeIdOf($productTypeId))
                    ->setActivityId($activityId)
                    ->setProductTypeId($productTypeId)
                    ->setQuantity($recipe['outputPerRun']);
            },
        );

        $activityMaterialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $activityMaterialRepository->method('findBy')->willReturnCallback(
            static function (array $criteria) use ($recipes): array {
                $materials = [];
                foreach (self::baseMaterialsOf($recipes, $criteria['typeId'], $criteria['activityId']) as $materialTypeId => $baseQuantity) {
                    $materials[] = (new IndustryActivityMaterial())
                        ->setTypeId($criteria['typeId'])
                        ->setActivityId($criteria['activityId'])
                        ->setMaterialTypeId($materialTypeId)
                        ->setQuantity($baseQuantity);
                }

                return $materials;
            },
        );
        $activityMaterialRepository->method('findMaterialsForBlueprints')->willReturnCallback(
            static function (array $blueprintTypeIds, array $activityIds) use ($recipes): array {
                $materialsByBlueprint = [];
                foreach ($blueprintTypeIds as $blueprintTypeId) {
                    foreach ($activityIds as $activityId) {
                        foreach (self::baseMaterialsOf($recipes, $blueprintTypeId, $activityId) as $materialTypeId => $baseQuantity) {
                            $materialsByBlueprint[$blueprintTypeId][] = ['materialTypeId' => $materialTypeId, 'quantity' => $baseQuantity];
                        }
                    }
                }

                return $materialsByBlueprint;
            },
        );

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(0);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $rigCategoryRepository = $this->createStub(IndustryRigCategoryRepository::class);
        $rigCategoryRepository->method('buildGroupCategoryMap')->willReturn([]);
        $structureConfigRepository = $this->createStub(IndustryStructureConfigRepository::class);
        $structureConfigRepository->method('findByUser')->willReturn([]);
        $bonusService = new IndustryBonusService($rigCategoryRepository, $structureConfigRepository, $invTypeRepository, $activityProductRepository);

        $treeService = new IndustryTreeService($activityProductRepository, $activityMaterialRepository, $invTypeRepository, $entityManager, $bonusService);

        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPricesWithFallback')->willReturnCallback(
            static function (array $typeIds) use ($jitaPrices): array {
                $prices = [];
                foreach ($typeIds as $typeId) {
                    $prices[$typeId] = $jitaPrices[$typeId] ?? null;
                }

                return $prices;
            },
        );

        $structureMarketService = $this->createStub(StructureMarketService::class);
        $structureMarketService->method('getLowestSellPrice')->willReturnCallback(
            static fn (int $structureId, int $typeId): ?float => $structurePrices[$structureId][$typeId] ?? null,
        );
        $structureMarketService->method('getLowestSellPrices')->willReturnCallback(
            static function (int $structureId, array $typeIds) use ($structurePrices): array {
                $prices = [];
                foreach ($typeIds as $typeId) {
                    $prices[$typeId] = $structurePrices[$structureId][$typeId] ?? null;
                }

                return $prices;
            },
        );

        $inventionService = $this->createStub(InventionService::class);
        $inventionService->method('isT2')->willReturn($isT2);

        return new BuyVsBuildService(
            $treeService,
            $jitaMarketService,
            $structureMarketService,
            $this->esiCostIndexServiceSyncedWith($adjustedPrices, $costIndices),
            $inventionService,
            new TypeNameResolver($invTypeRepository),
            $this->createStub(IndustryBlacklistService::class),
            $activityMaterialRepository,
        );
    }

    /**
     * @param array<int, float>                $adjustedPrices
     * @param array<int, array<string, float>> $costIndices
     */
    private function esiCostIndexServiceSyncedWith(array $adjustedPrices, array $costIndices): EsiCostIndexService
    {
        $esiAdjustedPrices = [];
        foreach ($adjustedPrices as $typeId => $adjustedPrice) {
            $esiAdjustedPrices[] = ['type_id' => $typeId, 'adjusted_price' => $adjustedPrice];
        }
        $esiSystems = [];
        foreach ($costIndices as $solarSystemId => $indexByActivity) {
            $esiIndices = [];
            foreach ($indexByActivity as $activity => $costIndex) {
                $esiIndices[] = ['activity' => $activity, 'cost_index' => $costIndex];
            }
            $esiSystems[] = ['solar_system_id' => $solarSystemId, 'cost_indices' => $esiIndices];
        }

        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('get')->willReturnCallback(static fn (string $endpoint): array => match ($endpoint) {
            '/markets/prices/' => $esiAdjustedPrices,
            '/industry/systems/' => $esiSystems,
        });

        $esiCostIndexService = new EsiCostIndexService($esiClient, new ArrayAdapter(), new NullLogger());
        $esiCostIndexService->syncAdjustedPrices();
        $esiCostIndexService->syncCostIndices();

        return $esiCostIndexService;
    }

    private static function blueprintTypeIdOf(int $productTypeId): int
    {
        return $productTypeId + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET;
    }

    private static function activityIdOf(string $activity): int
    {
        return $activity === 'reaction' ? IndustryActivityType::Reaction->value : IndustryActivityType::Manufacturing->value;
    }

    /**
     * @param array<int, array{activity: string, outputPerRun: int, materials: array<int|string, int>}> $recipes
     *
     * @return array<int, int> materialTypeId => base quantity per run
     */
    private static function baseMaterialsOf(array $recipes, int $blueprintTypeId, int $activityId): array
    {
        $recipe = $recipes[$blueprintTypeId - self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET] ?? null;
        if ($recipe === null || self::activityIdOf($recipe['activity']) !== $activityId) {
            return [];
        }

        $materials = [];
        foreach ($recipe['materials'] as $materialTypeId => $baseQuantity) {
            $materials[(int) $materialTypeId] = $baseQuantity;
        }

        return $materials;
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
        return json_decode((string) file_get_contents(self::SDE_RECIPES_FIXTURE), true, flags: JSON_THROW_ON_ERROR);
    }
}
