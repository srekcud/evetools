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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryProductionCalculator::class)]
final class PlanetaryProductionCalculatorCharacterizationTest extends TestCase
{
    private const AQUEOUS_LIQUIDS = 2268;
    private const IONIC_SOLUTIONS = 2309;
    private const BASE_METALS = 2267;
    private const NON_CS_CRYSTALS = 2306;
    private const WATER = 3645;
    private const ELECTROLYTES = 2390;
    private const REACTIVE_METALS = 2398;
    private const COOLANT = 9832;
    private const WATER_COOLED_CPU = 2328;
    private const SMARTFAB_UNITS = 2351;
    private const VACCINES = 28974;
    private const STERILE_CONDUITS = 2875;
    private const BACTERIA = 2393;
    private const ROBOTICS = 9848;

    private const SCHEMATIC_WATER = 121;
    private const SCHEMATIC_ELECTROLYTES = 123;
    private const SCHEMATIC_REACTIVE_METALS = 126;
    private const SCHEMATIC_COOLANT = 66;
    private const SCHEMATIC_WATER_COOLED_CPU = 72;
    private const SCHEMATIC_STERILE_CONDUITS = 113;
    private const SCHEMATIC_BROKEN = 999;
    private const SCHEMATIC_ROBOTICS = 97;

    private const MARKET_GROUP_P0 = 1333;
    private const MARKET_GROUP_P1 = 1334;
    private const MARKET_GROUP_P2 = 1335;
    private const MARKET_GROUP_P3 = 1336;
    private const MARKET_GROUP_P4 = 1337;

    private const TYPE_NAMES = [
        self::AQUEOUS_LIQUIDS => 'Aqueous Liquids',
        self::IONIC_SOLUTIONS => 'Ionic Solutions',
        self::BASE_METALS => 'Base Metals',
        self::WATER => 'Water',
        self::ELECTROLYTES => 'Electrolytes',
        self::REACTIVE_METALS => 'Reactive Metals',
        self::COOLANT => 'Coolant',
        self::WATER_COOLED_CPU => 'Water-Cooled CPU',
        self::SMARTFAB_UNITS => 'Smartfab Units',
        self::VACCINES => 'Vaccines',
        self::STERILE_CONDUITS => 'Sterile Conduits',
    ];

    private const MARKET_GROUP_BY_TYPE = [
        self::AQUEOUS_LIQUIDS => self::MARKET_GROUP_P0,
        self::IONIC_SOLUTIONS => self::MARKET_GROUP_P0,
        self::BASE_METALS => self::MARKET_GROUP_P0,
        self::WATER => self::MARKET_GROUP_P1,
        self::ELECTROLYTES => self::MARKET_GROUP_P1,
        self::REACTIVE_METALS => self::MARKET_GROUP_P1,
        self::COOLANT => self::MARKET_GROUP_P2,
        self::WATER_COOLED_CPU => self::MARKET_GROUP_P2,
        self::SMARTFAB_UNITS => self::MARKET_GROUP_P3,
        self::VACCINES => self::MARKET_GROUP_P3,
        self::STERILE_CONDUITS => self::MARKET_GROUP_P4,
    ];

    private const JITA_PRICES = [
        self::AQUEOUS_LIQUIDS => 6.834713,
        self::IONIC_SOLUTIONS => 9.4713,
        self::BASE_METALS => 4.2179,
        self::NON_CS_CRYSTALS => 3.6871,
        self::WATER => 412.37,
        self::ELECTROLYTES => 467.91,
        self::REACTIVE_METALS => 389.53,
        self::COOLANT => 11873.4,
        self::WATER_COOLED_CPU => 13261.9,
        self::SMARTFAB_UNITS => 58213.7,
        self::VACCINES => 64027.3,
        self::STERILE_CONDUITS => 1137492.0,
    ];

    private const SCHEMATIC_MAP = [
        self::SCHEMATIC_WATER => [
            'inputs' => [self::AQUEOUS_LIQUIDS => 3000],
            'output' => ['typeId' => self::WATER, 'quantity' => 20],
        ],
        self::SCHEMATIC_ELECTROLYTES => [
            'inputs' => [self::IONIC_SOLUTIONS => 3000],
            'output' => ['typeId' => self::ELECTROLYTES, 'quantity' => 20],
        ],
        self::SCHEMATIC_REACTIVE_METALS => [
            'inputs' => [self::BASE_METALS => 3000],
            'output' => ['typeId' => self::REACTIVE_METALS, 'quantity' => 20],
        ],
        self::SCHEMATIC_COOLANT => [
            'inputs' => [self::ELECTROLYTES => 40, self::WATER => 40],
            'output' => ['typeId' => self::COOLANT, 'quantity' => 5],
        ],
        self::SCHEMATIC_WATER_COOLED_CPU => [
            'inputs' => [self::REACTIVE_METALS => 40, self::WATER => 40],
            'output' => ['typeId' => self::WATER_COOLED_CPU, 'quantity' => 5],
        ],
        self::SCHEMATIC_STERILE_CONDUITS => [
            'inputs' => [self::SMARTFAB_UNITS => 6, self::VACCINES => 6, self::WATER => 40],
            'output' => ['typeId' => self::STERILE_CONDUITS, 'quantity' => 1],
        ],
    ];

