<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Planetary;

use App\Entity\Character;
use App\Entity\Notification;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\PlanetaryRoute;
use App\Entity\Sde\DgmAttributeType;
use App\Entity\Sde\InvType;
use App\Entity\Sde\PlanetSchematic;
use App\Entity\Sde\PlanetSchematicType;
use App\Entity\User;
use App\Entity\UserNotificationPreference;
use App\Entity\CachedCharacterSkill;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\Sde\DgmAttributeTypeRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\Sde\PlanetSchematicRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Repository\UserNotificationPreferenceRepository;
use App\Service\Planetary\ExtractorYieldCalculator;
use App\Service\Planetary\PlanetaryExpiryThresholdResolver;
use App\Service\Planetary\PlanetaryExtractionBalanceCalculator;
use App\Service\Planetary\PlanetaryFactoryColonyTracker;
use App\Service\Planetary\PlanetaryInputNeedsCalculator;
use App\Service\Planetary\PlanetaryPowerCalculator;
use App\Service\Planetary\PlanetaryProductionCalculator;
use App\Service\TypeNameResolver;
use App\State\Provider\Planetary\PlanetaryResourceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(PlanetaryResourceMapper::class)]
final class PlanetaryResourceMapperTest extends TestCase
{
    private const int BACTERIA = 2393;
    private const int MICROORGANISMS = 2073;
    private const int NANITES = 2463;
    private const int ROBOTICS = 9848;
    private const int BARREN_EXTRACTOR_CONTROL_UNIT = 2848;
    private const int BASIC_INDUSTRY_FACILITY = 2473;
    private const int STORAGE_FACILITY = 2541;

    private const int SCHEMATIC_BACTERIA = 131;
    private const int SCHEMATIC_NANITES = 97;

    private const array SDE_TYPES = [
        self::BACTERIA => ['name' => 'Bacteria', 'volume' => 0.38, 'capacity' => null],
        self::MICROORGANISMS => ['name' => 'Microorganisms', 'volume' => 0.01, 'capacity' => null],
        self::NANITES => ['name' => 'Nanites', 'volume' => 6.0, 'capacity' => null],
        self::BARREN_EXTRACTOR_CONTROL_UNIT => ['name' => 'Barren Extractor Control Unit', 'volume' => 0.0, 'capacity' => null],
        self::BASIC_INDUSTRY_FACILITY => ['name' => 'Barren Basic Industry Facility', 'volume' => 0.0, 'capacity' => null],
        self::STORAGE_FACILITY => ['name' => 'Barren Storage Facility', 'volume' => 0.0, 'capacity' => 12000.0],
    ];

    private const array SCHEMATICS = [
        self::SCHEMATIC_BACTERIA => [
            'name' => 'Bacteria',
            'cycleTime' => 1800,
            'inputs' => [self::MICROORGANISMS => 3000],
            'output' => [self::BACTERIA => 20],
        ],
        self::SCHEMATIC_NANITES => [
            'name' => 'Nanites',
            'cycleTime' => 3600,
            'inputs' => [self::ROBOTICS => 6, self::BACTERIA => 40],
            'output' => [self::NANITES => 1],
        ],
    ];

    private int $nextPinId = 1;

    private ?int $planetaryThresholdMinutes = null;

    private ?PlanetaryFactoryColonyTracker $factoryColonyTracker = null;

    private ?float $factoryAutonomyDays = null;

    private ?array $powerOutputs = null;

    private ?array $powerLoad = null;

    private ?array $yieldFactors = ['decay' => 0.012, 'noise' => 0.8];

    private array $skillLevelsByCharacterId = [];

    public function testRoutesToKnownTypesKeepTheirSdeContentTypeName(): void
    {
        $colony = $this->colony();
        $colony->addRoute($this->route(routeId: 501, contentTypeId: self::BACTERIA, quantity: 1200.0));

        $routes = $this->mapper()->toDetailResource($colony)->routes;

        $this->assertSame([
            [
                'routeId' => 501,
                'sourcePinId' => 11,
                'destinationPinId' => 12,
                'contentTypeId' => self::BACTERIA,
                'contentTypeName' => 'Bacteria',
                'contentVolume' => 0.38,
                'quantity' => 1200.0,
                'waypoints' => [],
            ],
        ], $routes);
    }

    public function testEachRouteCarriesTheSdeVolumeOfItsOwnContentType(): void
    {
        $colony = $this->colony();
        $colony->addRoute($this->route(routeId: 503, contentTypeId: self::NANITES, quantity: 10.0));
        $colony->addRoute($this->route(routeId: 504, contentTypeId: self::MICROORGANISMS, quantity: 3000.0));

        $volumes = array_column($this->mapper()->toDetailResource($colony)->routes, 'contentVolume', 'routeId');

        $this->assertSame([503 => 6.0, 504 => 0.01], $volumes);
    }

