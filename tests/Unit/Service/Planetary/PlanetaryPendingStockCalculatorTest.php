<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\PlanetaryRoute;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\PlanetSchematic;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\PlanetSchematicRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Planetary\ExtractorYieldCalculator;
use App\Service\Planetary\PlanetaryPendingStockCalculator;
use App\Service\TypeNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[Group('issue-1389')]
#[CoversClass(PlanetaryPendingStockCalculator::class)]
final class PlanetaryPendingStockCalculatorTest extends TestCase
{
    private const string NOW = '2026-11-25 12:00:00';
    private const string YESTERDAY = '2026-11-24 12:00:00';
    private const string TOMORROW = '2026-11-26 12:00:00';
    private const string TWENTY_HOURS_AGO = '2026-11-24 16:00:00';
    private const string TWENTY_FIVE_HOURS_AGO = '2026-11-24 11:00:00';
    private const string TWO_DAYS_AGO = '2026-11-23 12:00:00';
    private const string TEN_DAYS_AGO = '2026-11-15 12:00:00';

    private const int SPACEPORTS_GROUP_ID = 1030;
    private const int STORAGE_FACILITIES_GROUP_ID = 1029;
    private const int COMMAND_CENTERS_GROUP_ID = 1027;
    private const int COMMODITIES_GROUP_ID = 1042;
    private const int PROCESSORS_GROUP_ID = 1028;
    private const int EXTRACTOR_CONTROL_UNITS_GROUP_ID = 1063;

    private const int COMMAND_CENTER_TYPE_ID = 2524;
    private const int LAUNCHPAD_TYPE_ID = 2256;
    private const int UNSIZED_LAUNCHPAD_TYPE_ID = 2259;
    private const int STORAGE_FACILITY_TYPE_ID = 2257;
    private const int FACTORY_TYPE_ID = 2473;
    private const int EXTRACTOR_TYPE_ID = 2848;

    private const int BACTERIA = 2393;
    private const int REACTIVE_METALS = 2398;
    private const int PROTEINS = 2395;
    private const int OXYGEN = 3683;
    private const int WATER = 3645;
    private const int COOLANT = 9832;
    private const int ROBOTICS = 9848;
    private const int NANITES = 2867;
    private const int TRANSCRANIAL = 2868;
    private const int CONDENSATES = 2869;
    private const int VIRAL_AGENTS = 2876;
    private const int UNLISTED_COMMODITY = 3999;

    private const int COMMAND_CENTER = 1_029_384_756_001;
    private const int LAUNCHPAD_A = 1_029_384_756_101;
    private const int LAUNCHPAD_B = 1_029_384_756_102;
    private const int STORAGE = 1_029_384_756_103;
    private const int FACTORY = 1_029_384_756_201;
    private const int FACTORY_DOWNSTREAM = 1_029_384_756_202;
    private const int EXTRACTOR = 1_029_384_756_301;
    private const int EXTRACTOR_METALS = 1_029_384_756_302;

    private const int PLANET_ALPHA = 40_112_001;
    private const int PLANET_BETA = 40_112_002;
    private const int PLANET_GAMMA = 40_112_003;
    private const int PLANET_DELTA = 40_112_004;

    private const string ALPHA_NAME = 'Aldranette II';

    private PlanetSchematicRepository $schematicRepository;
    private PlanetSchematicTypeRepository $schematicTypeRepository;
    private InvTypeRepository $invTypeRepository;
    private TypeNameResolver $typeNameResolver;
    private PlanetaryPendingStockCalculator $calculator;

    private array $sdeTypes = [];

    private array $schematicMap = [];

    private array $schematics = [];

    private array $yieldCalls = [];

    private int $nextRouteId = 1;
    private int $sdeLookups = 0;
    private bool $extractorYieldUnknown = false;
    private string $lastUpdate = self::NOW;

    protected function setUp(): void
    {
        $this->schematicRepository = $this->createStub(PlanetSchematicRepository::class);
        $this->schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $this->invTypeRepository = $this->createStub(InvTypeRepository::class);
        $this->typeNameResolver = $this->createStub(TypeNameResolver::class);

        $this->schematicTypeRepository->method('getSchematicMap')->willReturnCallback(fn (): array => $this->schematicMap);
        $this->schematicRepository->method('findBySchematicId')->willReturnCallback(
            fn (int $schematicId): ?PlanetSchematic => $this->schematics[$schematicId] ?? null,
        );
        $this->invTypeRepository->method('findByTypeIds')->willReturnCallback(function (array $typeIds): array {
            ++$this->sdeLookups;

            return array_intersect_key($this->sdeTypes, array_flip($typeIds));
        });
        $this->invTypeRepository->method('find')->willReturnCallback(fn (mixed $typeId): ?InvType => $this->sdeTypes[$typeId] ?? null);
        $this->invTypeRepository->method('findByTypeId')->willReturnCallback(fn (int $typeId): ?InvType => $this->sdeTypes[$typeId] ?? null);
        $this->typeNameResolver->method('findName')->willReturnCallback(
            fn (int $typeId): ?string => isset($this->sdeTypes[$typeId]) ? $this->sdeTypes[$typeId]->getTypeName() : null,
        );

        $this->calculator = new PlanetaryPendingStockCalculator(
            $this->schematicRepository,
            $this->schematicTypeRepository,
            $this->invTypeRepository,
            $this->typeNameResolver,
            new MockClock(self::NOW),
            $this->doubledQuantityExtractorYield(),
        );

        $this->registerType(self::COMMAND_CENTER_TYPE_ID, 'Command Center', self::COMMAND_CENTERS_GROUP_ID, null, 500.0);
        $this->registerType(self::LAUNCHPAD_TYPE_ID, 'Launchpad', self::SPACEPORTS_GROUP_ID, null, 10000.0);
        $this->registerType(self::UNSIZED_LAUNCHPAD_TYPE_ID, 'Unsized Launchpad', self::SPACEPORTS_GROUP_ID, null, null);
        $this->registerType(self::STORAGE_FACILITY_TYPE_ID, 'Storage Facility', self::STORAGE_FACILITIES_GROUP_ID, null, 12000.0);
        $this->registerType(self::FACTORY_TYPE_ID, 'Basic Industry Facility', self::PROCESSORS_GROUP_ID);
        $this->registerType(self::EXTRACTOR_TYPE_ID, 'Extractor Control Unit', self::EXTRACTOR_CONTROL_UNITS_GROUP_ID);
        $this->registerCommodity(self::BACTERIA, 'Bacteria', 0.38);
        $this->registerCommodity(self::REACTIVE_METALS, 'Reactive Metals', 0.52);
        $this->registerCommodity(self::PROTEINS, 'Proteins', null);
        $this->registerCommodity(self::OXYGEN, 'Oxygen', 1.5);
        $this->registerCommodity(self::WATER, 'Aqueous Liquids', 0.5);
        $this->registerCommodity(self::COOLANT, 'Coolant', 1.75);
        $this->registerCommodity(self::ROBOTICS, 'Robotics', 6.0);
        $this->registerCommodity(self::NANITES, 'Nanites', 6.25);
        $this->registerCommodity(self::TRANSCRANIAL, 'Transcranial Microcontrollers', 6.5);
        $this->registerCommodity(self::CONDENSATES, 'Condensates', 6.75);
        $this->registerCommodity(self::VIRAL_AGENTS, 'Viral Agent', 50.0);
    }