    private const SCHEMATIC_CYCLE_TIMES = [
        self::SCHEMATIC_WATER => 1800,
        self::SCHEMATIC_ELECTROLYTES => 1800,
        self::SCHEMATIC_REACTIVE_METALS => 1800,
        self::SCHEMATIC_COOLANT => 3600,
        self::SCHEMATIC_WATER_COOLED_CPU => 3600,
        self::SCHEMATIC_STERILE_CONDUITS => 3600,
    ];

    private int $nextPinId = 1;

    public function testActiveExtractorsProduceQtyPerCycleTimesCyclesPerDayOnTheRawMaterialsTier(): void
    {
        $result = $this->calculator()->calculateProduction([$this->threeActiveExtractorsColony()]);

        $this->assertSame([
            [
                'tier' => 'P0',
                'label' => 'Raw Materials',
                'items' => [
                    $this->item(self::IONIC_SOLUTIONS, 300312.0, 9.4713, 2844345.05),
                    $this->item(self::AQUEOUS_LIQUIDS, 144000.0, 6.834713, 984198.67),
                    $this->item(self::BASE_METALS, 14844.0, 4.2179, 62610.51),
                ],
                'dailyIskValue' => 3891154.23,
            ],
        ], $result['tiers']);
        $this->assertSame(3891154.23, $result['totalDailyIsk']);
        $this->assertSame(116734626.76, $result['totalMonthlyIsk']);
    }

    public function testCalculateTotalDailyIskReturnsTheProductionTotal(): void
    {
        $this->assertSame(3891154.23, $this->calculator()->calculateTotalDailyIsk([$this->threeActiveExtractorsColony()]));
    }

    public function testExtractorsOfTheSameProductAcrossColoniesAreSummed(): void
    {
        $firstColony = $this->colony(
            $this->extractorPin(self::IONIC_SOLUTIONS, cycleTime: 1800, qtyPerCycle: 4127, expiryTime: new \DateTimeImmutable('+2 days')),
        );
        $secondColony = $this->colony(
            $this->extractorPin(self::IONIC_SOLUTIONS, cycleTime: 3600, qtyPerCycle: 7391, expiryTime: new \DateTimeImmutable('+6 hours')),
        );

        $result = $this->calculator()->calculateProduction([$firstColony, $secondColony]);

        $this->assertSame([
            [
                'tier' => 'P0',
                'label' => 'Raw Materials',
                'items' => [
                    $this->item(self::IONIC_SOLUTIONS, 375480.0, 9.4713, 3556283.72),
                ],
                'dailyIskValue' => 3556283.72,
            ],
        ], $result['tiers']);
        $this->assertSame(3556283.72, $result['totalDailyIsk']);
        $this->assertSame(106688511.72, $result['totalMonthlyIsk']);
    }

    public function testExpiredExtractorIsLeftOutWhileAnActiveOneStillCounts(): void
    {
        $colony = $this->colony(
            $this->extractorPin(self::AQUEOUS_LIQUIDS, cycleTime: 1800, qtyPerCycle: 3000, expiryTime: new \DateTimeImmutable('-10 minutes')),
            $this->extractorPin(self::BASE_METALS, cycleTime: 3600, qtyPerCycle: 8641, expiryTime: new \DateTimeImmutable('+10 minutes')),
        );

        $result = $this->calculator()->calculateProduction([$colony]);

        $this->assertSame([
            [
                'tier' => 'P0',
                'label' => 'Raw Materials',
                'items' => [
                    $this->item(self::BASE_METALS, 207384.0, 4.2179, 874724.97),
                ],
                'dailyIskValue' => 874724.97,
            ],
        ], $result['tiers']);
        $this->assertSame(874724.97, $result['totalDailyIsk']);
        $this->assertSame(26241749.21, $result['totalMonthlyIsk']);
    }