    public function testRouteToTypeAbsentFromTheSdeHasAnUnknownContentTypeName(): void
    {
        $colony = $this->colony();
        $colony->addRoute($this->route(routeId: 502, contentTypeId: self::ROBOTICS, quantity: 3000.0));

        $routes = $this->mapper()->toDetailResource($colony)->routes;

        $this->assertSame([
            [
                'routeId' => 502,
                'sourcePinId' => 11,
                'destinationPinId' => 12,
                'contentTypeId' => self::ROBOTICS,
                'contentTypeName' => null,
                'contentVolume' => null,
                'quantity' => 3000.0,
                'waypoints' => [],
            ],
        ], $routes);
    }

    public function testPinWithoutStoredNameOfAKnownTypeTakesItsSdeName(): void
    {
        $pin = $this->pin(self::STORAGE_FACILITY);

        $mappedPin = $this->mapSinglePin($pin);

        $this->assertSame(
            ['typeId' => self::STORAGE_FACILITY, 'typeName' => 'Barren Storage Facility', 'capacity' => 12000.0],
            $this->fieldsOf($mappedPin, ['typeId', 'typeName', 'capacity']),
        );
    }

    public function testPinWithoutStoredNameOfTypeAbsentFromTheSdeHasAnUnknownTypeName(): void
    {
        $pin = $this->pin(self::ROBOTICS);

        $mappedPin = $this->mapSinglePin($pin);

        $this->assertSame(
            ['typeId' => self::ROBOTICS, 'typeName' => null, 'capacity' => null],
            $this->fieldsOf($mappedPin, ['typeId', 'typeName', 'capacity']),
        );
    }

    public function testPinStoredNameIsKeptForTypeAbsentFromTheSde(): void
    {
        $pin = $this->pin(self::ROBOTICS);
        $pin->setTypeName('Robotics');

        $mappedPin = $this->mapSinglePin($pin);

        $this->assertSame(
            ['typeId' => self::ROBOTICS, 'typeName' => 'Robotics'],
            $this->fieldsOf($mappedPin, ['typeId', 'typeName']),
        );
    }

    public function testExtractorOfAKnownProductKeepsItsSdeProductName(): void
    {
        $mappedPin = $this->mapSinglePin($this->extractorPin(self::MICROORGANISMS));

        $this->assertSame(
            [
                'typeName' => 'Barren Extractor Control Unit',
                'extractorProductTypeId' => self::MICROORGANISMS,
                'extractorProductName' => 'Microorganisms',
                'extractorCycleTime' => 900,
                'extractorQtyPerCycle' => 6420,
            ],
            $this->fieldsOf($mappedPin, ['typeName', 'extractorProductTypeId', 'extractorProductName', 'extractorCycleTime', 'extractorQtyPerCycle']),
        );
    }

    public function testExtractorOfProductAbsentFromTheSdeHasAnUnknownProductName(): void
    {
        $mappedPin = $this->mapSinglePin($this->extractorPin(self::ROBOTICS));

        $this->assertSame(
            [
                'typeName' => 'Barren Extractor Control Unit',
                'extractorProductTypeId' => self::ROBOTICS,
                'extractorProductName' => null,
                'extractorCycleTime' => 900,
                'extractorQtyPerCycle' => 6420,
            ],
            $this->fieldsOf($mappedPin, ['typeName', 'extractorProductTypeId', 'extractorProductName', 'extractorCycleTime', 'extractorQtyPerCycle']),
        );
    }

    public function testPinContentAbsentFromTheSdeHasAnUnknownTypeNameAndVolumeNextToKnownContent(): void
    {
        $pin = $this->pin(self::STORAGE_FACILITY);
        $pin->setContents([
            ['type_id' => self::BACTERIA, 'amount' => 450],
            ['type_id' => self::ROBOTICS, 'amount' => 120],
        ]);

        $mappedPin = $this->mapSinglePin($pin);

        $this->assertSame([
            ['typeId' => self::BACTERIA, 'typeName' => 'Bacteria', 'amount' => 450, 'volume' => 0.38],
            ['typeId' => self::ROBOTICS, 'typeName' => null, 'amount' => 120, 'volume' => null],
        ], $mappedPin['contents']);
    }