    public function testFactoryOutputAtFullRateReachesTheLaunchpadItIsRoutedTo(): void
    {
        $this->lastUpdate = self::TWO_DAYS_AGO;
        $this->defineSchematic(65, [self::BACTERIA => 40], self::ROBOTICS, 1, 3600);
        $colony = $this->alphaColony(
            $this->launchpadPin(self::LAUNCHPAD_A, null),
            $this->factoryPin(self::FACTORY, 65),
        );
        $this->route($colony, self::FACTORY, self::LAUNCHPAD_A, self::ROBOTICS, 100.0);

        $result = $this->calculator->calculate([$colony]);

        $launchpad = $this->storageOf($result, self::LAUNCHPAD_A);
        self::assertSame([self::ROBOTICS], array_column($launchpad['contents'], 'typeId'));
        $content = $this->contentOf($launchpad, self::ROBOTICS);
        $this->assertQuantity(0, $content, 'stock');
        $this->assertQuantity(48, $content, 'projectedStock');
        $this->assertQuantity(6.0, $content, 'volumePerUnit');
        $pending = $this->pendingOf($result, self::ROBOTICS);
        $this->assertQuantity(48, $pending, 'quantity');
        $this->assertQuantity(288, $pending, 'volumeM3');
    }

    public function testExtractorProductionSinceTheLastViewReachesTheStorageItIsRoutedTo(): void
    {
        $this->lastUpdate = self::YESTERDAY;
        $colony = $this->alphaColony(
            $this->storagePin(self::STORAGE, null),
            $this->extractorPin(self::EXTRACTOR, self::BACTERIA, 1800, 4800),
        );
        $this->route($colony, self::EXTRACTOR, self::STORAGE, self::BACTERIA, 100.0);

        $result = $this->calculator->calculate([$colony]);

        $content = $this->contentOf($this->storageOf($result, self::STORAGE), self::BACTERIA);
        $this->assertQuantity(0, $content, 'stock');
        $this->assertQuantity(9600, $content, 'projectedStock');
        $pending = $this->pendingOf($result, self::BACTERIA);
        $this->assertQuantity(9600, $pending, 'quantity');
        $this->assertQuantity(0.38, $pending, 'volumePerUnit');
        $this->assertQuantity(3648, $pending, 'volumeM3');
        self::assertSame('home', $pending['destination']);
        self::assertSame([], $pending['consumers']);
        self::assertSame([self::STORAGE], $pending['pinIds']);
    }

    public function testExtractorYieldIsAskedFromTheLastViewToNow(): void
    {
        $this->lastUpdate = self::YESTERDAY;
        $colony = $this->alphaColony(
            $this->storagePin(self::STORAGE, null),
            $this->extractorPin(self::EXTRACTOR, self::BACTERIA, 1800, 4800),
        );
        $this->route($colony, self::EXTRACTOR, self::STORAGE, self::BACTERIA, 100.0);

        $this->calculator->calculate([$colony]);

        self::assertSame(
            [[4800, 1800, '2026-11-24T12:00:00+00:00', '2026-11-25T12:00:00+00:00']],
            $this->yieldCalls,
        );
    }

    public function testFactoryOutputIsSplitBetweenRoutesOfTheSameTypeByRouteQuantity(): void
    {
        $this->lastUpdate = self::TWENTY_HOURS_AGO;
        $this->defineSchematic(66, [self::BACTERIA => 40], self::NANITES, 5, 3600);
        $colony = $this->alphaColony(
            $this->launchpadPin(self::LAUNCHPAD_A, null),
            $this->storagePin(self::STORAGE, null),
            $this->factoryPin(self::FACTORY, 66),
        );
        $this->route($colony, self::FACTORY, self::LAUNCHPAD_A, self::NANITES, 1800.0);
        $this->route($colony, self::FACTORY, self::STORAGE, self::NANITES, 600.0);

        $result = $this->calculator->calculate([$colony]);

        $this->assertQuantity(75, $this->contentOf($this->storageOf($result, self::LAUNCHPAD_A), self::NANITES), 'projectedStock');
        $this->assertQuantity(25, $this->contentOf($this->storageOf($result, self::STORAGE), self::NANITES), 'projectedStock');
        $pending = $this->pendingOf($result, self::NANITES);
        $this->assertQuantity(100, $pending, 'quantity');
        $this->assertQuantity(625, $pending, 'volumeM3');
        self::assertSame([self::LAUNCHPAD_A, self::STORAGE], $pending['pinIds']);
    }

