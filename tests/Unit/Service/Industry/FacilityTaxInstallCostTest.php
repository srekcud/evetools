<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\ESI\EsiClient;
use App\Service\Industry\EsiCostIndexService;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryShoppingListBuilder;
use App\Service\Industry\ProductionCostService;
use App\Service\JitaMarketService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(ProductionCostService::class)]
final class FacilityTaxInstallCostTest extends TestCase
{
    private const int JITA_SOLAR_SYSTEM_ID = 30_000_142;
    private const int PERIMETER_SOLAR_SYSTEM_ID = 30_000_144;
    private const float JITA_MANUFACTURING_COST_INDEX = 0.0625;
    private const float PERIMETER_MANUFACTURING_COST_INDEX = 0.125;

    private const int TRITANIUM_TYPE_ID = 34;
    private const float TRITANIUM_ADJUSTED_PRICE = 8.0;
    private const int PYERITE_TYPE_ID = 35;
    private const float PYERITE_ADJUSTED_PRICE = 12.0;

    private const int RIFTER_BLUEPRINT_TYPE_ID = 691;
    private const int RIFTER_TYPE_ID = 587;
    private const int RIFTER_BLUEPRINT_TRITANIUM_QUANTITY = 2500;
    private const int MERLIN_BLUEPRINT_TYPE_ID = 604;
    private const int MERLIN_TYPE_ID = 603;
    private const int MERLIN_BLUEPRINT_PYERITE_QUANTITY = 1000;

    private ArrayAdapter $costIndexCache;
    private ProductionCostService $service;

    protected function setUp(): void
    {
        $this->costIndexCache = new ArrayAdapter();
        $this->cache('esi_adjusted_price_' . self::TRITANIUM_TYPE_ID, self::TRITANIUM_ADJUSTED_PRICE);
        $this->cache('esi_adjusted_price_' . self::PYERITE_TYPE_ID, self::PYERITE_ADJUSTED_PRICE);
        $this->cache('esi_cost_index_' . self::JITA_SOLAR_SYSTEM_ID . '_manufacturing', self::JITA_MANUFACTURING_COST_INDEX);
        $this->cache('esi_cost_index_' . self::PERIMETER_SOLAR_SYSTEM_ID . '_manufacturing', self::PERIMETER_MANUFACTURING_COST_INDEX);

        $materialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $materialRepository->method('findMaterialsForBlueprints')->willReturnCallback(
            fn (array $blueprintTypeIds, array $activityIds): array => match (true) {
                $activityIds !== [IndustryActivityType::Manufacturing->value] => [],
                $blueprintTypeIds === [self::RIFTER_BLUEPRINT_TYPE_ID] => [self::RIFTER_BLUEPRINT_TYPE_ID => [
                    ['materialTypeId' => self::TRITANIUM_TYPE_ID, 'quantity' => self::RIFTER_BLUEPRINT_TRITANIUM_QUANTITY],
                ]],
                $blueprintTypeIds === [self::MERLIN_BLUEPRINT_TYPE_ID] => [self::MERLIN_BLUEPRINT_TYPE_ID => [
                    ['materialTypeId' => self::PYERITE_TYPE_ID, 'quantity' => self::MERLIN_BLUEPRINT_PYERITE_QUANTITY],
                ]],
                default => [],
            },
        );

        $this->service = new ProductionCostService(
            $this->createStub(JitaMarketService::class),
            new EsiCostIndexService($this->createStub(EsiClient::class), $this->costIndexCache, new NullLogger()),
            $this->createStub(IndustryShoppingListBuilder::class),
            $this->createStub(IndustryCalculationService::class),
            $this->createStub(IndustryUserSettingsRepository::class),
            $materialRepository,
            $this->createStub(IndustryActivityProductRepository::class),
            $this->createStub(MapSolarSystemRepository::class),
        );
    }

    public function testInstallCostInAStoredNpcStationWithoutFacilityTaxUsesTheGameFacilityTax(): void
    {
        $jitaStation = $this->structure('Jita IV - Moon 4', 'station', self::JITA_SOLAR_SYSTEM_ID, null);
        $project = $this->projectWithManufacturingStep(self::RIFTER_TYPE_ID, self::RIFTER_BLUEPRINT_TYPE_ID, 4, $jitaStation);

        $result = $this->service->estimateJobInstallCosts($project);

        self::assertSame(5012.5, $result['steps'][0]['installCost']);
        self::assertSame(5012.5, $result['total']);
    }

    public function testInstallCostInAPlayerStructureWithoutFacilityTaxReadsItAsZero(): void
    {
        $perimeterRaitaru = $this->structure('Perimeter Raitaru', 'raitaru', self::PERIMETER_SOLAR_SYSTEM_ID, null);
        $project = $this->projectWithManufacturingStep(self::MERLIN_TYPE_ID, self::MERLIN_BLUEPRINT_TYPE_ID, 5, $perimeterRaitaru);

        $result = $this->service->estimateJobInstallCosts($project);

        self::assertSame(7500.0, $result['steps'][0]['installCost']);
        self::assertSame(7500.0, $result['total']);
    }

    private function structure(string $name, string $structureType, int $solarSystemId, ?float $facilityTaxRate): IndustryStructureConfig
    {
        return (new IndustryStructureConfig())
            ->setUser(new User())
            ->setName($name)
            ->setSecurityType('highsec')
            ->setStructureType($structureType)
            ->setSolarSystemId($solarSystemId)
            ->setRigs([])
            ->setFacilityTaxRate($facilityTaxRate);
    }

    private function projectWithManufacturingStep(int $productTypeId, int $blueprintTypeId, int $runs, IndustryStructureConfig $structure): IndustryProject
    {
        $project = (new IndustryProject())
            ->setUser(new User())
            ->setProductTypeId($productTypeId)
            ->setRuns($runs);

        $project->addStep(
            (new IndustryProjectStep())
                ->setProductTypeId($productTypeId)
                ->setBlueprintTypeId($blueprintTypeId)
                ->setActivityType('manufacturing')
                ->setRuns($runs)
                ->setStructureConfig($structure),
        );

        return $project;
    }

    private function cache(string $key, float $value): void
    {
        $item = $this->costIndexCache->getItem($key);
        $item->set($value);
        $this->costIndexCache->save($item);
    }
}