    public function testSchematicOfKnownTypesKeepsTheirSdeNames(): void
    {
        $mappedPin = $this->mapSinglePin($this->factoryPin(self::SCHEMATIC_BACTERIA));

        $this->assertSame(
            [
                'typeName' => 'Barren Basic Industry Facility',
                'schematicName' => 'Bacteria',
                'schematicCycleTime' => 1800,
                'schematicInputs' => [
                    ['typeId' => self::MICROORGANISMS, 'typeName' => 'Microorganisms', 'quantity' => 3000],
                ],
                'schematicOutput' => ['typeId' => self::BACTERIA, 'typeName' => 'Bacteria', 'quantity' => 20],
            ],
            $this->fieldsOf($mappedPin, ['typeName', 'schematicName', 'schematicCycleTime', 'schematicInputs', 'schematicOutput']),
        );
    }

    public function testSchematicInputAbsentFromTheSdeHasAnUnknownTypeName(): void
    {
        $mappedPin = $this->mapSinglePin($this->factoryPin(self::SCHEMATIC_NANITES));

        $this->assertSame(
            [
                'schematicName' => 'Nanites',
                'schematicCycleTime' => 3600,
                'schematicInputs' => [
                    ['typeId' => self::ROBOTICS, 'typeName' => null, 'quantity' => 6],
                    ['typeId' => self::BACTERIA, 'typeName' => 'Bacteria', 'quantity' => 40],
                ],
                'schematicOutput' => ['typeId' => self::NANITES, 'typeName' => 'Nanites', 'quantity' => 1],
            ],
            $this->fieldsOf($mappedPin, ['schematicName', 'schematicCycleTime', 'schematicInputs', 'schematicOutput']),
        );
    }

    #[Group('issue-537')]
    public function testExtractorExpiringWithinTheUserThresholdMakesTheColonyExpiring(): void
    {
        $this->planetaryThresholdMinutes = 360;

        $this->assertSame(['expiring', true], $this->statusAndExpiringFlagOfAnExtractorExpiring('+300 minutes'));
    }

    #[Group('issue-537')]
    public function testExtractorBeyondTheUserThresholdKeepsTheColonyActive(): void
    {
        $this->planetaryThresholdMinutes = 360;

        $this->assertSame(['active', false], $this->statusAndExpiringFlagOfAnExtractorExpiring('+420 minutes'));
    }

    #[Group('issue-537')]
    public function testWithoutPlanetaryPreferenceTheThresholdIsOneDay(): void
    {
        $this->planetaryThresholdMinutes = null;

        $this->assertSame(['expiring', true], $this->statusAndExpiringFlagOfAnExtractorExpiring('+23 hours'));
        $this->assertSame(['active', false], $this->statusAndExpiringFlagOfAnExtractorExpiring('+25 hours'));
    }

    #[Group('issue-537')]
    public function testExpiredExtractorStaysExpiredWhateverTheUserThreshold(): void
    {
        $this->planetaryThresholdMinutes = 10080;

        $this->assertSame(['expired', false], $this->statusAndExpiringFlagOfAnExtractorExpiring('-1 minute'));
    }

    #[Group('issue-744')]
    public function testColonyWithoutExtractorExposesTheTrackedFactoryProgressWithItsEndTime(): void
    {
        $tracker = $this->createMock(PlanetaryFactoryColonyTracker::class);
        $tracker->expects($this->once())->method('track')->willReturn([
            'finalProductTypeId' => self::NANITES,
            'finalProductName' => 'Nanites',
            'factoryCount' => 2,
            'stockedFinalProduct' => 5,
            'maxProducts' => 50,
            'remainingSeconds' => 7200,
        ]);
        $this->factoryColonyTracker = $tracker;
        $colony = $this->colony()->setCachedAt(new \DateTimeImmutable('2026-10-18T09:05:00+00:00'));
        $colony->addPin($this->factoryPin(self::SCHEMATIC_NANITES));

        $progress = $this->mapper()->toResource($colony)->factoryProgress;

        $this->assertNotNull($progress);
        $this->assertSame(
            [self::NANITES, 'Nanites', 2, 5, 50, 7200],
            [$progress['finalProductTypeId'], $progress['finalProductName'], $progress['factoryCount'], $progress['stockedFinalProduct'], $progress['maxProducts'], $progress['remainingSeconds']],
        );
        $this->assertSame('2026-10-18T11:05:00+00:00', $progress['endsAt']);
    }

    #[Group('issue-744')]
    public function testColonyWithAnExtractorIsNotTrackedAsAFactoryColony(): void
    {
        $tracker = $this->createMock(PlanetaryFactoryColonyTracker::class);
        $tracker->expects($this->never())->method('track');
        $this->factoryColonyTracker = $tracker;
        $colony = $this->colony();
        $colony->addPin($this->extractorPin(self::BACTERIA)->setExpiryTime(new \DateTimeImmutable('+2 days')));
        $colony->addPin($this->factoryPin(self::SCHEMATIC_NANITES));

        $this->assertNull($this->mapper()->toResource($colony)->factoryProgress);
    }