    public function testRouteToAFactoryConsumesTheStockByTheDailyNeedSinceTheLastView(): void
    {
        $this->lastUpdate = self::TWO_DAYS_AGO;
        $this->defineSchematic(65, [self::BACTERIA => 40], self::OXYGEN, 1, 3600);
        $colony = $this->alphaColony(
            $this->launchpadPin(self::LAUNCHPAD_A, [['type_id' => self::BACTERIA, 'amount' => 5000]]),
            $this->factoryPin(self::FACTORY, 65),
        );
        $this->route($colony, self::LAUNCHPAD_A, self::FACTORY, self::BACTERIA, 1800.0);

        $result = $this->calculator->calculate([$colony]);

        $content = $this->contentOf($this->storageOf($result, self::LAUNCHPAD_A), self::BACTERIA);
        $this->assertQuantity(5000, $content, 'stock');
        $this->assertQuantity(3080, $content, 'projectedStock');
    }

    public function testProjectedStockNeverGoesBelowZero(): void
    {
        $this->lastUpdate = self::TEN_DAYS_AGO;
        $this->defineSchematic(65, [self::BACTERIA => 40], self::OXYGEN, 1, 3600);
        $colony = $this->alphaColony(
            $this->launchpadPin(self::LAUNCHPAD_A, [['type_id' => self::BACTERIA, 'amount' => 5000]]),
            $this->factoryPin(self::FACTORY, 65),
        );
        $this->route($colony, self::LAUNCHPAD_A, self::FACTORY, self::BACTERIA, 1800.0);

        $result = $this->calculator->calculate([$colony]);

        $content = $this->contentOf($this->storageOf($result, self::LAUNCHPAD_A), self::BACTERIA);
        $this->assertQuantity(5000, $content, 'stock');
        $this->assertQuantity(0, $content, 'projectedStock');
    }

    public function testConsumptionIsSplitBetweenTheStoragesRoutedToTheSameFactoryByRouteQuantity(): void
    {
        $this->lastUpdate = self::YESTERDAY;
        $this->defineSchematic(65, [self::BACTERIA => 40], self::OXYGEN, 1, 3600);
        $colony = $this->alphaColony(
            $this->launchpadPin(self::LAUNCHPAD_A, [['type_id' => self::BACTERIA, 'amount' => 5000]]),
            $this->storagePin(self::STORAGE, [['type_id' => self::BACTERIA, 'amount' => 5000]]),
            $this->factoryPin(self::FACTORY, 65),
        );
        $this->route($colony, self::LAUNCHPAD_A, self::FACTORY, self::BACTERIA, 1800.0);
        $this->route($colony, self::STORAGE, self::FACTORY, self::BACTERIA, 600.0);

        $result = $this->calculator->calculate([$colony]);

        $this->assertQuantity(4280, $this->contentOf($this->storageOf($result, self::LAUNCHPAD_A), self::BACTERIA), 'projectedStock');
        $this->assertQuantity(4760, $this->contentOf($this->storageOf($result, self::STORAGE), self::BACTERIA), 'projectedStock');
    }

    public function testEntriesAreReducedProportionallyToTheRoomLeftInTheStorage(): void
    {
        $this->lastUpdate = self::YESTERDAY;
        $colony = $this->alphaColony(
            $this->storagePin(self::STORAGE, [['type_id' => self::VIRAL_AGENTS, 'amount' => 220]]),
            $this->extractorPin(self::EXTRACTOR, self::WATER, 1800, 2000),
        );
        $this->route($colony, self::EXTRACTOR, self::STORAGE, self::WATER, 100.0);

        $result = $this->calculator->calculate([$colony]);

        $storage = $this->storageOf($result, self::STORAGE);
        $this->assertQuantity(220, $this->contentOf($storage, self::VIRAL_AGENTS), 'projectedStock');
        $this->assertQuantity(2000, $this->contentOf($storage, self::WATER), 'projectedStock');
        $this->assertQuantity(12000, $storage, 'usedVolumeM3');
        $pending = $this->pendingOf($result, self::WATER);
        $this->assertQuantity(2000, $pending, 'quantity');
        $this->assertQuantity(1000, $pending, 'volumeM3');
    }

    public function testProjectedStockIsUnknownWhenAnEntryMeetsAStorageWithoutCapacity(): void
    {
        $this->lastUpdate = self::YESTERDAY;
        $colony = $this->alphaColony(
            $this->storagePin(self::LAUNCHPAD_A, null, self::UNSIZED_LAUNCHPAD_TYPE_ID),
            $this->extractorPin(self::EXTRACTOR, self::BACTERIA, 1800, 4800),
        );
        $this->route($colony, self::EXTRACTOR, self::LAUNCHPAD_A, self::BACTERIA, 100.0);

        $result = $this->calculator->calculate([$colony]);

        $launchpad = $this->storageOf($result, self::LAUNCHPAD_A);
        $this->assertNullField($launchpad, 'capacityM3');
        $this->assertNullField($launchpad, 'usedVolumeM3');
        $this->assertNullField($this->contentOf($launchpad, self::BACTERIA), 'projectedStock');
        $pending = $this->pendingOf($result, self::BACTERIA);
        $this->assertNullField($pending, 'quantity');
        $this->assertNullField($pending, 'volumeM3');
        $this->assertQuantity(0.38, $pending, 'volumePerUnit');
    }

