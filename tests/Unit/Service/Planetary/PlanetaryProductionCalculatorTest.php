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
use App\Service\TypeNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryProductionCalculator::class)]
class PlanetaryProductionCalculatorTest extends TestCase
{
    private const SUPPLY_TYPE_ID = 2073;
    private const RAW_MATERIALS_MARKET_GROUP_ID = 1333;

    private PlanetSchematicRepository $schematicRepository;
    private PlanetSchematicTypeRepository $schematicTypeRepository;
    private InvTypeRepository $invTypeRepository;
    private TypeNameResolver $typeNameResolver;
    private JitaMarketService $jitaMarketService;
    private PlanetaryProductionCalculator $calculator;

    protected function setUp(): void
    {
        $this->schematicRepository = $this->createStub(PlanetSchematicRepository::class);
        $this->schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $this->invTypeRepository = $this->createStub(InvTypeRepository::class);
        $this->typeNameResolver = $this->createStub(TypeNameResolver::class);
        $this->jitaMarketService = $this->createStub(JitaMarketService::class);

        $this->calculator = new PlanetaryProductionCalculator(
            $this->schematicRepository,
            $this->schematicTypeRepository,
            $this->invTypeRepository,
            $this->typeNameResolver,
            $this->jitaMarketService,
        );
    }

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

    public function testActiveExtractorProducesDailyOutput(): void
    {
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn([]);
        $this->stubRawMaterialTypes();
        $this->jitaMarketService->method('getPrices')->willReturn([2073 => 5.0]);

        $colony = new PlanetaryColony();
        $pin = $this->createExtractorPin(
            productTypeId: 2073,
            cycleTime: 900,
            qtyPerCycle: 1000,
            expiryTime: new \DateTimeImmutable('+2 days'),
        );
        $colony->addPin($pin);

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertSame(96000.0, $result['tiers'][0]['items'][0]['dailyQuantity']);
        $this->assertSame(5.0, $result['tiers'][0]['items'][0]['unitPrice']);
        $this->assertSame(480000.0, $result['tiers'][0]['items'][0]['dailyIskValue']);
        $this->assertSame(480000.0, $result['totalDailyIsk']);
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

    #[Group('pi-audit-15')]
    public function testExtractorWithoutProductTypeCurrentlyProducesNothingSilentlyPiAudit15(): void
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
        $pin = new PlanetaryPin();
        $pin->setPinId(1);
        $pin->setTypeId(3000);
        $colony->addPin($pin);

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertSame([], $result['tiers']);
    }

    public function testMultipleExtractorsOfSameTypeSumUp(): void
    {
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn([]);
        $this->stubRawMaterialTypes();
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

        $this->assertSame(14400.0, $result['totalDailyIsk']);
    }

    public function testTierClassificationP1(): void
    {
        $marketGroup = new InvMarketGroup();
        $marketGroup->setMarketGroupId(1334);

        $type = new InvType();
        $type->setTypeId(2389);
        $type->setTypeName('Water');
        $type->setMarketGroup($marketGroup);

        $colony = $this->setupSuppliedFactoryScenario(type: $type, schematicId: 121, outputTypeId: 2389, outputQty: 20, cycleTime: 1800, price: 400.0, inputQty: 3000);

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertSame(['P1', 'Basic Commodities'], $this->tierAndLabelOf($result, $type->getTypeId()));
    }

    public function testTierClassificationP2(): void
    {
        $marketGroup = new InvMarketGroup();
        $marketGroup->setMarketGroupId(1335);

        $type = new InvType();
        $type->setTypeId(9840);
        $type->setTypeName('Coolant');
        $type->setMarketGroup($marketGroup);

        $colony = $this->setupSuppliedFactoryScenario(type: $type, schematicId: 130, outputTypeId: 9840, outputQty: 5, cycleTime: 3600, price: 8000.0, inputQty: 40);

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertSame(['P2', 'Refined Commodities'], $this->tierAndLabelOf($result, $type->getTypeId()));
    }

    public function testTierClassificationP3(): void
    {
        $marketGroup = new InvMarketGroup();
        $marketGroup->setMarketGroupId(1336);

        $type = new InvType();
        $type->setTypeId(17392);
        $type->setTypeName('Condensates');
        $type->setMarketGroup($marketGroup);

        $colony = $this->setupSuppliedFactoryScenario(type: $type, schematicId: 140, outputTypeId: 17392, outputQty: 3, cycleTime: 3600, price: 50000.0, inputQty: 10);

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertSame(['P3', 'Specialized Commodities'], $this->tierAndLabelOf($result, $type->getTypeId()));
    }

    public function testTierClassificationP4(): void
    {
        $marketGroup = new InvMarketGroup();
        $marketGroup->setMarketGroupId(1337);

        $type = new InvType();
        $type->setTypeId(2867);
        $type->setTypeName('Broadcast Node');
        $type->setMarketGroup($marketGroup);

        $colony = $this->setupSuppliedFactoryScenario(type: $type, schematicId: 150, outputTypeId: 2867, outputQty: 1, cycleTime: 3600, price: 900000.0, inputQty: 6);

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertSame(['P4', 'Advanced Commodities'], $this->tierAndLabelOf($result, $type->getTypeId()));
    }