    #[Group('issue-744')]
    public function testColonyWithoutAnyFactoryIsNotTrackedAsAFactoryColony(): void
    {
        $tracker = $this->createMock(PlanetaryFactoryColonyTracker::class);
        $tracker->expects($this->never())->method('track');
        $this->factoryColonyTracker = $tracker;

        $this->assertNull($this->mapper()->toResource($this->colony())->factoryProgress);
    }

    #[Group('issue-1379')]
    public function testColonyWithFactoriesAndNoExtractorHasTheFactoryStatus(): void
    {
        $colony = $this->colony();
        $colony->addPin($this->factoryPin(self::SCHEMATIC_NANITES));

        $this->assertSame('factory', $this->mapper()->toResource($colony)->status);
    }

    #[Group('issue-1379')]
    public function testFactoryColonyExposesItsAutonomyAndTheDateTheStockRunsOut(): void
    {
        $this->factoryAutonomyDays = 1.5;
        $colony = $this->colony()->setCachedAt(new \DateTimeImmutable('2026-10-18T09:05:00+00:00'));
        $colony->addPin($this->factoryPin(self::SCHEMATIC_NANITES));

        $resource = $this->mapper()->toResource($colony);

        $this->assertSame(1.5, $resource->factoryAutonomyDays);
        $this->assertSame('2026-10-19T21:05:00+00:00', $resource->factoryRefillBy);
    }

    #[Group('issue-1379')]
    public function testFactoryColonyWithoutBoundedAutonomyHasNoRefillDate(): void
    {
        $this->factoryAutonomyDays = null;
        $colony = $this->colony();
        $colony->addPin($this->factoryPin(self::SCHEMATIC_NANITES));

        $resource = $this->mapper()->toResource($colony);

        $this->assertSame('factory', $resource->status);
        $this->assertNull($resource->factoryAutonomyDays);
        $this->assertNull($resource->factoryRefillBy);
    }

    #[Group('issue-1379')]
    public function testExtractionColonyKeepsItsStatusAndExposesNoAutonomy(): void
    {
        $this->factoryAutonomyDays = 2.0;
        $colony = $this->colony();
        $colony->addPin($this->extractorPin(self::BACTERIA)->setExpiryTime(new \DateTimeImmutable('+2 days')));
        $colony->addPin($this->factoryPin(self::SCHEMATIC_NANITES));

        $resource = $this->mapper()->toResource($colony);

        $this->assertSame('active', $resource->status);
        $this->assertNull($resource->factoryAutonomyDays);
        $this->assertNull($resource->factoryRefillBy);
    }

    #[Group('issue-1379')]
    public function testColonyWithoutAnyPinStaysIdle(): void
    {
        $this->factoryAutonomyDays = 2.0;

        $resource = $this->mapper()->toResource($this->colony());

        $this->assertSame('idle', $resource->status);
        $this->assertNull($resource->factoryAutonomyDays);
    }

    #[Group('issue-1385')]
    public function testListResourceCarriesTheCpuAndPowergridUsedAgainstTheCommandCenterOutput(): void
    {
        $this->powerOutputs = ['cpu' => 25415, 'powergrid' => 19000];
        $this->powerLoad = ['cpu' => 4000, 'powergrid' => 2300];

        $resource = $this->mapper()->toResource($this->colony());

        $this->assertSame(
            [4000, 25415, 2300, 19000],
            [$resource->cpuUsed, $resource->cpuMax, $resource->powergridUsed, $resource->powergridMax],
        );
    }

    #[Group('issue-1385')]
    public function testDetailResourceCarriesTheSameCpuAndPowergridFigures(): void
    {
        $this->powerOutputs = ['cpu' => 1675, 'powergrid' => 6000];
        $this->powerLoad = ['cpu' => 950, 'powergrid' => 5350];

        $resource = $this->mapper()->toDetailResource($this->colony());

        $this->assertSame(
            [950, 1675, 5350, 6000],
            [$resource->cpuUsed, $resource->cpuMax, $resource->powergridUsed, $resource->powergridMax],
        );
    }

    #[Group('issue-1385')]
    public function testUnknownLoadLeavesEveryCpuAndPowergridFigureUnknownEvenWhenTheOutputIsKnown(): void
    {
        $this->powerOutputs = ['cpu' => 25415, 'powergrid' => 19000];
        $this->powerLoad = null;

        $resource = $this->mapper()->toResource($this->colony());

        $this->assertNull($resource->cpuUsed);
        $this->assertNull($resource->cpuMax);
        $this->assertNull($resource->powergridUsed);
        $this->assertNull($resource->powergridMax);
    }

