<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\Sde\InvMarketGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\PlanetSchematic;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\PlanetSchematicRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\JitaMarketService;
use App\Service\Planetary\PlanetaryProductionCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryProductionCalculator::class)]
class PlanetaryProductionCalculatorTest extends TestCase
{
    private const SECONDS_PER_DAY = 86400;

    private PlanetSchematicRepository $schematicRepository;
    private PlanetSchematicTypeRepository $schematicTypeRepository;
    private InvTypeRepository $invTypeRepository;
    private JitaMarketService $jitaMarketService;
    private PlanetaryProductionCalculator $calculator;

    protected function setUp(): void
    {
        $this->schematicRepository = $this->createStub(PlanetSchematicRepository::class);
        $this->schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $this->invTypeRepository = $this->createStub(InvTypeRepository::class);
        $this->jitaMarketService = $this->createStub(JitaMarketService::class);

        $this->calculator = new PlanetaryProductionCalculator(
            $this->schematicRepository,
            $this->schematicTypeRepository,
            $this->invTypeRepository,
            $this->jitaMarketService,
        );
    }

    // ===========================================
    // Empty / edge cases
    // ===========================================

    public function testCalculateProductionWithNoColonies(): void
    {
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn([]);

        $result = $this->calculator->calculateProduction([]);

        $this->assertSame([], $result['tiers']);
        $this->assertSame(0.0, $result['totalDailyIsk']);
        $this->assertSame(0.0, $result['totalMonthlyIsk']);
    }

    public function testCalculateTotalDailyIskWithNoColonies(): void
    {
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn([]);

        $result = $this->calculator->calculateTotalDailyIsk([]);

        $this->assertSame(0.0, $result);
    }

    public function testCalculateProductionWithColonyButNoPins(): void
    {
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn([]);

        $colony = new PlanetaryColony();

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertSame([], $result['tiers']);
        $this->assertSame(0.0, $result['totalDailyIsk']);
        $this->assertSame(0.0, $result['totalMonthlyIsk']);
    }

    // ===========================================
    // Extractor output calculation
    // ===========================================

    public function testActiveExtractorProducesDailyOutput(): void
    {
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn([]);
        $this->invTypeRepository->method('find')->willReturn(null);
        $this->jitaMarketService->method('getPrices')->willReturn([2073 => 5.0]);

        $colony = new PlanetaryColony();
        $pin = $this->createExtractorPin(
            productTypeId: 2073,
            cycleTime: 900,       // 15 minute cycles
            qtyPerCycle: 1000,
            expiryTime: new \DateTimeImmutable('+2 days'),
        );
        $colony->addPin($pin);

        $result = $this->calculator->calculateProduction([$colony]);

        // 1000 qty * (86400 / 900 cycles/day) = 96000 units/day
        $expectedDailyQty = 1000.0 * (self::SECONDS_PER_DAY / 900);
        $this->assertEqualsWithDelta(96000.0, $expectedDailyQty, 0.01);

        $this->assertNotEmpty($result['tiers']);
        // Total daily ISK = 96000 * 5.0 = 480000.0
        $this->assertSame(480000.0, $result['totalDailyIsk']);
        // Total monthly ISK = 480000.0 * 30
        $this->assertSame(14400000.0, $result['totalMonthlyIsk']);
    }

    public function testExpiredExtractorIsExcluded(): void
    {
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn([]);
        $this->jitaMarketService->method('getPrices')->willReturn([]);

        $colony = new PlanetaryColony();
        $pin = $this->createExtractorPin(
            productTypeId: 2073,
            cycleTime: 900,
            qtyPerCycle: 1000,
            expiryTime: new \DateTimeImmutable('-1 hour'),
        );
        $colony->addPin($pin);

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertSame([], $result['tiers']);
        $this->assertSame(0.0, $result['totalDailyIsk']);
    }

    public function testExtractorWithNullExpiryIsIncluded(): void
    {
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn([]);
        $this->invTypeRepository->method('find')->willReturn(null);
        $this->jitaMarketService->method('getPrices')->willReturn([2073 => 10.0]);

        $colony = new PlanetaryColony();
        $pin = $this->createExtractorPin(
            productTypeId: 2073,
            cycleTime: 1800,
            qtyPerCycle: 500,
            expiryTime: null,
        );
        $colony->addPin($pin);

        $result = $this->calculator->calculateProduction([$colony]);

        // 500 * (86400/1800) = 24000 units/day, ISK = 24000 * 10 = 240000
        $this->assertSame(240000.0, $result['totalDailyIsk']);
    }