    public function testProjectedStockIsUnknownWhenTheEntryTypeHasNoVolume(): void
    {
        $this->lastUpdate = self::YESTERDAY;
        $colony = $this->alphaColony(
            $this->storagePin(self::STORAGE, null),
            $this->extractorPin(self::EXTRACTOR, self::PROTEINS, 1800, 4800),
        );
        $this->route($colony, self::EXTRACTOR, self::STORAGE, self::PROTEINS, 100.0);

        $result = $this->calculator->calculate([$colony]);

        $this->assertNullField($this->contentOf($this->storageOf($result, self::STORAGE), self::PROTEINS), 'projectedStock');
        $this->assertNullField($this->pendingOf($result, self::PROTEINS), 'quantity');
    }

    public function testProjectedStockIsUnknownWhenTheExtractorYieldIsUnknown(): void
    {
        $this->lastUpdate = self::YESTERDAY;
        $this->extractorYieldUnknown = true;
        $colony = $this->alphaColony(
            $this->storagePin(self::STORAGE, null),
            $this->extractorPin(self::EXTRACTOR, self::BACTERIA, 1800, 4800),
        );
        $this->route($colony, self::EXTRACTOR, self::STORAGE, self::BACTERIA, 100.0);

        $result = $this->calculator->calculate([$colony]);

        $content = $this->contentOf($this->storageOf($result, self::STORAGE), self::BACTERIA);
        $this->assertQuantity(0, $content, 'stock');
        $this->assertNullField($content, 'projectedStock');
        $this->assertNullField($this->pendingOf($result, self::BACTERIA), 'quantity');
    }

    public function testExtractorWithoutCycleTimeGivesAnUnknownQuantity(): void
    {
        $this->lastUpdate = self::YESTERDAY;
        $colony = $this->alphaColony(
            $this->storagePin(self::STORAGE, null),
            $this->extractorPin(self::EXTRACTOR, self::BACTERIA, null, 4800),
        );
        $this->route($colony, self::EXTRACTOR, self::STORAGE, self::BACTERIA, 100.0);

        $result = $this->calculator->calculate([$colony]);

        $this->assertNullField($this->contentOf($this->storageOf($result, self::STORAGE), self::BACTERIA), 'projectedStock');
        $pending = $this->pendingOf($result, self::BACTERIA);
        $this->assertNullField($pending, 'quantity');
        $this->assertNullField($pending, 'volumeM3');
    }

    public function testRouteOfAnotherTypeThanTheExtractorProductTakesNoShareOfItsYield(): void
    {
        $this->lastUpdate = self::YESTERDAY;
        $colony = $this->alphaColony(
            $this->launchpadPin(self::LAUNCHPAD_A, null),
            $this->storagePin(self::STORAGE, null),
            $this->extractorPin(self::EXTRACTOR, self::BACTERIA, 1800, 4800),
        );
        $this->route($colony, self::EXTRACTOR, self::STORAGE, self::BACTERIA, 600.0);
        $this->route($colony, self::EXTRACTOR, self::LAUNCHPAD_A, self::REACTIVE_METALS, 1800.0);

        $result = $this->calculator->calculate([$colony]);

        $this->assertQuantity(9600, $this->contentOf($this->storageOf($result, self::STORAGE), self::BACTERIA), 'projectedStock');
        self::assertSame([self::BACTERIA], array_column($result['pendingOutputs'], 'typeId'));
        $this->assertQuantity(9600, $this->pendingOf($result, self::BACTERIA), 'quantity');
    }

    public function testRouteBetweenTwoStoragesMovesNoStock(): void
    {
        $this->lastUpdate = self::YESTERDAY;
        $colony = $this->alphaColony(
            $this->launchpadPin(self::LAUNCHPAD_A, [['type_id' => self::BACTERIA, 'amount' => 700]]),
            $this->storagePin(self::STORAGE, null),
        );
        $this->route($colony, self::LAUNCHPAD_A, self::STORAGE, self::BACTERIA, 600.0);

        $result = $this->calculator->calculate([$colony]);

        $content = $this->contentOf($this->storageOf($result, self::LAUNCHPAD_A), self::BACTERIA);
        $this->assertQuantity(700, $content, 'stock');
        $this->assertQuantity(700, $content, 'projectedStock');
        $this->assertQuantity(0, $this->storageOf($result, self::STORAGE), 'usedVolumeM3');
    }

    public function testOutputConsumedByAFactoryOfTheSameColonyIsNotPending(): void
    {
        $this->lastUpdate = self::YESTERDAY;
        $this->defineSchematic(81, [self::BACTERIA => 100], self::OXYGEN, 20, 3600);
        $this->defineSchematic(82, [self::OXYGEN => 40], self::COOLANT, 5, 3600);
        $colony = $this->alphaColony(
            $this->storagePin(self::STORAGE, null),
            $this->factoryPin(self::FACTORY, 81),
            $this->factoryPin(self::FACTORY_DOWNSTREAM, 82),
        );
        $this->route($colony, self::FACTORY, self::STORAGE, self::OXYGEN, 100.0);
        $this->route($colony, self::STORAGE, self::FACTORY_DOWNSTREAM, self::OXYGEN, 100.0);
        $this->route($colony, self::FACTORY_DOWNSTREAM, self::STORAGE, self::COOLANT, 100.0);

        $result = $this->calculator->calculate([$colony]);

        $storage = $this->storageOf($result, self::STORAGE);
        $this->assertQuantity(480, $this->contentOf($storage, self::OXYGEN), 'projectedStock');
        $this->assertQuantity(120, $this->contentOf($storage, self::COOLANT), 'projectedStock');
        self::assertSame([self::COOLANT], array_column($result['pendingOutputs'], 'typeId'));
        $this->assertQuantity(120, $this->pendingOf($result, self::COOLANT), 'quantity');
        $this->assertQuantity(210, $this->pendingOf($result, self::COOLANT), 'volumeM3');
    }