    #[Group('issue-1385')]
    public function testUnknownOutputLeavesEveryCpuAndPowergridFigureUnknownEvenWhenTheLoadIsKnown(): void
    {
        $this->powerOutputs = null;
        $this->powerLoad = ['cpu' => 4000, 'powergrid' => 2300];

        $resource = $this->mapper()->toResource($this->colony());

        $this->assertNull($resource->cpuUsed);
        $this->assertNull($resource->cpuMax);
        $this->assertNull($resource->powergridUsed);
        $this->assertNull($resource->powergridMax);
    }

    #[Group('issue-1385')]
    public function testColonyIsUpgradableWhenTheCharacterSkillExceedsTheCommandCenterLevel(): void
    {
        $this->skillLevelsByCharacterId = [2112000201 => [CachedCharacterSkill::SKILL_COMMAND_CENTER_UPGRADES => 4, CachedCharacterSkill::SKILL_INTERPLANETARY_CONSOLIDATION => 2]];

        $resource = $this->mapper()->toResource($this->colony()->setUpgradeLevel(3));

        $this->assertSame(4, $resource->commandCenterSkillLevel);
        $this->assertTrue($resource->commandCenterUpgradable);
    }

    #[Group('issue-1385')]
    public function testColonyIsNotUpgradableWhenTheCharacterSkillEqualsTheCommandCenterLevel(): void
    {
        $this->skillLevelsByCharacterId = [2112000201 => [CachedCharacterSkill::SKILL_COMMAND_CENTER_UPGRADES => 3]];

        $resource = $this->mapper()->toResource($this->colony()->setUpgradeLevel(3));

        $this->assertSame(3, $resource->commandCenterSkillLevel);
        $this->assertFalse($resource->commandCenterUpgradable);
    }

    #[Group('issue-1385')]
    public function testUntrainedSkillIsKnownAtZeroAndDoesNotMakeTheColonyUpgradable(): void
    {
        $this->skillLevelsByCharacterId = [2112000201 => [CachedCharacterSkill::SKILL_COMMAND_CENTER_UPGRADES => 0]];

        $resource = $this->mapper()->toResource($this->colony()->setUpgradeLevel(0));

        $this->assertSame(0, $resource->commandCenterSkillLevel);
        $this->assertFalse($resource->commandCenterUpgradable);
    }

    #[Group('issue-1385')]
    public function testCharacterWithoutSkillLineHasAnUnknownSkillAndAnUnknownUpgradeSignal(): void
    {
        $this->skillLevelsByCharacterId = [2112000201 => [CachedCharacterSkill::SKILL_INTERPLANETARY_CONSOLIDATION => 2]];

        $resource = $this->mapper()->toResource($this->colony()->setUpgradeLevel(1));

        $this->assertNull($resource->commandCenterSkillLevel);
        $this->assertNull($resource->commandCenterUpgradable);
    }

    #[Group('issue-1386')]
    public function testExtractorWithItsProgramDatesCarriesTheYieldCurveOfItsProgram(): void
    {
        $install = new \DateTimeImmutable('2026-11-24 12:00:00');
        $pin = $this->extractorPin(self::MICROORGANISMS)
            ->setExtractorCycleTime(1800)
            ->setExtractorQtyPerCycle(6965)
            ->setInstallTime($install)
            ->setExpiryTime($install->modify('+171000 seconds'));

        $mappedPin = $this->mapSinglePin($pin);

        $this->assertArrayHasKey('yieldCurve', $mappedPin);
        $this->assertIsArray($mappedPin['yieldCurve']);
        $this->assertSame(['cycleSeconds', 'yields', 'totalQuantity', 'averagePerHour', 'suggestedDays'], array_keys($mappedPin['yieldCurve']));
        $this->assertSame(1800, $mappedPin['yieldCurve']['cycleSeconds']);
        $this->assertCount(95, $mappedPin['yieldCurve']['yields']);
        $this->assertEqualsWithDelta(24261.6594, $mappedPin['yieldCurve']['yields'][0], 0.001);
        $this->assertEqualsWithDelta(789365.5893, $mappedPin['yieldCurve']['totalQuantity'], 0.001);
        $this->assertEqualsWithDelta(16618.2229, $mappedPin['yieldCurve']['averagePerHour'], 0.001);
        $this->assertSame(2, $mappedPin['yieldCurve']['suggestedDays']);
    }