    public function testExtractorWithZeroCycleTimeIsExcluded(): void
    {
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn([]);

        $colony = new PlanetaryColony();
        $pin = $this->createExtractorPin(
            productTypeId: 2073,
            cycleTime: 0,
            qtyPerCycle: 500,
            expiryTime: new \DateTimeImmutable('+1 day'),
        );
        $colony->addPin($pin);

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertSame([], $result['tiers']);
        $this->assertSame(0.0, $result['totalDailyIsk']);
    }

    public function testExtractorWithNullProductTypeIsExcluded(): void
    {
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn([]);

        $colony = new PlanetaryColony();
        $pin = $this->createExtractorPin(
            productTypeId: null,
            cycleTime: 900,
            qtyPerCycle: 500,
            expiryTime: new \DateTimeImmutable('+1 day'),
        );
        $colony->addPin($pin);

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertSame([], $result['tiers']);
    }

    // ===========================================
    // Factory output calculation
    // ===========================================

    public function testFactoryProducesDailyOutput(): void
    {
        // Schematic 121 : cycle 1800s, outputs 20 units of type 2389
        $schematicMap = [
            121 => [
                'inputs' => [2073 => 3000],
                'output' => ['typeId' => 2389, 'quantity' => 20],
            ],
        ];
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn($schematicMap);

        $schematic = new PlanetSchematic();
        $schematic->setSchematicId(121);
        $schematic->setSchematicName('Water');
        $schematic->setCycleTime(1800);
        $this->schematicRepository->method('findBySchematicId')->willReturn($schematic);

        $this->invTypeRepository->method('find')->willReturn(null);
        $this->jitaMarketService->method('getPrices')->willReturn([2389 => 400.0]);

        $colony = new PlanetaryColony();
        $pin = $this->createFactoryPin(schematicId: 121);
        $colony->addPin($pin);

        $result = $this->calculator->calculateProduction([$colony]);

        // 20 * (86400/1800) = 960 units/day
        // ISK = 960 * 400 = 384000
        $this->assertSame(384000.0, $result['totalDailyIsk']);
    }

    public function testFactoryWithUnknownSchematicIsExcluded(): void
    {
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn([]);

        $colony = new PlanetaryColony();
        $pin = $this->createFactoryPin(schematicId: 999);
        $colony->addPin($pin);

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertSame([], $result['tiers']);
    }

    public function testFactoryWithNullSchematicIsExcluded(): void
    {
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn([]);

        $colony = new PlanetaryColony();
        // Pin with no schematic and no extractor = neither factory nor extractor
        $pin = new PlanetaryPin();
        $pin->setPinId(1);
        $pin->setTypeId(3000);
        $colony->addPin($pin);

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertSame([], $result['tiers']);
    }

    // ===========================================
    // Aggregation across colonies
    // ===========================================

    public function testMultipleExtractorsOfSameTypeSumUp(): void
    {
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn([]);
        $this->invTypeRepository->method('find')->willReturn(null);
        $this->jitaMarketService->method('getPrices')->willReturn([2073 => 1.0]);

        $colony1 = new PlanetaryColony();
        $colony1->addPin($this->createExtractorPin(
            productTypeId: 2073,
            cycleTime: 1800,
            qtyPerCycle: 100,
            expiryTime: new \DateTimeImmutable('+1 day'),
        ));

        $colony2 = new PlanetaryColony();
        $colony2->addPin($this->createExtractorPin(
            productTypeId: 2073,
            cycleTime: 1800,
            qtyPerCycle: 200,
            expiryTime: new \DateTimeImmutable('+1 day'),
        ));

        $result = $this->calculator->calculateProduction([$colony1, $colony2]);

        // colony1: 100 * (86400/1800) = 4800
        // colony2: 200 * (86400/1800) = 9600
        // total = 14400 * 1.0 ISK = 14400
        $this->assertSame(14400.0, $result['totalDailyIsk']);
    }

    // ===========================================
    // Tier classification
    // ===========================================

    public function testTierClassificationP1(): void
    {
        $marketGroup = new InvMarketGroup();
        $marketGroup->setMarketGroupId(1334); // P1

        $type = new InvType();
        $type->setTypeId(2389);
        $type->setTypeName('Water');
        $type->setMarketGroup($marketGroup);

        $this->setupFactoryScenario(type: $type, schematicId: 121, outputTypeId: 2389, outputQty: 20, cycleTime: 1800, price: 400.0);

        $colony = new PlanetaryColony();
        $colony->addPin($this->createFactoryPin(schematicId: 121));

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertNotEmpty($result['tiers']);
        $this->assertSame('P1', $result['tiers'][0]['tier']);
        $this->assertSame('Basic Commodities', $result['tiers'][0]['label']);
    }