    public function testTierClassificationP0ForTheRawMaterialsMarketGroup(): void
    {
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn([]);
        $this->stubRawMaterialTypes();
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
        $parentGroup = new InvMarketGroup();
        $parentGroup->setMarketGroupId(1335);

        $childGroup = new InvMarketGroup();
        $childGroup->setMarketGroupId(99999);
        $childGroup->setMarketGroupName('Sub-P2');
        $childGroup->setParentGroup($parentGroup);

        $type = new InvType();
        $type->setTypeId(9840);
        $type->setTypeName('Coolant');
        $type->setMarketGroup($childGroup);

        $colony = $this->setupSuppliedFactoryScenario(type: $type, schematicId: 130, outputTypeId: 9840, outputQty: 5, cycleTime: 3600, price: 8000.0, inputQty: 40);

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertSame(['P2', 'Refined Commodities'], $this->tierAndLabelOf($result, $type->getTypeId()));
    }

    public function testMonthlyIskIs30TimesDailyIsk(): void
    {
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn([]);
        $this->stubRawMaterialTypes();
        $this->jitaMarketService->method('getPrices')->willReturn([2073 => 10.0]);

        $colony = new PlanetaryColony();
        $colony->addPin($this->createExtractorPin(
            productTypeId: 2073,
            cycleTime: 1800,
            qtyPerCycle: 100,
            expiryTime: new \DateTimeImmutable('+1 day'),
        ));

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertSame(48000.0, $result['totalDailyIsk']);
        $this->assertSame(1440000.0, $result['totalMonthlyIsk']);
    }

    public function testTierItemsAreSortedByDailyIskDescending(): void
    {
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn([]);
        $this->stubRawMaterialTypes();
        $this->jitaMarketService->method('getPrices')->willReturn([
            2073 => 97.3,
            2074 => 1.9,
        ]);

        $colony = new PlanetaryColony();
        $colony->addPin($this->createExtractorPin(
            productTypeId: 2074,
            cycleTime: 1800,
            qtyPerCycle: 137,
            expiryTime: new \DateTimeImmutable('+1 day'),
        ));
        $colony->addPin($this->createExtractorPin(
            productTypeId: 2073,
            cycleTime: 3600,
            qtyPerCycle: 100,
            expiryTime: new \DateTimeImmutable('+1 day'),
        ));

        $result = $this->calculator->calculateProduction([$colony]);

        $this->assertSame(
            [2073 => 233520.0, 2074 => 12494.4],
            array_column($result['tiers'][0]['items'], 'dailyIskValue', 'typeId'),
        );
    }

    private function createExtractorPin(
        ?int $productTypeId,
        ?int $cycleTime,
        ?int $qtyPerCycle,
        ?\DateTimeImmutable $expiryTime,
    ): PlanetaryPin {
        static $pinCounter = 0;
        $pin = new PlanetaryPin();
        $pin->setPinId(++$pinCounter);
        $pin->setTypeId(2848);
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
        $pin->setTypeId(2480);
        $pin->setSchematicId($schematicId);
        $pin->setLastCycleStart(new \DateTimeImmutable('-10 minutes'));

        return $pin;
    }

    private function setupSuppliedFactoryScenario(
        InvType $type,
        int $schematicId,
        int $outputTypeId,
        int $outputQty,
        int $cycleTime,
        float $price,
        int $inputQty,
    ): PlanetaryColony {
        $this->schematicTypeRepository->method('getSchematicMap')->willReturn([
            $schematicId => [
                'inputs' => [self::SUPPLY_TYPE_ID => $inputQty],
                'output' => ['typeId' => $outputTypeId, 'quantity' => $outputQty],
            ],
        ]);

        $schematic = new PlanetSchematic();
        $schematic->setSchematicId($schematicId);
        $schematic->setSchematicName($type->getTypeName());
        $schematic->setCycleTime($cycleTime);
        $this->schematicRepository->method('findBySchematicId')->willReturnCallback(
            static fn (int $requestedSchematicId): ?PlanetSchematic => $requestedSchematicId === $schematicId ? $schematic : null,
        );

        $this->invTypeRepository->method('find')->willReturnCallback(
            fn (mixed $requestedTypeId): InvType => $requestedTypeId === $outputTypeId ? $type : $this->rawMaterialType($requestedTypeId),
        );
        $this->jitaMarketService->method('getPrices')->willReturn([$outputTypeId => $price, self::SUPPLY_TYPE_ID => 3.17]);

        $colony = new PlanetaryColony();
        $colony->addPin($this->createExtractorPin(
            productTypeId: self::SUPPLY_TYPE_ID,
            cycleTime: $cycleTime,
            qtyPerCycle: $inputQty,
            expiryTime: new \DateTimeImmutable('+1 day'),
        ));
        $colony->addPin($this->createFactoryPin(schematicId: $schematicId));

        return $colony;
    }

    private function stubRawMaterialTypes(): void
    {
        $this->invTypeRepository->method('find')->willReturnCallback(
            fn (mixed $typeId): InvType => $this->rawMaterialType($typeId),
        );
    }

    private function rawMaterialType(int $typeId): InvType
    {
        $marketGroup = new InvMarketGroup();
        $marketGroup->setMarketGroupId(self::RAW_MATERIALS_MARKET_GROUP_ID);

        $type = new InvType();
        $type->setTypeId($typeId);
        $type->setTypeName('Raw material '.$typeId);
        $type->setMarketGroup($marketGroup);

        return $type;
    }

    private function tierAndLabelOf(array $result, int $typeId): array
    {
        foreach ($result['tiers'] as $tier) {
            if (in_array($typeId, array_column($tier['items'], 'typeId'), true)) {
                return [$tier['tier'], $tier['label']];
            }
        }

        return [];
    }
}