    public function testPendingOutputGoesToTheColoniesThatConsumeItSortedByPlanetName(): void
    {
        $this->lastUpdate = self::YESTERDAY;
        $this->defineSchematic(65, [self::BACTERIA => 40], self::ROBOTICS, 1, 3600);
        $this->defineSchematic(90, [self::ROBOTICS => 3], self::NANITES, 1, 3600);
        $this->defineSchematic(91, [self::ROBOTICS => 6, self::OXYGEN => 2], self::TRANSCRANIAL, 1, 3600);
        $this->defineSchematic(92, [self::COOLANT => 4], self::CONDENSATES, 1, 3600);
        $producer = $this->alphaColony(
            $this->storagePin(self::STORAGE, null),
            $this->factoryPin(self::FACTORY, 65),
        );
        $this->route($producer, self::FACTORY, self::STORAGE, self::ROBOTICS, 100.0);
        $cassiel = $this->colony(self::PLANET_BETA, 'Cassiel III', $this->factoryPin(self::FACTORY, 90));
        $brinmoor = $this->colony(self::PLANET_GAMMA, 'Brinmoor V', $this->factoryPin(self::FACTORY, 91));
        $dunmere = $this->colony(self::PLANET_DELTA, 'Dunmere I', $this->factoryPin(self::FACTORY, 92));

        $result = $this->calculator->calculate([$producer, $cassiel, $dunmere, $brinmoor]);

        self::assertCount(1, $result['pendingOutputs']);
        $pending = $this->pendingOf($result, self::ROBOTICS);
        $this->assertQuantity(24, $pending, 'quantity');
        self::assertSame('colony', $pending['destination']);
        self::assertSame(
            [
                ['colonyId' => $brinmoor->getId()?->toRfc4122(), 'planetName' => 'Brinmoor V'],
                ['colonyId' => $cassiel->getId()?->toRfc4122(), 'planetName' => 'Cassiel III'],
            ],
            $pending['consumers'],
        );
    }

    public function testFinishedProductNobodyConsumesGoesHome(): void
    {
        $this->lastUpdate = self::YESTERDAY;
        $this->defineSchematic(67, [self::OXYGEN => 10], self::CONDENSATES, 2, 3600);
        $colony = $this->alphaColony(
            $this->launchpadPin(self::LAUNCHPAD_A, null),
            $this->factoryPin(self::FACTORY, 67),
        );
        $this->route($colony, self::FACTORY, self::LAUNCHPAD_A, self::CONDENSATES, 100.0);

        $result = $this->calculator->calculate([$colony]);

        $pending = $this->pendingOf($result, self::CONDENSATES);
        $this->assertQuantity(48, $pending, 'quantity');
        $this->assertQuantity(324, $pending, 'volumeM3');
        self::assertSame('home', $pending['destination']);
        self::assertSame([], $pending['consumers']);
    }

    public function testOutputWithAProjectedQuantityOfZeroIsOmittedFromPendingOutputs(): void
    {
        $this->defineSchematic(65, [self::BACTERIA => 40], self::ROBOTICS, 1, 3600);
        $colony = $this->alphaColony(
            $this->storagePin(self::STORAGE, null),
            $this->factoryPin(self::FACTORY, 65),
        );
        $this->route($colony, self::FACTORY, self::STORAGE, self::ROBOTICS, 100.0);

        $result = $this->calculator->calculate([$colony]);

        $this->assertQuantity(0, $this->contentOf($this->storageOf($result, self::STORAGE), self::ROBOTICS), 'projectedStock');
        self::assertSame([], $result['pendingOutputs']);
    }

    public function testStorageRowCarriesTheColonyIdentityTheSdeFiguresAndTheMeasuredStock(): void
    {
        $colony = $this->alphaColony(
            $this->launchpadPin(self::LAUNCHPAD_A, [
                ['type_id' => self::BACTERIA, 'amount' => 500],
                ['type_id' => self::REACTIVE_METALS, 'amount' => 20],
            ]),
        );

        $result = $this->calculator->calculate([$colony]);

        self::assertTrue($result['complete']);
        self::assertCount(1, $result['storages']);
        $storage = $result['storages'][0];
        self::assertEqualsCanonicalizing(
            ['colonyId', 'planetId', 'planetName', 'solarSystemName', 'characterId', 'characterName', 'pinId', 'typeId', 'typeName', 'kind', 'capacityM3', 'usedVolumeM3', 'lastViewAt', 'dataAgeSeconds', 'dataStale', 'contents'],
            array_keys($storage),
        );
        self::assertSame($colony->getId()?->toRfc4122(), $storage['colonyId']);
        self::assertSame(self::PLANET_ALPHA, $storage['planetId']);
        self::assertSame('Aldranette II', $storage['planetName']);
        self::assertSame('Aldranette', $storage['solarSystemName']);
        self::assertSame(91_112_001, $storage['characterId']);
        self::assertSame('Mira Voss', $storage['characterName']);
        self::assertSame(self::LAUNCHPAD_A, $storage['pinId']);
        self::assertSame(self::LAUNCHPAD_TYPE_ID, $storage['typeId']);
        self::assertSame('Launchpad', $storage['typeName']);
        self::assertSame('launchpad', $storage['kind']);
        $this->assertQuantity(10000, $storage, 'capacityM3');
        $this->assertQuantity(200.4, $storage, 'usedVolumeM3');
        self::assertSame('2026-11-25T12:00:00+00:00', $storage['lastViewAt']);
        self::assertSame(0, $storage['dataAgeSeconds']);
        self::assertFalse($storage['dataStale']);
        self::assertCount(2, $storage['contents']);
        $content = $this->contentOf($storage, self::BACTERIA);
        self::assertEqualsCanonicalizing(['typeId', 'typeName', 'stock', 'projectedStock', 'volumePerUnit'], array_keys($content));
        self::assertSame('Bacteria', $content['typeName']);
        $this->assertQuantity(500, $content, 'stock');
        $this->assertQuantity(500, $content, 'projectedStock');
        $this->assertQuantity(0.38, $content, 'volumePerUnit');
    }