    public static function inactiveExtractors(): iterable
    {
        yield 'zero cycle time' => [0, new \DateTimeImmutable('+1 day')];
        yield 'negative cycle time' => [-1800, new \DateTimeImmutable('+1 day')];
        yield 'expired' => [1800, new \DateTimeImmutable('-1 day')];
    }

    #[DataProvider('inactiveExtractors')]
    public function testInactiveExtractorProducesNothing(int $cycleTime, \DateTimeImmutable $expiryTime): void
    {
        $colony = $this->colony($this->extractorPin(self::AQUEOUS_LIQUIDS, $cycleTime, 3000, $expiryTime));

        $result = $this->calculator()->calculateProduction([$colony]);

        $this->assertSame([], $result['tiers']);
        $this->assertSame(0.0, $result['totalDailyIsk']);
        $this->assertSame(0.0, $result['totalMonthlyIsk']);
    }

    public static function extractorsMissingEsiDetails(): iterable
    {
        yield 'no cycle_time' => [null, 3000];
        yield 'no qty_per_cycle' => [1800, null];
    }

    #[DataProvider('extractorsMissingEsiDetails')]
    #[Group('pi-audit-15')]
    public function testExtractorMissingEsiDetailsCurrentlyProducesNothingSilentlyPiAudit15(?int $cycleTime, ?int $qtyPerCycle): void
    {
        $colony = $this->colony($this->extractorPin(self::AQUEOUS_LIQUIDS, $cycleTime, $qtyPerCycle, new \DateTimeImmutable('+1 day')));

        $result = $this->calculator()->calculateProduction([$colony]);

        $this->assertSame([], $result['tiers']);
        $this->assertSame(0.0, $result['totalDailyIsk']);
        $this->assertSame(0.0, $result['totalMonthlyIsk']);
    }

    public function testFactoryWithASchematicAbsentFromTheSchematicMapProducesAndConsumesNothing(): void
    {
        $this->assertOnlyTheSuppliedWaterFactoryCounts(self::SCHEMATIC_MAP, self::SCHEMATIC_CYCLE_TIMES + [self::SCHEMATIC_BROKEN => 1800]);
    }

    public static function brokenSdeSchematics(): iterable
    {
        $brokenWater = ['inputs' => [self::AQUEOUS_LIQUIDS => 3000], 'output' => ['typeId' => self::WATER, 'quantity' => 20]];

        yield 'schematic absent from the SDE schematics' => [self::SCHEMATIC_MAP + [self::SCHEMATIC_BROKEN => $brokenWater], self::SCHEMATIC_CYCLE_TIMES];
        yield 'schematic without output' => [
            self::SCHEMATIC_MAP + [self::SCHEMATIC_BROKEN => ['inputs' => [self::AQUEOUS_LIQUIDS => 3000], 'output' => null]],
            self::SCHEMATIC_CYCLE_TIMES + [self::SCHEMATIC_BROKEN => 1800],
        ];
        yield 'schematic with a zero cycle time' => [
            self::SCHEMATIC_MAP + [self::SCHEMATIC_BROKEN => $brokenWater],
            self::SCHEMATIC_CYCLE_TIMES + [self::SCHEMATIC_BROKEN => 0],
        ];
    }

    #[DataProvider('brokenSdeSchematics')]
    #[Group('pi-audit-15')]
    public function testFactoryWithABrokenSdeSchematicCurrentlyProducesAndConsumesNothingSilentlyPiAudit15(array $schematicMap, array $schematicCycleTimes): void
    {
        $this->assertOnlyTheSuppliedWaterFactoryCounts($schematicMap, $schematicCycleTimes);
    }

    #[Group('pi-audit-2')]
    public function testAdvancedCommodityInputsKeepTheirOwnSchematicQuantityCurrentlyWithoutSupplyPiAudit2(): void
    {
        $colony = $this->colony($this->factoryPin(self::SCHEMATIC_STERILE_CONDUITS));

        $result = $this->calculator()->calculateProduction([$colony]);

        $inputs = $this->factoryOutputsByTypeId($result)[self::STERILE_CONDUITS][5];
        $this->assertSame(
            [self::SMARTFAB_UNITS => 144.0, self::VACCINES => 144.0, self::WATER => 960.0],
            array_column($inputs, 'dailyConsumed', 'typeId'),
        );
    }