    public function testTierClassificationP2(): void
    {
        $marketGroup = new InvMarketGroup();
        $marketGroup->setMarketGroupId(1335); // P2

        $type = new InvType();
        $type->setTypeId(9840);
        $type->setTypeName('Coolant');
        $type->setMarketGroup($marketGroup);

        $this->setupFactoryScenario(type: $type, schematicId: 130, outputTypeId: 9840, outputQty: 5, cycleTime: 3600, price: 8000.0);

        $colony = new PlanetaryColony();
        $colony->addPin($this->createFactoryPin(schematicId: 130));

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertNotEmpty($result['tiers']);
        $this->assertSame('P2', $result['tiers'][0]['tier']);
        $this->assertSame('Refined Commodities', $result['tiers'][0]['label']);
    }

    public function testTierClassificationP3(): void
    {
        $marketGroup = new InvMarketGroup();
        $marketGroup->setMarketGroupId(1336); // P3

        $type = new InvType();
        $type->setTypeId(17392);
        $type->setTypeName('Condensates');
        $type->setMarketGroup($marketGroup);

        $this->setupFactoryScenario(type: $type, schematicId: 140, outputTypeId: 17392, outputQty: 3, cycleTime: 3600, price: 50000.0);

        $colony = new PlanetaryColony();
        $colony->addPin($this->createFactoryPin(schematicId: 140));

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertNotEmpty($result['tiers']);
        $this->assertSame('P3', $result['tiers'][0]['tier']);
        $this->assertSame('Specialized Commodities', $result['tiers'][0]['label']);
    }

    public function testTierClassificationP4(): void
    {
        $marketGroup = new InvMarketGroup();
        $marketGroup->setMarketGroupId(1337); // P4

        $type = new InvType();
        $type->setTypeId(2867);
        $type->setTypeName('Broadcast Node');
        $type->setMarketGroup($marketGroup);

        $this->setupFactoryScenario(type: $type, schematicId: 150, outputTypeId: 2867, outputQty: 1, cycleTime: 21600, price: 900000.0);

        $colony = new PlanetaryColony();
        $colony->addPin($this->createFactoryPin(schematicId: 150));

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertNotEmpty($result['tiers']);
        $this->assertSame('P4', $result['tiers'][0]['tier']);
        $this->assertSame('Advanced Commodities', $result['tiers'][0]['label']);
    }

    public function testTierClassificationP0WhenNoMarketGroup(): void
    {
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn([]);
        $this->invTypeRepository->method('find')->willReturn(null);
        $this->jitaMarketService->method('getPrices')->willReturn([2073 => 2.0]);

        $colony = new PlanetaryColony();
        $colony->addPin($this->createExtractorPin(
            productTypeId: 2073,
            cycleTime: 1800,
            qtyPerCycle: 100,
            expiryTime: new \DateTimeImmutable('+1 day'),
        ));

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertNotEmpty($result['tiers']);
        $this->assertSame('P0', $result['tiers'][0]['tier']);
        $this->assertSame('Raw Materials', $result['tiers'][0]['label']);
    }

    public function testTierClassificationWithNestedMarketGroup(): void
    {
        // Create a parent group that is P2 and a child group under it
        $parentGroup = new InvMarketGroup();
        $parentGroup->setMarketGroupId(1335); // P2

        $childGroup = new InvMarketGroup();
        $childGroup->setMarketGroupId(99999);
        $childGroup->setMarketGroupName('Sub-P2');
        $childGroup->setParentGroup($parentGroup);

        $type = new InvType();
        $type->setTypeId(9840);
        $type->setTypeName('Coolant');
        $type->setMarketGroup($childGroup);

        $this->setupFactoryScenario(type: $type, schematicId: 130, outputTypeId: 9840, outputQty: 5, cycleTime: 3600, price: 8000.0);

        $colony = new PlanetaryColony();
        $colony->addPin($this->createFactoryPin(schematicId: 130));

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertNotEmpty($result['tiers']);
        $this->assertSame('P2', $result['tiers'][0]['tier']);
    }

    // ===========================================
    // ISK Valuation
    // ===========================================