    public function testPendingOutputRowCarriesTheColonyIdentityAndTheDataAge(): void
    {
        $this->lastUpdate = self::TWENTY_FIVE_HOURS_AGO;
        $colony = $this->alphaColony(
            $this->storagePin(self::STORAGE, [['type_id' => self::BACTERIA, 'amount' => 200]]),
            $this->extractorPin(self::EXTRACTOR, self::BACTERIA, 1800, 4800),
        );

        $result = $this->calculator->calculate([$colony]);

        self::assertCount(1, $result['pendingOutputs']);
        $pending = $result['pendingOutputs'][0];
        self::assertEqualsCanonicalizing(
            ['colonyId', 'planetId', 'planetName', 'solarSystemName', 'characterId', 'characterName', 'typeId', 'typeName', 'quantity', 'volumePerUnit', 'volumeM3', 'pinIds', 'consumers', 'destination', 'lastViewAt', 'dataAgeSeconds', 'dataStale'],
            array_keys($pending),
        );
        self::assertSame($colony->getId()?->toRfc4122(), $pending['colonyId']);
        self::assertSame(self::PLANET_ALPHA, $pending['planetId']);
        self::assertSame('Aldranette II', $pending['planetName']);
        self::assertSame('Aldranette', $pending['solarSystemName']);
        self::assertSame(91_112_001, $pending['characterId']);
        self::assertSame('Mira Voss', $pending['characterName']);
        self::assertSame(self::BACTERIA, $pending['typeId']);
        self::assertSame('Bacteria', $pending['typeName']);
        $this->assertQuantity(200, $pending, 'quantity');
        $this->assertQuantity(76, $pending, 'volumeM3');
        self::assertSame('2026-11-24T11:00:00+00:00', $pending['lastViewAt']);
        self::assertSame(90000, $pending['dataAgeSeconds']);
        self::assertTrue($pending['dataStale']);
        $storage = $this->storageOf($result, self::STORAGE);
        self::assertSame('2026-11-24T11:00:00+00:00', $storage['lastViewAt']);
        self::assertSame(90000, $storage['dataAgeSeconds']);
        self::assertTrue($storage['dataStale']);
    }

    public function testDataIsNotStaleAtExactlyTwentyFourHours(): void
    {
        $this->lastUpdate = self::YESTERDAY;
        $colony = $this->alphaColony($this->storagePin(self::STORAGE, null));

        $result = $this->calculator->calculate([$colony]);

        $storage = $this->storageOf($result, self::STORAGE);
        self::assertSame(86400, $storage['dataAgeSeconds']);
        self::assertFalse($storage['dataStale']);
    }

    public function testOnlySpaceportsAndStorageFacilitiesAreStoragePins(): void
    {
        $this->defineSchematic(65, [self::BACTERIA => 40], self::OXYGEN, 1, 3600);
        $colony = $this->alphaColony(
            $this->factoryPin(self::FACTORY, 65),
            $this->storagePin(self::STORAGE, null),
            $this->extractorPin(self::EXTRACTOR, self::BACTERIA, 1800, 4800),
            $this->launchpadPin(self::LAUNCHPAD_A, null),
            (new PlanetaryPin())->setPinId(self::COMMAND_CENTER)->setTypeId(self::COMMAND_CENTER_TYPE_ID),
        );

        $result = $this->calculator->calculate([$colony]);

        self::assertSame([self::LAUNCHPAD_A, self::STORAGE], array_column($result['storages'], 'pinId'));
        self::assertSame(['launchpad', 'storage'], array_column($result['storages'], 'kind'));
    }

    public function testStorageWithoutContentsHasNoLineAndAnEmptyVolume(): void
    {
        $colony = $this->alphaColony($this->storagePin(self::STORAGE, null));

        $result = $this->calculator->calculate([$colony]);

        $storage = $this->storageOf($result, self::STORAGE);
        self::assertSame([], $storage['contents']);
        $this->assertQuantity(12000, $storage, 'capacityM3');
        $this->assertQuantity(0, $storage, 'usedVolumeM3');
        self::assertSame([], $result['pendingOutputs']);
    }

    public function testStoragesAreSortedByPlanetNameThenPinIdAndContentsByTypeId(): void
    {
        $result = $this->calculator->calculate($this->sortingColonies());

        self::assertSame(
            [self::LAUNCHPAD_B, self::LAUNCHPAD_A, self::STORAGE],
            array_column($result['storages'], 'pinId'),
        );
        self::assertSame(
            [self::BACTERIA, self::REACTIVE_METALS],
            array_column($this->storageOf($result, self::STORAGE)['contents'], 'typeId'),
        );
    }

    public function testPendingOutputsAreSortedByTypeIdThenPlanetName(): void
    {
        $result = $this->calculator->calculate($this->sortingColonies());

        self::assertCount(3, $result['pendingOutputs']);
        $rows = array_map(
            static fn (array $row): array => [$row['typeId'], $row['planetName'], $row['quantity']],
            $result['pendingOutputs'],
        );
        self::assertEqualsWithDelta(
            [[self::BACTERIA, 'Aldranette II', 1000.0], [self::BACTERIA, 'Brinmoor V', 700.0], [self::REACTIVE_METALS, 'Brinmoor V', 620.0]],
            $rows,
            0.0001,
        );
    }