    public function testSuppliedChainFromRawMaterialsToRefinedCommoditiesReportsFactoryOutputsInputsAndDeltas(): void
    {
        $result = $this->calculator()->calculateProduction([$this->suppliedChainColony()]);

        $this->assertSame([
            self::WATER => ['P1', 'Basic Commodities', 'Water', 3840.0, 412.37, [
                $this->input(self::AQUEOUS_LIQUIDS, dailyConsumed: 576000.0, dailyProduced: 583200.0, delta: 7200.0),
            ]],
            self::ELECTROLYTES => ['P1', 'Basic Commodities', 'Electrolytes', 960.0, 467.91, [
                $this->input(self::IONIC_SOLUTIONS, dailyConsumed: 144000.0, dailyProduced: 300000.0, delta: 156000.0),
            ]],
            self::REACTIVE_METALS => ['P1', 'Basic Commodities', 'Reactive Metals', 1920.0, 389.53, [
                $this->input(self::BASE_METALS, dailyConsumed: 288000.0, dailyProduced: 291804.0, delta: 3804.0),
            ]],
            self::COOLANT => ['P2', 'Refined Commodities', 'Coolant', 120.0, 11873.4, [
                $this->input(self::ELECTROLYTES, dailyConsumed: 960.0, dailyProduced: 960.0, delta: 0.0),
                $this->input(self::WATER, dailyConsumed: 960.0, dailyProduced: 3840.0, delta: 960.0),
            ]],
            self::WATER_COOLED_CPU => ['P2', 'Refined Commodities', 'Water-Cooled CPU', 240.0, 13261.9, [
                $this->input(self::REACTIVE_METALS, dailyConsumed: 1920.0, dailyProduced: 1920.0, delta: 0.0),
                $this->input(self::WATER, dailyConsumed: 1920.0, dailyProduced: 3840.0, delta: 960.0),
            ]],
        ], $this->factoryOutputsByTypeId($result));
    }

    #[Group('pi-audit-1')]
    public function testSuppliedChainTotalCurrentlyAddsTheValueOfEveryTierIncludingConsumedMaterialsPiAudit1(): void
    {
        $result = $this->calculator()->calculateProduction([$this->suppliedChainColony()]);

        $this->assertSame([
            [
                'tier' => 'P0',
                'label' => 'Raw Materials',
                'items' => [
                    $this->item(self::AQUEOUS_LIQUIDS, 583200.0, 6.834713, 3986004.62),
                    $this->item(self::IONIC_SOLUTIONS, 300000.0, 9.4713, 2841390.0),
                    $this->item(self::BASE_METALS, 291804.0, 4.2179, 1230800.09),
                ],
                'dailyIskValue' => 8058194.71,
            ],
            [
                'tier' => 'P1',
                'label' => 'Basic Commodities',
                'items' => [
                    $this->item(self::WATER, 3840.0, 412.37, 1583500.8, [
                        $this->input(self::AQUEOUS_LIQUIDS, dailyConsumed: 576000.0, dailyProduced: 583200.0, delta: 7200.0),
                    ]),
                    $this->item(self::REACTIVE_METALS, 1920.0, 389.53, 747897.6, [
                        $this->input(self::BASE_METALS, dailyConsumed: 288000.0, dailyProduced: 291804.0, delta: 3804.0),
                    ]),
                    $this->item(self::ELECTROLYTES, 960.0, 467.91, 449193.6, [
                        $this->input(self::IONIC_SOLUTIONS, dailyConsumed: 144000.0, dailyProduced: 300000.0, delta: 156000.0),
                    ]),
                ],
                'dailyIskValue' => 2780592.0,
            ],
            [
                'tier' => 'P2',
                'label' => 'Refined Commodities',
                'items' => [
                    $this->item(self::WATER_COOLED_CPU, 240.0, 13261.9, 3182856.0, [
                        $this->input(self::REACTIVE_METALS, dailyConsumed: 1920.0, dailyProduced: 1920.0, delta: 0.0),
                        $this->input(self::WATER, dailyConsumed: 1920.0, dailyProduced: 3840.0, delta: 960.0),
                    ]),
                    $this->item(self::COOLANT, 120.0, 11873.4, 1424808.0, [
                        $this->input(self::ELECTROLYTES, dailyConsumed: 960.0, dailyProduced: 960.0, delta: 0.0),
                        $this->input(self::WATER, dailyConsumed: 960.0, dailyProduced: 3840.0, delta: 960.0),
                    ]),
                ],
                'dailyIskValue' => 4607664.0,
            ],
        ], $result['tiers']);
        $this->assertSame(15446450.71, $result['totalDailyIsk']);
        $this->assertSame(463393521.4, $result['totalMonthlyIsk']);
    }

