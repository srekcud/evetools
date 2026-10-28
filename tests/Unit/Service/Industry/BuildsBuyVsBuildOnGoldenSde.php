<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\User;
use App\Industry\Application\ProductionCostCalculator;
use App\Industry\Application\RigCategories;
use App\Industry\Application\UserProductionStructures;
use App\Industry\Infrastructure\Esi\EsiAdjustedPrices;
use App\Industry\Infrastructure\Esi\EsiSystemCostIndices;
use App\Industry\Infrastructure\Market\JitaMarketPrices;
use App\Industry\Infrastructure\Sde\SdeBlueprintCatalog;
use App\Industry\Infrastructure\Sde\SdeInventionCatalog;
use App\Service\ESI\EsiClient;
use App\Service\Industry\BuyVsBuildService;
use App\Service\Industry\EsiCostIndexService;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\Service\TypeNameResolver;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Uid\Uuid;

trait BuildsBuyVsBuildOnGoldenSde
{
    use StubsGoldenSdeRepositories;

    private const string GOLDEN_USER_ID = '01928c3e-7a00-7000-8000-0000000000b2';
    private const float FULL_JITA_COVERAGE = 1.0;

    private function buyVsBuildServiceOnGoldenSde(
        array $jitaPrices,
        array $structurePrices,
        array $adjustedPrices,
        array $costIndices,
        ?StructureMarketService $structureMarket = null,
        array $jitaCoverages = [],
    ): BuyVsBuildService {
        if ([] === $this->recipes) {
            $this->loadGoldenSde();
        }

        $productRepository = $this->sdeProductRepository();
        $materialRepository = $this->sdeMaterialRepository();
        $blueprintCatalog = new SdeBlueprintCatalog($productRepository, $this->sdeActivityTimeRepository(), $materialRepository, $this->sdeBlueprintRepository());
        $jitaMarketService = $this->jitaMarketServiceAt($jitaPrices, $jitaCoverages);
        $esiCostIndexService = $this->esiCostIndexServiceSyncedWith($adjustedPrices, $costIndices);

        return new BuyVsBuildService(
            new ProductionCostCalculator(
                $blueprintCatalog,
                new SdeInventionCatalog($productRepository, $materialRepository),
                new JitaMarketPrices($jitaMarketService),
                new EsiAdjustedPrices($esiCostIndexService),
                new EsiSystemCostIndices($esiCostIndexService),
                $this->rigCategoriesWithoutAny(),
            ),
            $blueprintCatalog,
            $this->userProductionStructuresWithoutAny(),
            $jitaMarketService,
            $structureMarket ?? $this->structureMarketAt($structurePrices),
            new TypeNameResolver($this->sdeInvTypeRepository()),
        );
    }

    private function goldenUser(): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, Uuid::fromString(self::GOLDEN_USER_ID));

        return $user;
    }

    private static function marketPriceMissing(int $typeId): array
    {
        return ['reason' => 'market_price', 'typeId' => $typeId];
    }

    private static function adjustedPriceMissing(int $typeId): array
    {
        return ['reason' => 'adjusted_price', 'typeId' => $typeId];
    }

    private static function costIndexMissing(): array
    {
        return ['reason' => 'cost_index', 'typeId' => null];
    }

    private static function inventionProbabilityMissing(int $t1BlueprintTypeId): array
    {
        return ['reason' => 'invention_probability', 'typeId' => $t1BlueprintTypeId];
    }

    private function jitaMarketServiceAt(array $jitaPrices, array $jitaCoverages = []): JitaMarketService
    {
        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getWeightedSellPricesWithFallback')->willReturnCallback(
            static function (array $typeQuantities) use ($jitaPrices, $jitaCoverages): array {
                $prices = [];
                foreach ($typeQuantities as $typeId => $quantity) {
                    $prices[$typeId] = \array_key_exists($typeId, $jitaPrices)
                        ? ['weightedPrice' => $jitaPrices[$typeId], 'coverage' => self::jitaCoverageOf($jitaCoverages, $typeId, $quantity), 'ordersUsed' => 1]
                        : null;
                }

                return $prices;
            },
        );
        $jitaMarketService->method('getPricesWithFallback')->willReturnCallback(
            static function (array $typeIds) use ($jitaPrices): array {
                $prices = [];
                foreach ($typeIds as $typeId) {
                    $prices[$typeId] = $jitaPrices[$typeId] ?? null;
                }

                return $prices;
            },
        );

        return $jitaMarketService;
    }

    private static function jitaCoverageOf(array $jitaCoverages, int $typeId, int $quantity): float
    {
        if (!\array_key_exists($typeId, $jitaCoverages)) {
            return self::FULL_JITA_COVERAGE;
        }

        return \is_array($jitaCoverages[$typeId]) ? $jitaCoverages[$typeId][$quantity] : $jitaCoverages[$typeId];
    }

    private function structureMarketAt(array $structurePrices): StructureMarketService
    {
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

        return $structureMarketService;
    }

    private function rigCategoriesWithoutAny(): RigCategories
    {
        $rigCategories = $this->createStub(RigCategories::class);
        $rigCategories->method('categoryOf')->willReturn(null);

        return $rigCategories;
    }

    private function userProductionStructuresWithoutAny(): UserProductionStructures
    {
        $productionStructures = $this->createStub(UserProductionStructures::class);
        $productionStructures->method('configuredBy')->willReturn([]);

        return $productionStructures;
    }

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
}