    public function testFactoryWithUnknownSchematicMakesTheResultIncompleteWithoutGuessingItsFlows(): void
    {
        $this->lastUpdate = self::YESTERDAY;
        $colony = $this->alphaColony(
            $this->storagePin(self::STORAGE, [['type_id' => self::BACTERIA, 'amount' => 500]]),
            $this->factoryPin(self::FACTORY, 999),
        );
        $this->route($colony, self::STORAGE, self::FACTORY, self::BACTERIA, 100.0);
        $this->route($colony, self::FACTORY, self::STORAGE, self::ROBOTICS, 100.0);

        $result = $this->calculator->calculate([$colony]);

        self::assertFalse($result['complete']);
        $storage = $this->storageOf($result, self::STORAGE);
        $this->assertQuantity(500, $this->contentOf($storage, self::BACTERIA), 'projectedStock');
        $this->assertQuantity(190, $storage, 'usedVolumeM3');
        self::assertSame([], $result['pendingOutputs']);
    }

    public function testFactoryWhoseSchematicHasNoCycleTimeMakesTheResultIncomplete(): void
    {
        $this->defineSchematic(70, [self::BACTERIA => 40], self::OXYGEN, 1, 0);
        $colony = $this->alphaColony(
            $this->storagePin(self::STORAGE, null),
            $this->factoryPin(self::FACTORY, 70),
        );

        $result = $this->calculator->calculate([$colony]);

        self::assertFalse($result['complete']);
    }

    public function testTypesMissingFromTheSdeKeepAnUnknownNameAndVolume(): void
    {
        $colony = $this->alphaColony(
            $this->launchpadPin(self::LAUNCHPAD_A, [
                ['type_id' => self::UNLISTED_COMMODITY, 'amount' => 30],
                ['type_id' => self::PROTEINS, 'amount' => 200],
                ['type_id' => self::BACTERIA, 'amount' => 100],
            ]),
        );

        $result = $this->calculator->calculate([$colony]);

        $storage = $this->storageOf($result, self::LAUNCHPAD_A);
        $this->assertNullField($storage, 'usedVolumeM3');
        $unlisted = $this->contentOf($storage, self::UNLISTED_COMMODITY);
        $this->assertNullField($unlisted, 'typeName');
        $this->assertNullField($unlisted, 'volumePerUnit');
        $this->assertQuantity(30, $unlisted, 'projectedStock');
        $proteins = $this->contentOf($storage, self::PROTEINS);
        self::assertSame('Proteins', $proteins['typeName']);
        $this->assertNullField($proteins, 'volumePerUnit');
        $this->assertQuantity(200, $proteins, 'projectedStock');
        $this->assertQuantity(0.38, $this->contentOf($storage, self::BACTERIA), 'volumePerUnit');
    }

    public function testPendingOutputWithoutVolumeKeepsItsQuantityAndAnUnknownVolume(): void
    {
        $colony = $this->alphaColony(
            $this->storagePin(self::STORAGE, [['type_id' => self::PROTEINS, 'amount' => 200]]),
            $this->extractorPin(self::EXTRACTOR, self::PROTEINS, 1800, 4800),
        );

        $result = $this->calculator->calculate([$colony]);

        $pending = $this->pendingOf($result, self::PROTEINS);
        $this->assertQuantity(200, $pending, 'quantity');
        $this->assertNullField($pending, 'volumePerUnit');
        $this->assertNullField($pending, 'volumeM3');
    }

    public function testTheSdeIsReadOnceForAllTheColonies(): void
    {
        $this->defineSchematic(65, [self::BACTERIA => 40], self::ROBOTICS, 1, 3600);
        $first = $this->alphaColony(
            $this->launchpadPin(self::LAUNCHPAD_A, [['type_id' => self::BACTERIA, 'amount' => 500]]),
            $this->factoryPin(self::FACTORY, 65),
        );
        $second = $this->colony(self::PLANET_BETA, 'Brinmoor V', $this->storagePin(self::STORAGE, [['type_id' => self::OXYGEN, 'amount' => 40]]));

        $result = $this->calculator->calculate([$first, $second]);

        self::assertCount(2, $result['storages']);
        self::assertSame(1, $this->sdeLookups);
    }

    private function sortingColonies(): array
    {
        $brinmoor = $this->colony(
            self::PLANET_BETA,
            'Brinmoor V',
            $this->storagePin(self::STORAGE, [
                ['type_id' => self::REACTIVE_METALS, 'amount' => 20],
                ['type_id' => self::BACTERIA, 'amount' => 500],
            ]),
            $this->launchpadPin(self::LAUNCHPAD_A, null),
            $this->extractorPin(self::EXTRACTOR_METALS, self::REACTIVE_METALS, 1800, 300),
            $this->extractorPin(self::EXTRACTOR, self::BACTERIA, 1800, 100),
        );
        $this->route($brinmoor, self::EXTRACTOR_METALS, self::STORAGE, self::REACTIVE_METALS, 100.0);
        $this->route($brinmoor, self::EXTRACTOR, self::STORAGE, self::BACTERIA, 100.0);

        $aldranette = $this->colony(
            self::PLANET_ALPHA,
            self::ALPHA_NAME,
            $this->launchpadPin(self::LAUNCHPAD_B, null),
            $this->extractorPin(self::EXTRACTOR, self::BACTERIA, 1800, 500),
        );
        $this->route($aldranette, self::EXTRACTOR, self::LAUNCHPAD_B, self::BACTERIA, 100.0);

        return [$brinmoor, $aldranette];
    }