    #[Group('pi-audit-20')]
    public function testInputDeltaCurrentlyNetsProductionAndConsumptionAcrossColoniesPiAudit20(): void
    {
        $coolantColony = $this->colony(
            $this->extractorPin(self::AQUEOUS_LIQUIDS, cycleTime: 1800, qtyPerCycle: 3000, expiryTime: new \DateTimeImmutable('+2 days')),
            $this->extractorPin(self::IONIC_SOLUTIONS, cycleTime: 3600, qtyPerCycle: 6000, expiryTime: new \DateTimeImmutable('+2 days')),
            $this->factoryPin(self::SCHEMATIC_WATER),
            $this->factoryPin(self::SCHEMATIC_ELECTROLYTES),
            $this->factoryPin(self::SCHEMATIC_COOLANT),
        );
        $cpuColony = $this->colony(
            $this->extractorPin(self::AQUEOUS_LIQUIDS, cycleTime: 3600, qtyPerCycle: 12000, expiryTime: new \DateTimeImmutable('+3 days')),
            $this->extractorPin(self::BASE_METALS, cycleTime: 7200, qtyPerCycle: 12000, expiryTime: new \DateTimeImmutable('+3 days')),
            $this->factoryPin(self::SCHEMATIC_WATER),
            $this->factoryPin(self::SCHEMATIC_WATER),
            $this->factoryPin(self::SCHEMATIC_REACTIVE_METALS),
            $this->factoryPin(self::SCHEMATIC_WATER_COOLED_CPU),
        );

        $result = $this->calculator()->calculateProduction([$coolantColony, $cpuColony]);

        $outputs = $this->factoryOutputsByTypeId($result);
        $this->assertSame([
            $this->input(self::ELECTROLYTES, dailyConsumed: 960.0, dailyProduced: 960.0, delta: 0.0),
            $this->input(self::WATER, dailyConsumed: 960.0, dailyProduced: 2880.0, delta: 960.0),
        ], $outputs[self::COOLANT][5]);
        $this->assertSame([
            $this->input(self::REACTIVE_METALS, dailyConsumed: 960.0, dailyProduced: 960.0, delta: 0.0),
            $this->input(self::WATER, dailyConsumed: 960.0, dailyProduced: 2880.0, delta: 960.0),
        ], $outputs[self::WATER_COOLED_CPU][5]);
    }

    #[Group('pi-audit-2')]
    public function testFactoryWhoseExtractorExpiredCurrentlyProducesAtFullRatePiAudit2(): void
    {
        $colony = $this->colony(
            $this->extractorPin(self::AQUEOUS_LIQUIDS, cycleTime: 1800, qtyPerCycle: 3000, expiryTime: new \DateTimeImmutable('-2 hours')),
            $this->factoryPin(self::SCHEMATIC_WATER),
        );

        $result = $this->calculator()->calculateProduction([$colony]);

        $this->assertSame([
            [
                'tier' => 'P1',
                'label' => 'Basic Commodities',
                'items' => [
                    $this->item(self::WATER, 960.0, 412.37, 395875.2, [
                        $this->input(self::AQUEOUS_LIQUIDS, dailyConsumed: 144000.0, dailyProduced: 0.0, delta: -144000.0),
                    ]),
                ],
                'dailyIskValue' => 395875.2,
            ],
        ], $result['tiers']);
        $this->assertSame(395875.2, $result['totalDailyIsk']);
        $this->assertSame(11876256.0, $result['totalMonthlyIsk']);
    }

    #[Group('pi-audit-6')]
    public function testMissingJitaPriceCurrentlyCountsAsZeroIskInTheTotalPiAudit6(): void
    {
        $colony = $this->colony(
            $this->extractorPin(self::BASE_METALS, cycleTime: 7200, qtyPerCycle: 1237, expiryTime: new \DateTimeImmutable('+1 day')),
            $this->extractorPin(self::AQUEOUS_LIQUIDS, cycleTime: 1800, qtyPerCycle: 3000, expiryTime: new \DateTimeImmutable('+3 days')),
            $this->extractorPin(self::IONIC_SOLUTIONS, cycleTime: 3600, qtyPerCycle: 12513, expiryTime: new \DateTimeImmutable('+5 hours')),
        );
        $prices = [self::AQUEOUS_LIQUIDS => 6.834713, self::BASE_METALS => null];

        $result = $this->calculator(prices: $prices)->calculateProduction([$colony]);

        $this->assertSame([
            'tiers' => [
                [
                    'tier' => 'P0',
                    'label' => 'Raw Materials',
                    'items' => [
                        $this->item(self::AQUEOUS_LIQUIDS, 144000.0, 6.834713, 984198.67),
                        $this->item(self::BASE_METALS, 14844.0, null, 0.0),
                        $this->item(self::IONIC_SOLUTIONS, 300312.0, null, 0.0),
                    ],
                    'dailyIskValue' => 984198.67,
                ],
            ],
            'totalDailyIsk' => 984198.67,
            'totalMonthlyIsk' => 29525960.16,
        ], $result);
    }