    #[Group('issue-1386')]
    public function testExtractorWithoutInstallTimeCarriesAnUnknownYieldCurveKey(): void
    {
        $mappedPin = $this->mapSinglePin($this->extractorPin(self::MICROORGANISMS));

        $this->assertArrayHasKey('yieldCurve', $mappedPin);
        $this->assertNull($mappedPin['yieldCurve']);
    }

    #[Group('issue-1386')]
    public function testExtractorWithoutSdeFactorsCarriesAnUnknownYieldCurveKey(): void
    {
        $this->yieldFactors = null;
        $install = new \DateTimeImmutable('2026-11-24 12:00:00');
        $pin = $this->extractorPin(self::MICROORGANISMS)->setInstallTime($install)->setExpiryTime($install->modify('+48 hours'));

        $mappedPin = $this->mapSinglePin($pin);

        $this->assertArrayHasKey('yieldCurve', $mappedPin);
        $this->assertNull($mappedPin['yieldCurve']);
    }

    #[Group('issue-1386')]
    public function testFactoryAndStoragePinsCarryAnUnknownYieldCurveKey(): void
    {
        $install = new \DateTimeImmutable('2026-11-24 12:00:00');
        $colony = $this->colony();
        $colony->addPin($this->factoryPin(self::SCHEMATIC_BACTERIA)->setInstallTime($install)->setExpiryTime($install->modify('+48 hours')));
        $colony->addPin($this->pin(self::STORAGE_FACILITY));

        $pins = $this->mapper()->toDetailResource($colony)->pins;

        $this->assertCount(2, $pins);
        foreach ($pins as $mappedPin) {
            $this->assertArrayHasKey('yieldCurve', $mappedPin);
            $this->assertNull($mappedPin['yieldCurve']);
        }
    }

    #[Group('issue-1386')]
    public function testListResourceDoesNotCarryPinsAndSoNoYieldCurve(): void
    {
        $install = new \DateTimeImmutable('2026-11-24 12:00:00');
        $colony = $this->colony();
        $colony->addPin($this->extractorPin(self::MICROORGANISMS)->setInstallTime($install)->setExpiryTime($install->modify('+48 hours')));

        $resource = $this->mapper()->toResource($colony);

        $this->assertSame([], $resource->pins);
    }

    #[Group('issue-1387')]
    public function testListResourceCarriesTheExtractionBalanceOfAnExtractionColonyWithFactories(): void
    {
        $resource = $this->mapper()->toResource($this->extractionColonyFeedingOneBacteriaFactory());

        $this->assertExtractionBalanceOfMicroorganisms($resource->extractionBalance);
    }

    #[Group('issue-1387')]
    public function testDetailResourceCarriesTheSameExtractionBalance(): void
    {
        $resource = $this->mapper()->toDetailResource($this->extractionColonyFeedingOneBacteriaFactory());

        $this->assertExtractionBalanceOfMicroorganisms($resource->extractionBalance);
    }

    #[Group('issue-1387')]
    public function testColonyWithoutExtractorHasAnEmptyExtractionBalance(): void
    {
        $colony = $this->colony();
        $colony->addPin($this->factoryPin(self::SCHEMATIC_BACTERIA));

        $this->assertSame([], $this->mapper()->toResource($colony)->extractionBalance);
        $this->assertSame([], $this->mapper()->toDetailResource($colony)->extractionBalance);
    }

    #[Group('issue-1387')]
    public function testColonyWithoutAnyPinHasAnEmptyExtractionBalance(): void
    {
        $this->assertSame([], $this->mapper()->toResource($this->colony())->extractionBalance);
    }

    private function extractionColonyFeedingOneBacteriaFactory(): PlanetaryColony
    {
        $colony = $this->colony();
        $colony->addPin($this->extractorPin(self::MICROORGANISMS)->setExtractorNumHeads(4));
        $colony->addPin($this->factoryPin(self::SCHEMATIC_BACTERIA));

        return $colony;
    }