    private function doubledQuantityExtractorYield(): ExtractorYieldCalculator
    {
        $yield = $this->createStub(ExtractorYieldCalculator::class);
        $yield->method('yieldBetween')->willReturnCallback(
            function (int $quantityPerCycle, int $cycleTime, ?\DateTimeImmutable $installTime, ?\DateTimeImmutable $expiryTime, \DateTimeImmutable $from, \DateTimeImmutable $to): ?float {
                $this->yieldCalls[] = [$quantityPerCycle, $cycleTime, $from->format('c'), $to->format('c')];

                return $this->extractorYieldUnknown ? null : $quantityPerCycle * 2.0;
            },
        );

        return $yield;
    }

    private function registerType(int $typeId, string $typeName, int $groupId, ?float $volume = null, ?float $capacity = null): void
    {
        $group = (new InvGroup())->setGroupId($groupId)->setGroupName('Group '.$groupId);
        $this->sdeTypes[$typeId] = (new InvType())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setGroup($group)
            ->setVolume($volume)
            ->setCapacity($capacity);
    }

    private function registerCommodity(int $typeId, string $typeName, ?float $volume): void
    {
        $this->registerType($typeId, $typeName, self::COMMODITIES_GROUP_ID, $volume);
    }

    private function defineSchematic(int $schematicId, array $inputs, int $outputTypeId, int $outputQuantity, int $cycleTime): void
    {
        $this->schematicMap[$schematicId] = [
            'inputs' => $inputs,
            'output' => ['typeId' => $outputTypeId, 'quantity' => $outputQuantity],
        ];
        $this->schematics[$schematicId] = (new PlanetSchematic())
            ->setSchematicId($schematicId)
            ->setSchematicName('Schematic '.$schematicId)
            ->setCycleTime($cycleTime);
    }

    private function alphaColony(PlanetaryPin ...$pins): PlanetaryColony
    {
        return $this->colony(self::PLANET_ALPHA, self::ALPHA_NAME, ...$pins);
    }

    private function colony(int $planetId, string $planetName, PlanetaryPin ...$pins): PlanetaryColony
    {
        $character = (new Character())
            ->setEveCharacterId(91_112_001)
            ->setName('Mira Voss');
        $colony = (new PlanetaryColony())
            ->setCharacter($character)
            ->setPlanetId($planetId)
            ->setPlanetName($planetName)
            ->setPlanetType('temperate')
            ->setSolarSystemName('Aldranette')
            ->setLastUpdate(new \DateTimeImmutable($this->lastUpdate));
        (new \ReflectionProperty(PlanetaryColony::class, 'id'))->setValue($colony, Uuid::v4());
        foreach ($pins as $pin) {
            $colony->addPin($pin);
        }

        return $colony;
    }

    private function factoryPin(int $pinId, int $schematicId): PlanetaryPin
    {
        return (new PlanetaryPin())
            ->setPinId($pinId)
            ->setTypeId(self::FACTORY_TYPE_ID)
            ->setSchematicId($schematicId);
    }

    private function extractorPin(int $pinId, int $productTypeId, ?int $cycleTime, ?int $quantityPerCycle): PlanetaryPin
    {
        return (new PlanetaryPin())
            ->setPinId($pinId)
            ->setTypeId(self::EXTRACTOR_TYPE_ID)
            ->setExtractorProductTypeId($productTypeId)
            ->setExtractorCycleTime($cycleTime)
            ->setExtractorQtyPerCycle($quantityPerCycle)
            ->setInstallTime(new \DateTimeImmutable(self::YESTERDAY))
            ->setExpiryTime(new \DateTimeImmutable(self::TOMORROW));
    }

    private function launchpadPin(int $pinId, ?array $contents): PlanetaryPin
    {
        return $this->storagePin($pinId, $contents, self::LAUNCHPAD_TYPE_ID);
    }

    private function storagePin(int $pinId, ?array $contents, int $typeId = self::STORAGE_FACILITY_TYPE_ID): PlanetaryPin
    {
        return (new PlanetaryPin())
            ->setPinId($pinId)
            ->setTypeId($typeId)
            ->setContents($contents);
    }

    private function route(PlanetaryColony $colony, int $sourcePinId, int $destinationPinId, int $contentTypeId, float $routeQuantity): void
    {
        $colony->addRoute(
            (new PlanetaryRoute())
                ->setRouteId($this->nextRouteId++)
                ->setSourcePinId($sourcePinId)
                ->setDestinationPinId($destinationPinId)
                ->setContentTypeId($contentTypeId)
                ->setQuantity($routeQuantity),
        );
    }

    private function storageOf(array $result, int $pinId): array
    {
        $byPin = array_column($result['storages'], null, 'pinId');
        self::assertArrayHasKey($pinId, $byPin);

        return $byPin[$pinId];
    }

    private function contentOf(array $storage, int $typeId): array
    {
        $byType = array_column($storage['contents'], null, 'typeId');
        self::assertArrayHasKey($typeId, $byType);

        return $byType[$typeId];
    }

    private function pendingOf(array $result, int $typeId): array
    {
        $byType = array_column($result['pendingOutputs'], null, 'typeId');
        self::assertArrayHasKey($typeId, $byType);

        return $byType[$typeId];
    }

    private function assertQuantity(int|float $expected, array $row, string $key): void
    {
        self::assertArrayHasKey($key, $row);
        self::assertIsNumeric($row[$key]);
        self::assertEqualsWithDelta($expected, $row[$key], 0.0001);
    }

    private function assertNullField(array $row, string $key): void
    {
        self::assertArrayHasKey($key, $row);
        self::assertNull($row[$key]);
    }
}