    #[Group('pi-audit-7')]
    public function testExtractorWithoutExpiryTimeCurrentlyProducesAsIfActivePiAudit7(): void
    {
        $colony = $this->colony(
            $this->extractorPin(self::IONIC_SOLUTIONS, cycleTime: 3600, qtyPerCycle: 12513, expiryTime: null),
        );

        $result = $this->calculator()->calculateProduction([$colony]);

        $this->assertSame([
            [
                'tier' => 'P0',
                'label' => 'Raw Materials',
                'items' => [
                    $this->item(self::IONIC_SOLUTIONS, 300312.0, 9.4713, 2844345.05),
                ],
                'dailyIskValue' => 2844345.05,
            ],
        ], $result['tiers']);
        $this->assertSame(2844345.05, $result['totalDailyIsk']);
        $this->assertSame(85330351.37, $result['totalMonthlyIsk']);
    }

    #[Group('pi-audit-15')]
    public function testTypeAbsentFromTheSdeCurrentlyFallsBackToRawMaterialsPiAudit15(): void
    {
        $colony = $this->colony(
            $this->extractorPin(self::NON_CS_CRYSTALS, cycleTime: 3600, qtyPerCycle: 100, expiryTime: new \DateTimeImmutable('+1 day')),
        );

        $result = $this->calculator()->calculateProduction([$colony]);

        $this->assertSame([
            [
                'tier' => 'P0',
                'label' => 'Raw Materials',
                'items' => [
                    [
                        'typeId' => self::NON_CS_CRYSTALS,
                        'typeName' => null,
                        'dailyQuantity' => 2400.0,
                        'unitPrice' => 3.6871,
                        'dailyIskValue' => 8849.04,
                        'inputs' => [],
                    ],
                ],
                'dailyIskValue' => 8849.04,
            ],
        ], $result['tiers']);
    }

    public function testFactoryProductAbsentFromTheSdeHasAnUnknownTypeNameNextToItsKnownInput(): void
    {
        $bacteria = new InvType();
        $bacteria->setTypeId(self::BACTERIA);
        $bacteria->setTypeName('Bacteria');
        $basicCommodities = new InvMarketGroup();
        $basicCommodities->setMarketGroupId(self::MARKET_GROUP_P1);
        $bacteria->setMarketGroup($basicCommodities);

        $result = $this->calculator(
            prices: [self::ROBOTICS => 71245.6, self::BACTERIA => 418.75],
            schematicMap: [
                self::SCHEMATIC_ROBOTICS => [
                    'inputs' => [self::BACTERIA => 40],
                    'output' => ['typeId' => self::ROBOTICS, 'quantity' => 3],
                ],
            ],
            schematicCycleTimes: [self::SCHEMATIC_ROBOTICS => 3600],
            typesById: [self::BACTERIA => $bacteria],
        )->calculateProduction([$this->colony($this->factoryPin(self::SCHEMATIC_ROBOTICS))]);

        $this->assertSame([
            'tiers' => [
                [
                    'tier' => 'P0',
                    'label' => 'Raw Materials',
                    'items' => [
                        [
                            'typeId' => self::ROBOTICS,
                            'typeName' => null,
                            'dailyQuantity' => 72.0,
                            'unitPrice' => 71245.6,
                            'dailyIskValue' => 5129683.2,
                            'inputs' => [
                                [
                                    'typeId' => self::BACTERIA,
                                    'typeName' => 'Bacteria',
                                    'dailyConsumed' => 960.0,
                                    'dailyProduced' => 0.0,
                                    'delta' => -960.0,
                                ],
                            ],
                        ],
                    ],
                    'dailyIskValue' => 5129683.2,
                ],
            ],
            'totalDailyIsk' => 5129683.2,
            'totalMonthlyIsk' => 153890496.0,
        ], $result);
    }

    public function testWaterNestedTenMarketGroupsBelowBasicCommoditiesIsStillABasicCommodity(): void
    {
        $result = $this->calculator(typesById: [self::WATER => $this->waterNestedBelowBasicCommodities(9)])
            ->calculateProduction([$this->suppliedWaterColony()]);

        $this->assertSame(['P1', 'Basic Commodities'], $this->tierAndLabelOf($result, self::WATER));
    }

