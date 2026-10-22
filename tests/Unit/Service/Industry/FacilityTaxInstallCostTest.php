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
use App\Industry\Domain\SecurityClass;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use App\Service\ESI\EsiClient;
use App\Service\Industry\EsiCostIndexService;
use App\Service\Industry\FacilityTaxRateResolver;
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
    use CreatesUserWithId;

    private const int JITA_SOLAR_SYSTEM_ID = 30_000_142;
    private const int PERIMETER_SOLAR_SYSTEM_ID = 30_000_144;
    private const int SYSTEM_WITHOUT_COST_INDEX_SOLAR_SYSTEM_ID = 30_000_999;
    private const float JITA_MANUFACTURING_COST_INDEX = 0.0625;
    private const float PERIMETER_MANUFACTURING_COST_INDEX = 0.125;

    private const int TRITANIUM_TYPE_ID = 34;
    private const float TRITANIUM_ADJUSTED_PRICE = 8.0;
    private const int PYERITE_TYPE_ID = 35;
    private const float PYERITE_ADJUSTED_PRICE = 12.0;
    private const int MEXALLON_TYPE_ID = 36;
    private const string RIFTER_USER_UUID = '5d0c1a52-8d0e-4f43-9a52-0c8f2a1f7b10';
    private const string USER_RAITARU_ID = 'user-raitaru-in-jita';
    private const float USER_RAITARU_FACILITY_TAX_RATE = 0.05;

    private const int RIFTER_BLUEPRINT_TYPE_ID = 691;
    private const int RIFTER_TYPE_ID = 587;
    private const int RIFTER_BLUEPRINT_TRITANIUM_QUANTITY = 2500;
    private const int SLASHER_BLUEPRINT_TYPE_ID = 11_585;
    private const int SLASHER_TYPE_ID = 585;
    private const int SLASHER_BLUEPRINT_MEXALLON_QUANTITY = 300;
    private const int MERLIN_BLUEPRINT_TYPE_ID = 604;
    private const int MERLIN_TYPE_ID = 603;
    private const int MERLIN_BLUEPRINT_PYERITE_QUANTITY = 1000;

    private ArrayAdapter $costIndexCache;
    private ProductionCostService $service;

    protected function setUp(): void
    {
        $this->service = $this->serviceWithConfiguredStructures([]);
    }

    private function serviceWithConfiguredStructures(array $structuresByUserId): ProductionCostService
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
                $blueprintTypeIds === [self::SLASHER_BLUEPRINT_TYPE_ID] => [self::SLASHER_BLUEPRINT_TYPE_ID => [
                    ['materialTypeId' => self::TRITANIUM_TYPE_ID, 'quantity' => self::RIFTER_BLUEPRINT_TRITANIUM_QUANTITY],
                    ['materialTypeId' => self::MEXALLON_TYPE_ID, 'quantity' => self::SLASHER_BLUEPRINT_MEXALLON_QUANTITY],
                ]],
                $blueprintTypeIds === [self::MERLIN_BLUEPRINT_TYPE_ID] => [self::MERLIN_BLUEPRINT_TYPE_ID => [
                    ['materialTypeId' => self::PYERITE_TYPE_ID, 'quantity' => self::MERLIN_BLUEPRINT_PYERITE_QUANTITY],
                ]],
                default => [],
            },
        );

        return new ProductionCostService(
            $this->createStub(JitaMarketService::class),
            new EsiCostIndexService($this->createStub(EsiClient::class), $this->costIndexCache, new NullLogger()),
            $this->createStub(IndustryShoppingListBuilder::class),
            $this->createStub(IndustryCalculationService::class),
            $this->createStub(IndustryUserSettingsRepository::class),
            $materialRepository,
            $this->createStub(IndustryActivityProductRepository::class),
            $this->createStub(MapSolarSystemRepository::class),
            new FacilityTaxRateResolver(new InMemoryUserProductionStructures($structuresByUserId)),
        );
    }

    public function testInstallCostInAStoredNpcStationWithoutFacilityTaxAddsTheGameFacilityTaxAndTheSurchargeToTheCostIndex(): void
    {
        $jitaStation = $this->structure('Jita IV - Moon 4', 'station', self::JITA_SOLAR_SYSTEM_ID, null);
        $project = $this->projectWithManufacturingStep(self::RIFTER_TYPE_ID, self::RIFTER_BLUEPRINT_TYPE_ID, 4, $jitaStation);

        $result = $this->service->estimateJobInstallCosts($project);

        self::assertSame(8400.0, $result['steps'][0]['installCost']);
        self::assertSame(8400.0, $result['total']);
    }

    public function testInstallCostInAPlayerStructureWithoutFacilityTaxAddsOnlyTheCostIndexAndTheSurcharge(): void
    {
        $perimeterRaitaru = $this->structure('Perimeter Raitaru', 'raitaru', self::PERIMETER_SOLAR_SYSTEM_ID, null);
        $project = $this->projectWithManufacturingStep(self::MERLIN_TYPE_ID, self::MERLIN_BLUEPRINT_TYPE_ID, 5, $perimeterRaitaru);

        $result = $this->service->estimateJobInstallCosts($project);

        self::assertSame(9900.0, $result['steps'][0]['installCost']);
        self::assertSame(9900.0, $result['total']);
    }

    public function testInstallCostOfAStepWithoutStructureForAUserWithoutConfiguredStructureUsesTheNpcFacilityTax(): void
    {
        $project = $this->projectWithManufacturingStep(self::RIFTER_TYPE_ID, self::RIFTER_BLUEPRINT_TYPE_ID, 4, null, $this->userWithId(self::RIFTER_USER_UUID));

        $result = $this->service->estimateJobInstallCosts($project);

        self::assertSame(8400.0, $result['steps'][0]['installCost']);
        self::assertSame(8400.0, $result['total']);
    }

    public function testInstallCostOfAStepWithoutStructureUsesTheFacilityTaxOfTheConfiguredStructureOfTheUserInTheSystem(): void
    {
        $service = $this->serviceWithConfiguredStructures([
            self::RIFTER_USER_UUID => [
                ProductionStructures::raitaruWithFacilityTax(self::USER_RAITARU_ID, SecurityClass::HighSec, self::JITA_SOLAR_SYSTEM_ID, self::USER_RAITARU_FACILITY_TAX_RATE),
            ],
        ]);
        $project = $this->projectWithManufacturingStep(self::RIFTER_TYPE_ID, self::RIFTER_BLUEPRINT_TYPE_ID, 4, null, $this->userWithId(self::RIFTER_USER_UUID));

        $result = $service->estimateJobInstallCosts($project);

        self::assertEqualsWithDelta(12200.0, $result['steps'][0]['installCost'], 0.001);
        self::assertEqualsWithDelta(12200.0, $result['total'], 0.001);
    }

    public function testInstallCostOfAStepInASystemWithoutCostIndexIsUnknown(): void
    {
        $unindexedStation = $this->structure('Unindexed Station', 'station', self::SYSTEM_WITHOUT_COST_INDEX_SOLAR_SYSTEM_ID, null);
        $project = $this->projectWithManufacturingStep(self::RIFTER_TYPE_ID, self::RIFTER_BLUEPRINT_TYPE_ID, 4, $unindexedStation);

        $result = $this->service->estimateJobInstallCosts($project);

        self::assertNull($result['steps'][0]['installCost']);
        self::assertNull($result['steps'][0]['costIndex']);
        self::assertSame('missing_cost_index', $result['steps'][0]['unknownReason']);
        self::assertNull($result['total']);
    }

    public function testInstallCostOfAStepWithAMaterialWithoutAdjustedPriceIsUnknownAndListsTheType(): void
    {
        $jitaStation = $this->structure('Jita IV - Moon 4', 'station', self::JITA_SOLAR_SYSTEM_ID, null);
        $project = $this->projectWithManufacturingStep(self::SLASHER_TYPE_ID, self::SLASHER_BLUEPRINT_TYPE_ID, 2, $jitaStation);

        $result = $this->service->estimateJobInstallCosts($project);

        self::assertNull($result['steps'][0]['installCost']);
        self::assertSame(self::JITA_MANUFACTURING_COST_INDEX, $result['steps'][0]['costIndex']);
        self::assertSame('missing_adjusted_price', $result['steps'][0]['unknownReason']);
        self::assertSame([self::MEXALLON_TYPE_ID], $result['steps'][0]['missingAdjustedPriceTypeIds']);
        self::assertNull($result['total']);
    }

    public function testTotalInstallCostIsUnknownButTheKnownStepKeepsItsAmountWhenAnotherStepIsUnknown(): void
    {
        $jitaStation = $this->structure('Jita IV - Moon 4', 'station', self::JITA_SOLAR_SYSTEM_ID, null);
        $unindexedStation = $this->structure('Unindexed Station', 'station', self::SYSTEM_WITHOUT_COST_INDEX_SOLAR_SYSTEM_ID, null);
        $project = $this->projectWithManufacturingStep(self::RIFTER_TYPE_ID, self::RIFTER_BLUEPRINT_TYPE_ID, 4, $jitaStation);
        $project->addStep(
            (new IndustryProjectStep())
                ->setProductTypeId(self::MERLIN_TYPE_ID)
                ->setBlueprintTypeId(self::MERLIN_BLUEPRINT_TYPE_ID)
                ->setActivityType('manufacturing')
                ->setRuns(5)
                ->setStructureConfig($unindexedStation),
        );

        $result = $this->service->estimateJobInstallCosts($project);

        self::assertCount(2, $result['steps']);
        self::assertSame(8400.0, $result['steps'][0]['installCost']);
        self::assertNull($result['steps'][0]['unknownReason']);
        self::assertSame([], $result['steps'][0]['missingAdjustedPriceTypeIds']);
        self::assertNull($result['steps'][1]['installCost']);
        self::assertSame('missing_cost_index', $result['steps'][1]['unknownReason']);
        self::assertNull($result['total']);
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

    private function projectWithManufacturingStep(int $productTypeId, int $blueprintTypeId, int $runs, ?IndustryStructureConfig $structure, ?User $owner = null): IndustryProject
    {
        $project = (new IndustryProject())
            ->setUser($owner ?? new User())
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