    public function testNullPriceResultsInZeroIsk(): void
    {
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn([]);
        $this->invTypeRepository->method('find')->willReturn(null);
        $this->jitaMarketService->method('getPrices')->willReturn([2073 => null]);

        $colony = new PlanetaryColony();
        $colony->addPin($this->createExtractorPin(
            productTypeId: 2073,
            cycleTime: 1800,
            qtyPerCycle: 100,
            expiryTime: new \DateTimeImmutable('+1 day'),
        ));

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertSame(0.0, $result['totalDailyIsk']);
        $this->assertSame(0.0, $result['totalMonthlyIsk']);
    }

    public function testMonthlyIskIs30TimesDailyIsk(): void
    {
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn([]);
        $this->invTypeRepository->method('find')->willReturn(null);
        $this->jitaMarketService->method('getPrices')->willReturn([2073 => 10.0]);

        $colony = new PlanetaryColony();
        $colony->addPin($this->createExtractorPin(
            productTypeId: 2073,
            cycleTime: 1800,
            qtyPerCycle: 100,
            expiryTime: new \DateTimeImmutable('+1 day'),
        ));

        $result = $this->calculator->calculateProduction([$colony]);

        // 100 * (86400/1800) = 4800/day * 10 ISK = 48000
        $this->assertSame(48000.0, $result['totalDailyIsk']);
        $this->assertSame(48000.0 * 30, $result['totalMonthlyIsk']);
    }

    // ===========================================
    // Items within tiers
    // ===========================================

    public function testTierItemsAreSortedByDailyIskDescending(): void
    {
        // Two different P0 extractors with different values
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn([]);
        $this->invTypeRepository->method('find')->willReturn(null);
        $this->jitaMarketService->method('getPrices')->willReturn([
            2073 => 100.0,   // Higher ISK
            2074 => 1.0,     // Lower ISK
        ]);

        $colony = new PlanetaryColony();
        $colony->addPin($this->createExtractorPin(
            productTypeId: 2074,
            cycleTime: 1800,
            qtyPerCycle: 100,
            expiryTime: new \DateTimeImmutable('+1 day'),
        ));
        $colony->addPin($this->createExtractorPin(
            productTypeId: 2073,
            cycleTime: 1800,
            qtyPerCycle: 100,
            expiryTime: new \DateTimeImmutable('+1 day'),
        ));

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertNotEmpty($result['tiers']);
        $items = $result['tiers'][0]['items'];
        $this->assertCount(2, $items);
        // Higher ISK should come first
        $this->assertSame(2073, $items[0]['typeId']);
        $this->assertSame(2074, $items[1]['typeId']);
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createExtractorPin(
        ?int $productTypeId,
        ?int $cycleTime,
        ?int $qtyPerCycle,
        ?\DateTimeImmutable $expiryTime,
    ): PlanetaryPin {
        static $pinCounter = 0;
        $pin = new PlanetaryPin();
        $pin->setPinId(++$pinCounter);
        $pin->setTypeId(2848); // Extractor Control Unit type
        $pin->setExtractorProductTypeId($productTypeId);
        $pin->setExtractorCycleTime($cycleTime);
        $pin->setExtractorQtyPerCycle($qtyPerCycle);
        $pin->setExpiryTime($expiryTime);

        return $pin;
    }

    private function createFactoryPin(int $schematicId): PlanetaryPin
    {
        static $pinCounter = 1000;
        $pin = new PlanetaryPin();
        $pin->setPinId(++$pinCounter);
        $pin->setTypeId(2480); // Basic Industry Facility type
        $pin->setSchematicId($schematicId);

        return $pin;
    }

    /**
     * Setup all stubs needed for a factory scenario with a single output type.
     */
    private function setupFactoryScenario(
        InvType $type,
        int $schematicId,
        int $outputTypeId,
        int $outputQty,
        int $cycleTime,
        float $price,
    ): void {
        $schematicMap = [
            $schematicId => [
                'inputs' => [],
                'output' => ['typeId' => $outputTypeId, 'quantity' => $outputQty],
            ],
        ];
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn($schematicMap);

        $schematic = new PlanetSchematic();
        $schematic->setSchematicId($schematicId);
        $schematic->setSchematicName($type->getTypeName());
        $schematic->setCycleTime($cycleTime);
        $this->schematicRepository->method('findBySchematicId')->willReturn($schematic);

        $this->invTypeRepository->method('find')->willReturn($type);
        $this->jitaMarketService->method('getPrices')->willReturn([$outputTypeId => $price]);
    }
}