    public function testWaterNestedElevenMarketGroupsBelowBasicCommoditiesFallsBackToRawMaterials(): void
    {
        $result = $this->calculator(typesById: [self::WATER => $this->waterNestedBelowBasicCommodities(10)])
            ->calculateProduction([$this->suppliedWaterColony()]);

        $this->assertSame(['P0', 'Raw Materials'], $this->tierAndLabelOf($result, self::WATER));
    }

    private function assertOnlyTheSuppliedWaterFactoryCounts(array $schematicMap, array $schematicCycleTimes): void
    {
        $colony = $this->colony(
            $this->extractorPin(self::AQUEOUS_LIQUIDS, cycleTime: 1800, qtyPerCycle: 3000, expiryTime: new \DateTimeImmutable('+3 days')),
            $this->factoryPin(self::SCHEMATIC_BROKEN),
            $this->factoryPin(self::SCHEMATIC_WATER),
        );

        $result = $this->calculator(schematicMap: $schematicMap, schematicCycleTimes: $schematicCycleTimes)->calculateProduction([$colony]);

        $this->assertSame([
            self::WATER => ['P1', 'Basic Commodities', 'Water', 960.0, 412.37, [
                $this->input(self::AQUEOUS_LIQUIDS, dailyConsumed: 144000.0, dailyProduced: 144000.0, delta: 0.0),
            ]],
        ], $this->factoryOutputsByTypeId($result));
    }

    private function threeActiveExtractorsColony(): PlanetaryColony
    {
        return $this->colony(
            $this->extractorPin(self::AQUEOUS_LIQUIDS, cycleTime: 1800, qtyPerCycle: 3000, expiryTime: new \DateTimeImmutable('+3 days')),
            $this->extractorPin(self::IONIC_SOLUTIONS, cycleTime: 3600, qtyPerCycle: 12513, expiryTime: new \DateTimeImmutable('+5 hours')),
            $this->extractorPin(self::BASE_METALS, cycleTime: 7200, qtyPerCycle: 1237, expiryTime: new \DateTimeImmutable('+1 day')),
        );
    }

    private function suppliedWaterColony(): PlanetaryColony
    {
        return $this->colony(
            $this->extractorPin(self::AQUEOUS_LIQUIDS, cycleTime: 1800, qtyPerCycle: 3000, expiryTime: new \DateTimeImmutable('+3 days')),
            $this->factoryPin(self::SCHEMATIC_WATER),
        );
    }

    private function suppliedChainColony(): PlanetaryColony
    {
        return $this->colony(
            $this->factoryPin(self::SCHEMATIC_REACTIVE_METALS),
            $this->factoryPin(self::SCHEMATIC_REACTIVE_METALS),
            $this->factoryPin(self::SCHEMATIC_WATER_COOLED_CPU),
            $this->factoryPin(self::SCHEMATIC_WATER_COOLED_CPU),
            $this->extractorPin(self::BASE_METALS, cycleTime: 7200, qtyPerCycle: 24317, expiryTime: new \DateTimeImmutable('+30 hours')),
            $this->extractorPin(self::AQUEOUS_LIQUIDS, cycleTime: 1800, qtyPerCycle: 12150, expiryTime: new \DateTimeImmutable('+4 days')),
            $this->extractorPin(self::IONIC_SOLUTIONS, cycleTime: 3600, qtyPerCycle: 12500, expiryTime: new \DateTimeImmutable('+2 days')),
            $this->factoryPin(self::SCHEMATIC_WATER),
            $this->factoryPin(self::SCHEMATIC_WATER),
            $this->factoryPin(self::SCHEMATIC_WATER),
            $this->factoryPin(self::SCHEMATIC_WATER),
            $this->factoryPin(self::SCHEMATIC_ELECTROLYTES),
            $this->factoryPin(self::SCHEMATIC_COOLANT),
        );
    }

    private function waterNestedBelowBasicCommodities(int $intermediateGroupCount): InvType
    {
        $marketGroup = new InvMarketGroup();
        $marketGroup->setMarketGroupId(self::MARKET_GROUP_P1);
        for ($level = 1; $level <= $intermediateGroupCount; ++$level) {
            $childGroup = new InvMarketGroup();
            $childGroup->setMarketGroupId(4100 + $level);
            $childGroup->setParentGroup($marketGroup);
            $marketGroup = $childGroup;
        }

        $water = new InvType();
        $water->setTypeId(self::WATER);
        $water->setTypeName(self::TYPE_NAMES[self::WATER]);
        $water->setMarketGroup($marketGroup);

        return $water;
    }