    private function assertExtractionBalanceOfMicroorganisms(array $extractionBalance): void
    {
        $this->assertCount(1, $extractionBalance);
        $row = $extractionBalance[0];
        $this->assertSame(self::MICROORGANISMS, $row['typeId']);
        $this->assertSame('Microorganisms', $row['typeName']);
        $this->assertEqualsWithDelta(616320.0, $row['extractedPerDay'], 0.0001);
        $this->assertEqualsWithDelta(144000.0, $row['demandedPerDay'], 0.0001);
        $this->assertEqualsWithDelta(472320.0, $row['balancePerDay'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $row['runRate'], 0.0001);
        $this->assertSame(1, $row['factoryCount']);
        $this->assertSame(4, $row['headCount']);
        $this->assertSame('surplus', $row['advice']['kind']);
        $this->assertSame(3, $row['advice']['factories']);
        $this->assertNull($row['advice']['heads']);
    }

    private function statusAndExpiringFlagOfAnExtractorExpiring(string $expiresIn): array
    {
        $colony = $this->colony();
        $colony->addPin($this->extractorPin(self::BACTERIA)->setExpiryTime(new \DateTimeImmutable($expiresIn)));

        $resource = $this->mapper()->toDetailResource($colony);

        $this->assertCount(1, $resource->pins);
        $this->assertArrayHasKey('isExpiringSoon', $resource->pins[0]);

        return [$resource->status, $resource->pins[0]['isExpiringSoon']];
    }

    private function mapSinglePin(PlanetaryPin $pin): array
    {
        $colony = $this->colony();
        $colony->addPin($pin);

        $pins = $this->mapper()->toDetailResource($colony)->pins;

        $this->assertCount(1, $pins);

        return $pins[0];
    }

    private function fieldsOf(array $mappedPin, array $fieldNames): array
    {
        $fields = [];
        foreach ($fieldNames as $fieldName) {
            $this->assertArrayHasKey($fieldName, $mappedPin);
            $fields[$fieldName] = $mappedPin[$fieldName];
        }

        return $fields;
    }

    private function mapper(): PlanetaryResourceMapper
    {
        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturnCallback(
            static function (mixed $typeId): ?InvType {
                if (!isset(self::SDE_TYPES[$typeId])) {
                    return null;
                }

                return (new InvType())
                    ->setTypeId($typeId)
                    ->setTypeName(self::SDE_TYPES[$typeId]['name'])
                    ->setVolume(self::SDE_TYPES[$typeId]['volume'])
                    ->setCapacity(self::SDE_TYPES[$typeId]['capacity']);
            },
        );

        $schematicRepository = $this->createStub(PlanetSchematicRepository::class);
        $schematicRepository->method('find')->willReturnCallback($this->sdeSchematic(...));

        $solarSystemRepository = $this->createStub(MapSolarSystemRepository::class);
        $solarSystemRepository->method('findBySolarSystemId')->willReturn(null);

        $preferenceRepository = $this->createStub(UserNotificationPreferenceRepository::class);
        $preferenceRepository->method('findByUserAndCategory')->willReturn(
            $this->planetaryThresholdMinutes === null
                ? null
                : (new UserNotificationPreference())->setCategory(Notification::CATEGORY_PLANETARY)->setThresholdMinutes($this->planetaryThresholdMinutes),
        );

        $productionCalculator = $this->createStub(PlanetaryProductionCalculator::class);
        $productionCalculator->method('calculateAutonomyDays')->willReturn($this->factoryAutonomyDays);

        $powerCalculator = $this->createStub(PlanetaryPowerCalculator::class);
        $powerCalculator->method('outputs')->willReturn($this->powerOutputs);
        $powerCalculator->method('load')->willReturn($this->powerLoad);

        $skillRepository = $this->createStub(CachedCharacterSkillRepository::class);
        $skillRepository->method('findLevelsByEveCharacterId')->willReturnCallback(
            fn (int $eveCharacterId): array => $this->skillLevelsByCharacterId[$eveCharacterId] ?? [],
        );

        $attributeRepository = $this->createStub(DgmAttributeTypeRepository::class);
        $attributeRepository->method('find')->willReturnCallback(
            fn (mixed $attributeId): ?DgmAttributeType => $this->yieldFactors === null
                ? null
                : (new DgmAttributeType())
                    ->setAttributeId($attributeId)
                    ->setDefaultValue($attributeId === 1683 ? $this->yieldFactors['decay'] : $this->yieldFactors['noise']),
        );

        return new PlanetaryResourceMapper(
            $invTypeRepository,
            new TypeNameResolver($invTypeRepository),
            $schematicRepository,
            $solarSystemRepository,
            new PlanetaryExpiryThresholdResolver($preferenceRepository),
            $this->factoryColonyTracker ?? $this->createStub(PlanetaryFactoryColonyTracker::class),
            $productionCalculator,
            $powerCalculator,
            $skillRepository,
            new ExtractorYieldCalculator($attributeRepository),
            $this->extractionBalanceCalculator($invTypeRepository),
        );
    }

    private function extractionBalanceCalculator(InvTypeRepository $invTypeRepository): PlanetaryExtractionBalanceCalculator
    {
        $balanceInvTypeRepository = $this->createStub(InvTypeRepository::class);
        $balanceInvTypeRepository->method('findByTypeIds')->willReturnCallback(
            static function (array $typeIds) use ($invTypeRepository): array {
                $types = [];
                foreach ($typeIds as $typeId) {
                    $type = $invTypeRepository->find($typeId);
                    if ($type !== null) {
                        $types[$typeId] = $type;
                    }
                }

                return $types;
            },
        );

        $balanceSchematicRepository = $this->createStub(PlanetSchematicRepository::class);
        $balanceSchematicRepository->method('findBySchematicId')->willReturnCallback($this->sdeSchematic(...));

        $schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $schematicTypeRepository->method('getSchematicMap')->willReturn([
            self::SCHEMATIC_BACTERIA => ['inputs' => [self::MICROORGANISMS => 3000], 'output' => ['typeId' => self::BACTERIA, 'quantity' => 20]],
            self::SCHEMATIC_NANITES => ['inputs' => [self::ROBOTICS => 6, self::BACTERIA => 40], 'output' => ['typeId' => self::NANITES, 'quantity' => 1]],
        ]);

        $constantRateYield = $this->createStub(ExtractorYieldCalculator::class);
        $constantRateYield->method('programDailyAverage')->willReturnCallback(
            static fn (int $quantityPerCycle, int $cycleTime, ?\DateTimeImmutable $installTime, ?\DateTimeImmutable $expiryTime): ?float => $expiryTime === null
                ? null
                : $quantityPerCycle * (86400 / $cycleTime),
        );

        $clock = new MockClock();

        return new PlanetaryExtractionBalanceCalculator(
            new PlanetaryInputNeedsCalculator(
                $balanceSchematicRepository,
                $schematicTypeRepository,
                $balanceInvTypeRepository,
                new TypeNameResolver($invTypeRepository),
                $clock,
                $constantRateYield,
            ),
            $clock,
        );
    }

    private function sdeSchematic(mixed $schematicId): ?PlanetSchematic
    {
        if (!isset(self::SCHEMATICS[$schematicId])) {
            return null;
        }

        $definition = self::SCHEMATICS[$schematicId];
        $schematic = (new PlanetSchematic())
            ->setSchematicId($schematicId)
            ->setSchematicName($definition['name'])
            ->setCycleTime($definition['cycleTime']);

        foreach ($definition['inputs'] as $typeId => $quantity) {
            $schematic->getSchematicTypes()->add($this->schematicType($schematic, $typeId, $quantity, isInput: true));
        }
        foreach ($definition['output'] as $typeId => $quantity) {
            $schematic->getSchematicTypes()->add($this->schematicType($schematic, $typeId, $quantity, isInput: false));
        }

        return $schematic;
    }

    private function schematicType(PlanetSchematic $schematic, int $typeId, int $quantity, bool $isInput): PlanetSchematicType
    {
        return (new PlanetSchematicType())
            ->setSchematic($schematic)
            ->setTypeId($typeId)
            ->setIsInput($isInput)
            ->setQuantity($quantity);
    }

    private function colony(): PlanetaryColony
    {
        $character = new Character();
        $character->setEveCharacterId(2112000201);
        $character->setName('Planetary Pilot');
        $character->setUser(new User());

        return (new PlanetaryColony())
            ->setCharacter($character)
            ->setPlanetId(40009077)
            ->setPlanetType('barren')
            ->setSolarSystemId(30000142)
            ->setSolarSystemName('Jita')
            ->setPlanetName('Jita IV')
            ->setUpgradeLevel(4)
            ->setNumPins(1)
            ->setLastUpdate(new \DateTimeImmutable('2026-10-18T09:00:00Z'));
    }

    private function route(int $routeId, int $contentTypeId, float $quantity): PlanetaryRoute
    {
        return (new PlanetaryRoute())
            ->setRouteId($routeId)
            ->setSourcePinId(11)
            ->setDestinationPinId(12)
            ->setContentTypeId($contentTypeId)
            ->setQuantity($quantity);
    }

    private function pin(int $typeId): PlanetaryPin
    {
        return (new PlanetaryPin())
            ->setPinId($this->nextPinId++)
            ->setTypeId($typeId);
    }

    private function extractorPin(int $productTypeId): PlanetaryPin
    {
        return $this->pin(self::BARREN_EXTRACTOR_CONTROL_UNIT)
            ->setExtractorProductTypeId($productTypeId)
            ->setExtractorCycleTime(900)
            ->setExtractorQtyPerCycle(6420)
            ->setExpiryTime(new \DateTimeImmutable('+3 days'));
    }

    private function factoryPin(int $schematicId): PlanetaryPin
    {
        return $this->pin(self::BASIC_INDUSTRY_FACILITY)->setSchematicId($schematicId);
    }
}