    private function factoryOutputsByTypeId(array $result): array
    {
        $byTypeId = [];
        foreach ($result['tiers'] as $tier) {
            foreach ($tier['items'] as $item) {
                if ($item['inputs'] === []) {
                    continue;
                }
                $byTypeId[$item['typeId']] = [$tier['tier'], $tier['label'], $item['typeName'], $item['dailyQuantity'], $item['unitPrice'], $item['inputs']];
            }
        }
        $orderedByTypeId = [];
        foreach (array_keys(self::TYPE_NAMES) as $typeId) {
            if (isset($byTypeId[$typeId])) {
                $orderedByTypeId[$typeId] = $byTypeId[$typeId];
            }
        }

        return $orderedByTypeId;
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

    private function item(int $typeId, float $dailyQuantity, ?float $unitPrice, float $dailyIskValue, array $inputs = []): array
    {
        return [
            'typeId' => $typeId,
            'typeName' => self::TYPE_NAMES[$typeId],
            'dailyQuantity' => $dailyQuantity,
            'unitPrice' => $unitPrice,
            'dailyIskValue' => $dailyIskValue,
            'inputs' => $inputs,
        ];
    }

    private function input(int $typeId, float $dailyConsumed, float $dailyProduced, float $delta): array
    {
        return [
            'typeId' => $typeId,
            'typeName' => self::TYPE_NAMES[$typeId],
            'dailyConsumed' => $dailyConsumed,
            'dailyProduced' => $dailyProduced,
            'delta' => $delta,
        ];
    }

    private function calculator(
        array $prices = self::JITA_PRICES,
        array $schematicMap = self::SCHEMATIC_MAP,
        array $schematicCycleTimes = self::SCHEMATIC_CYCLE_TIMES,
        array $typesById = [],
    ): PlanetaryProductionCalculator {
        $schematicRepository = $this->createStub(PlanetSchematicRepository::class);
        $schematicRepository->method('findBySchematicId')->willReturnCallback(
            static function (int $schematicId) use ($schematicCycleTimes): ?PlanetSchematic {
                if (!isset($schematicCycleTimes[$schematicId])) {
                    return null;
                }
                $schematic = new PlanetSchematic();
                $schematic->setSchematicId($schematicId);
                $schematic->setSchematicName('Schematic '.$schematicId);
                $schematic->setCycleTime($schematicCycleTimes[$schematicId]);

                return $schematic;
            },
        );

        $schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $schematicTypeRepository->method('getSchematicMap')->willReturn($schematicMap);

        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturnCallback(
            static function (mixed $typeId) use ($typesById): ?InvType {
                if (isset($typesById[$typeId])) {
                    return $typesById[$typeId];
                }
                if (!isset(self::MARKET_GROUP_BY_TYPE[$typeId])) {
                    return null;
                }
                $marketGroup = new InvMarketGroup();
                $marketGroup->setMarketGroupId(self::MARKET_GROUP_BY_TYPE[$typeId]);
                $type = new InvType();
                $type->setTypeId($typeId);
                $type->setTypeName(self::TYPE_NAMES[$typeId]);
                $type->setMarketGroup($marketGroup);

                return $type;
            },
        );

        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPrices')->willReturnCallback(
            static fn (array $typeIds): array => array_intersect_key($prices, array_flip($typeIds)),
        );

        return new PlanetaryProductionCalculator(
            $schematicRepository,
            $schematicTypeRepository,
            $invTypeRepository,
            new TypeNameResolver($invTypeRepository),
            $jitaMarketService,
        );
    }

    private function colony(PlanetaryPin ...$pins): PlanetaryColony
    {
        $colony = new PlanetaryColony();
        foreach ($pins as $pin) {
            $colony->addPin($pin);
        }

        return $colony;
    }

    private function extractorPin(int $productTypeId, ?int $cycleTime, ?int $qtyPerCycle, ?\DateTimeImmutable $expiryTime): PlanetaryPin
    {
        $pin = new PlanetaryPin();
        $pin->setPinId($this->nextPinId++);
        $pin->setTypeId(2848);
        $pin->setExtractorProductTypeId($productTypeId);
        $pin->setExtractorCycleTime($cycleTime);
        $pin->setExtractorQtyPerCycle($qtyPerCycle);
        $pin->setExpiryTime($expiryTime);

        return $pin;
    }

    private function factoryPin(int $schematicId): PlanetaryPin
    {
        $pin = new PlanetaryPin();
        $pin->setPinId($this->nextPinId++);
        $pin->setTypeId(2473);
        $pin->setSchematicId($schematicId);
        $pin->setLastCycleStart(new \DateTimeImmutable('-10 minutes'));

        return $pin;
    }
}
