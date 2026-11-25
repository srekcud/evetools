<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\PlanetaryRoute;
use App\Entity\Sde\DgmAttributeType;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\PlanetSchematic;
use App\Repository\Sde\DgmAttributeTypeRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\PlanetSchematicRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Planetary\ExtractorYieldCalculator;
use App\Service\Planetary\PlanetaryInputNeedsCalculator;
use App\Service\TypeNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[Group('issue-1380')]
#[CoversClass(PlanetaryInputNeedsCalculator::class)]
final class PlanetaryInputNeedsCalculatorTest extends TestCase
{
    private const string NOW = '2026-11-25 12:00:00';
    private const string YESTERDAY = '2026-11-24 12:00:00';
    private const string TOMORROW = '2026-11-26 12:00:00';

    private const int SPACEPORTS_GROUP_ID = 1030;
    private const int STORAGE_FACILITIES_GROUP_ID = 1029;
    private const int COMMODITIES_GROUP_ID = 1042;
    private const int PROCESSORS_GROUP_ID = 1028;
    private const int EXTRACTOR_CONTROL_UNITS_GROUP_ID = 1063;

    private const int LAUNCHPAD_TYPE_ID = 2256;
    private const int STORAGE_FACILITY_TYPE_ID = 2257;
    private const int FACTORY_TYPE_ID = 2473;
    private const int EXTRACTOR_TYPE_ID = 2848;

    private const int BACTERIA = 2393;
    private const int REACTIVE_METALS = 2398;
    private const int PROTEINS = 2395;
    private const int OXYGEN = 3683;
    private const int COOLANT = 9832;
    private const int ROBOTICS = 9848;
    private const int NANITES = 2867;
    private const int TRANSCRANIAL = 2868;
    private const int CONDENSATES = 2869;
    private const int VIRAL_AGENTS = 2876;
    private const int UNLISTED_COMMODITY = 3999;

    private const int LAUNCHPAD_A = 1_029_384_756_101;
    private const int LAUNCHPAD_B = 1_029_384_756_102;
    private const int STORAGE = 1_029_384_756_103;
    private const int FACTORY = 1_029_384_756_201;
    private const int FACTORY_UPSTREAM = 1_029_384_756_202;
    private const int EXTRACTOR = 1_029_384_756_301;

    private const int PLANET_ALPHA = 40_112_001;
    private const int PLANET_BETA = 40_112_002;

    private PlanetSchematicRepository $schematicRepository;
    private PlanetSchematicTypeRepository $schematicTypeRepository;
    private InvTypeRepository $invTypeRepository;
    private TypeNameResolver $typeNameResolver;
    private PlanetaryInputNeedsCalculator $calculator;

    /** @var array<int, InvType> */
    private array $sdeTypes = [];

    /** @var array<int, array{inputs: array<int, int>, output: array{typeId: int, quantity: int}|null}> */
    private array $schematicMap = [];

    /** @var array<int, PlanetSchematic> */
    private array $schematics = [];

    private int $nextRouteId = 1;

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
        $this->invTypeRepository->method('findByTypeIds')->willReturnCallback(
            fn (array $typeIds): array => array_intersect_key($this->sdeTypes, array_flip($typeIds)),
        );
        $this->invTypeRepository->method('find')->willReturnCallback(fn (mixed $typeId): ?InvType => $this->sdeTypes[$typeId] ?? null);
        $this->invTypeRepository->method('findByTypeId')->willReturnCallback(fn (int $typeId): ?InvType => $this->sdeTypes[$typeId] ?? null);
        $this->typeNameResolver->method('findName')->willReturnCallback(
            fn (int $typeId): ?string => isset($this->sdeTypes[$typeId]) ? $this->sdeTypes[$typeId]->getTypeName() : null,
        );

        $this->calculator = new PlanetaryInputNeedsCalculator(
            $this->schematicRepository,
            $this->schematicTypeRepository,
            $this->invTypeRepository,
            $this->typeNameResolver,
            new MockClock(self::NOW),
            $this->constantRateExtractorYield(),
        );

        $this->registerType(self::LAUNCHPAD_TYPE_ID, 'Launchpad', self::SPACEPORTS_GROUP_ID, null, 10000.0);
        $this->registerType(self::STORAGE_FACILITY_TYPE_ID, 'Storage Facility', self::STORAGE_FACILITIES_GROUP_ID, null, 12000.0);
        $this->registerType(self::FACTORY_TYPE_ID, 'Basic Industry Facility', self::PROCESSORS_GROUP_ID);
        $this->registerType(self::EXTRACTOR_TYPE_ID, 'Extractor Control Unit', self::EXTRACTOR_CONTROL_UNITS_GROUP_ID);
        $this->registerCommodity(self::BACTERIA, 'Bacteria', 0.38);
        $this->registerCommodity(self::REACTIVE_METALS, 'Reactive Metals', 0.52);
        $this->registerCommodity(self::PROTEINS, 'Proteins', null);
        $this->registerCommodity(self::OXYGEN, 'Oxygen', 1.5);
        $this->registerCommodity(self::COOLANT, 'Coolant', 1.75);
        $this->registerCommodity(self::ROBOTICS, 'Robotics', 6.0);
        $this->registerCommodity(self::NANITES, 'Nanites', 6.25);
        $this->registerCommodity(self::TRANSCRANIAL, 'Transcranial Microcontrollers', 6.5);
        $this->registerCommodity(self::CONDENSATES, 'Condensates', 6.75);
        $this->registerCommodity(self::VIRAL_AGENTS, 'Viral Agent', 50.0);
    }

    public function testFactoryNeedsItsSchematicInputQuantityPerDayAtFullRate(): void
    {
        $this->defineSchematic(65, [self::BACTERIA => 40], self::OXYGEN, 1, 3600);
        $colony = $this->colony(self::PLANET_ALPHA, $this->factoryPin(self::FACTORY, 65));

        $result = $this->calculator->calculate([$colony]);

        self::assertTrue($result['complete']);
        self::assertCount(1, $result['colonies']);
        $alpha = $this->colonyOf($result, self::PLANET_ALPHA);
        self::assertTrue($alpha['complete']);
        self::assertCount(1, $alpha['needs']);
        $bacteria = $this->needOf($alpha, self::BACTERIA);
        self::assertSame('Bacteria', $bacteria['typeName']);
        $this->assertQuantity(960, $bacteria, 'dailyRequired');
        $this->assertQuantity(0, $bacteria, 'dailyProducedLocally');
        $this->assertQuantity(960, $bacteria, 'dailyNetImport');
        $this->assertQuantity(0.38, $bacteria, 'volumePerUnit');
    }

    public function testColonyEntryCarriesThePlanetAndTheCharacterIdentity(): void
    {
        $this->defineSchematic(65, [self::BACTERIA => 40], self::OXYGEN, 1, 3600);
        $colony = $this->colony(self::PLANET_ALPHA, $this->factoryPin(self::FACTORY, 65));

        $result = $this->calculator->calculate([$colony]);

        $alpha = $this->colonyOf($result, self::PLANET_ALPHA);
        self::assertSame($colony->getId()?->toRfc4122(), $alpha['colonyId']);
        self::assertSame('Aldranette II', $alpha['planetName']);
        self::assertSame('temperate', $alpha['planetType']);
        self::assertSame('Aldranette', $alpha['solarSystemName']);
        self::assertSame(91_112_001, $alpha['characterId']);
        self::assertSame('Mira Voss', $alpha['characterName']);
    }

    public function testPlanetaryTierFourFactoryNeedsEachInputQuantityPerDayAtFullRate(): void
    {
        $this->defineSchematic(133, [self::CONDENSATES => 6, self::NANITES => 6, self::TRANSCRANIAL => 6], self::VIRAL_AGENTS, 1, 3600);
        $colony = $this->colony(self::PLANET_ALPHA, $this->factoryPin(self::FACTORY, 133));

        $result = $this->calculator->calculate([$colony]);

        $needs = $this->colonyOf($result, self::PLANET_ALPHA)['needs'];
        self::assertSame([self::NANITES, self::TRANSCRANIAL, self::CONDENSATES], array_column($needs, 'typeId'));
        self::assertEqualsWithDelta(
            [self::NANITES => 144.0, self::TRANSCRANIAL => 144.0, self::CONDENSATES => 144.0],
            array_column($needs, 'dailyRequired', 'typeId'),
            0.0001,
        );
    }

    public function testActiveExtractorCoveringTheFactoryLeavesNoNetImport(): void
    {
        $this->defineSchematic(65, [self::BACTERIA => 40], self::OXYGEN, 1, 3600);
        $colony = $this->colony(
            self::PLANET_ALPHA,
            $this->factoryPin(self::FACTORY, 65),
            $this->extractorPin(self::EXTRACTOR, self::BACTERIA, 1800, 4400, self::TOMORROW),
        );

        $result = $this->calculator->calculate([$colony]);

        $bacteria = $this->needOf($this->colonyOf($result, self::PLANET_ALPHA), self::BACTERIA);
        $this->assertQuantity(960, $bacteria, 'dailyRequired');
        $this->assertQuantity(211200, $bacteria, 'dailyProducedLocally');
        $this->assertQuantity(0, $bacteria, 'dailyNetImport');
    }

    public function testExpiredExtractorProducesNothingAndTheFactoryStillNeedsItsFullInput(): void
    {
        $this->defineSchematic(65, [self::BACTERIA => 40], self::OXYGEN, 1, 3600);
        $colony = $this->colony(
            self::PLANET_ALPHA,
            $this->factoryPin(self::FACTORY, 65),
            $this->extractorPin(self::EXTRACTOR, self::BACTERIA, 1800, 4400, self::YESTERDAY),
        );

        $result = $this->calculator->calculate([$colony]);

        $bacteria = $this->needOf($this->colonyOf($result, self::PLANET_ALPHA), self::BACTERIA);
        $this->assertQuantity(0, $bacteria, 'dailyProducedLocally');
        $this->assertQuantity(960, $bacteria, 'dailyNetImport');
    }

    public function testExtractorWithoutExpiryTimeLeavesProducedLocallyAndNetImportUnknown(): void
    {
        $this->defineSchematic(65, [self::BACTERIA => 40], self::OXYGEN, 1, 3600);
        $colony = $this->colony(
            self::PLANET_ALPHA,
            $this->factoryPin(self::FACTORY, 65),
            $this->extractorPin(self::EXTRACTOR, self::BACTERIA, 1800, 4400, null),
        );

        $result = $this->calculator->calculate([$colony]);

        $bacteria = $this->needOf($this->colonyOf($result, self::PLANET_ALPHA), self::BACTERIA);
        $this->assertNullField($bacteria, 'dailyProducedLocally');
        $this->assertNullField($bacteria, 'dailyNetImport');
    }

    public function testExtractorProducesTheDecayingProgramAverageOfTheCcpFormula(): void
    {
        $this->defineSchematic(65, [self::BACTERIA => 40], self::OXYGEN, 1, 3600);
        $colony = $this->colony(
            self::PLANET_ALPHA,
            $this->factoryPin(self::FACTORY, 65),
            $this->extractorPin(self::EXTRACTOR, self::BACTERIA, 1800, 4400, self::TOMORROW),
        );
        $calculator = new PlanetaryInputNeedsCalculator(
            $this->schematicRepository,
            $this->schematicTypeRepository,
            $this->invTypeRepository,
            $this->typeNameResolver,
            new MockClock(self::NOW),
            new ExtractorYieldCalculator($this->defaultFactorsRepository()),
        );

        $result = $calculator->calculate([$colony]);

        $bacteria = $this->needOf($this->colonyOf($result, self::PLANET_ALPHA), self::BACTERIA);
        $this->assertQuantity(313874.1190760704, $bacteria, 'dailyProducedLocally');
        $this->assertQuantity(0, $bacteria, 'dailyNetImport');
        $this->assertQuantity(2197118.8335, $this->weeklyOf($result, self::BACTERIA), 'weeklyExtraction');
    }

    public function testFactoryOutputFeedingAnotherFactoryOfTheColonyIsProducedLocally(): void
    {
        $colony = $this->chainColony();

        $result = $this->calculator->calculate([$colony]);

        $alpha = $this->colonyOf($result, self::PLANET_ALPHA);
        $oxygen = $this->needOf($alpha, self::OXYGEN);
        $this->assertQuantity(960, $oxygen, 'dailyRequired');
        $this->assertQuantity(480, $oxygen, 'dailyProducedLocally');
        $this->assertQuantity(480, $oxygen, 'dailyNetImport');
        $bacteria = $this->needOf($alpha, self::BACTERIA);
        $this->assertQuantity(2400, $bacteria, 'dailyRequired');
        $this->assertQuantity(0, $bacteria, 'dailyProducedLocally');
        $this->assertQuantity(2400, $bacteria, 'dailyNetImport');
    }

    public function testExtractorWithoutCycleTimeLeavesProducedLocallyAndNetImportUnknown(): void
    {
        $this->defineSchematic(65, [self::BACTERIA => 40], self::OXYGEN, 1, 3600);
        $colony = $this->colony(
            self::PLANET_ALPHA,
            $this->factoryPin(self::FACTORY, 65),
            $this->extractorPin(self::EXTRACTOR, self::BACTERIA, null, 4400, self::TOMORROW),
        );

        $result = $this->calculator->calculate([$colony]);

        $bacteria = $this->needOf($this->colonyOf($result, self::PLANET_ALPHA), self::BACTERIA);
        $this->assertQuantity(960, $bacteria, 'dailyRequired');
        $this->assertNullField($bacteria, 'dailyProducedLocally');
        $this->assertNullField($bacteria, 'dailyNetImport');
    }

    public function testExtractorWithoutQuantityPerCycleLeavesProducedLocallyAndNetImportUnknown(): void
    {
        $this->defineSchematic(65, [self::BACTERIA => 40], self::OXYGEN, 1, 3600);
        $colony = $this->colony(
            self::PLANET_ALPHA,
            $this->factoryPin(self::FACTORY, 65),
            $this->extractorPin(self::EXTRACTOR, self::BACTERIA, 1800, null, self::TOMORROW),
        );

        $result = $this->calculator->calculate([$colony]);

        $bacteria = $this->needOf($this->colonyOf($result, self::PLANET_ALPHA), self::BACTERIA);
        $this->assertNullField($bacteria, 'dailyProducedLocally');
        $this->assertNullField($bacteria, 'dailyNetImport');
    }

    public function testFactoryWithUnknownSchematicMakesTheColonyAndTheResultIncompleteWithoutGuessingInputs(): void
    {
        $this->defineSchematic(65, [self::BACTERIA => 40], self::OXYGEN, 1, 3600);
        $healthy = $this->colony(self::PLANET_ALPHA, $this->factoryPin(self::FACTORY, 65));
        $unknown = $this->colony(self::PLANET_BETA, $this->factoryPin(self::FACTORY_UPSTREAM, 999));

        $result = $this->calculator->calculate([$healthy, $unknown]);

        self::assertFalse($result['complete']);
        self::assertTrue($this->colonyOf($result, self::PLANET_ALPHA)['complete']);
        $beta = $this->colonyOf($result, self::PLANET_BETA);
        self::assertFalse($beta['complete']);
        self::assertSame([], $beta['needs']);
    }

    public function testFactoryWhoseSchematicHasNoCycleTimeMakesTheColonyIncomplete(): void
    {
        $this->defineSchematic(65, [self::BACTERIA => 40], self::OXYGEN, 1, 0);
        $colony = $this->colony(self::PLANET_ALPHA, $this->factoryPin(self::FACTORY, 65));

        $result = $this->calculator->calculate([$colony]);

        self::assertFalse($result['complete']);
        $alpha = $this->colonyOf($result, self::PLANET_ALPHA);
        self::assertFalse($alpha['complete']);
        self::assertSame([], $alpha['needs']);
    }

    public function testInputTypeMissingFromTheSdeKeepsAnUnknownNameAndVolume(): void
    {
        $this->defineSchematic(65, [self::UNLISTED_COMMODITY => 40], self::OXYGEN, 1, 3600);
        $colony = $this->colony(self::PLANET_ALPHA, $this->factoryPin(self::FACTORY, 65));

        $result = $this->calculator->calculate([$colony]);

        $unlisted = $this->needOf($this->colonyOf($result, self::PLANET_ALPHA), self::UNLISTED_COMMODITY);
        $this->assertQuantity(960, $unlisted, 'dailyRequired');
        $this->assertNullField($unlisted, 'typeName');
        $this->assertNullField($unlisted, 'volumePerUnit');
    }

    public function testTwoRoutesToTheSameFactorySplitItsDailyNeedByRouteQuantity(): void
    {
        $this->defineSchematic(65, [self::BACTERIA => 40], self::OXYGEN, 1, 3600);
        $colony = $this->colony(
            self::PLANET_ALPHA,
            $this->launchpadPin(self::LAUNCHPAD_A, [['type_id' => self::BACTERIA, 'amount' => 500]]),
            $this->launchpadPin(self::LAUNCHPAD_B, null),
            $this->factoryPin(self::FACTORY, 65),
        );
        $this->route($colony, self::LAUNCHPAD_A, self::FACTORY, self::BACTERIA, 1800.0);
        $this->route($colony, self::LAUNCHPAD_B, self::FACTORY, self::BACTERIA, 600.0);

        $result = $this->calculator->calculate([$colony]);

        $feedA = $this->feedOf($this->launchpadOf($result, self::LAUNCHPAD_A), self::BACTERIA);
        $feedB = $this->feedOf($this->launchpadOf($result, self::LAUNCHPAD_B), self::BACTERIA);
        $this->assertQuantity(720, $feedA, 'dailyRate');
        $this->assertQuantity(500, $feedA, 'stock');
        $this->assertQuantity(240, $feedB, 'dailyRate');
        $this->assertQuantity(0, $feedB, 'stock');
    }

    public function testLaunchpadStockAndVolumesAreReadFromItsContentsAndTheSde(): void
    {
        $this->defineSchematic(65, [self::BACTERIA => 40], self::OXYGEN, 1, 3600);
        $colony = $this->colony(
            self::PLANET_ALPHA,
            $this->launchpadPin(self::LAUNCHPAD_A, [
                ['type_id' => self::BACTERIA, 'amount' => 500],
                ['type_id' => self::REACTIVE_METALS, 'amount' => 20],
            ]),
            $this->factoryPin(self::FACTORY, 65),
        );
        $this->route($colony, self::LAUNCHPAD_A, self::FACTORY, self::BACTERIA, 1800.0);

        $result = $this->calculator->calculate([$colony]);

        self::assertCount(1, $result['launchpads']);
        $launchpad = $this->launchpadOf($result, self::LAUNCHPAD_A);
        self::assertSame($colony->getId()?->toRfc4122(), $launchpad['colonyId']);
        self::assertSame(self::PLANET_ALPHA, $launchpad['planetId']);
        self::assertSame('Aldranette II', $launchpad['planetName']);
        self::assertSame(self::LAUNCHPAD_TYPE_ID, $launchpad['typeId']);
        self::assertSame('Launchpad', $launchpad['typeName']);
        $this->assertQuantity(10000, $launchpad, 'capacityM3');
        $this->assertQuantity(200.4, $launchpad, 'usedVolumeM3');
        $this->assertQuantity(364.8, $launchpad, 'dailyVolumeM3');
        self::assertCount(1, $launchpad['feeds']);
        $feed = $this->feedOf($launchpad, self::BACTERIA);
        self::assertSame('Bacteria', $feed['typeName']);
        $this->assertQuantity(960, $feed, 'dailyRate');
        $this->assertQuantity(500, $feed, 'stock');
        $this->assertQuantity(0.38, $feed, 'volumePerUnit');
    }

    public function testLaunchpadWithoutRouteIsListedEvenOnAColonyWithoutFactory(): void
    {
        $colony = $this->colony(self::PLANET_ALPHA, $this->launchpadPin(self::LAUNCHPAD_A, null));

        $result = $this->calculator->calculate([$colony]);

        self::assertSame([], $result['colonies']);
        self::assertCount(1, $result['launchpads']);
        $launchpad = $this->launchpadOf($result, self::LAUNCHPAD_A);
        self::assertSame([], $launchpad['feeds']);
        $this->assertQuantity(0, $launchpad, 'usedVolumeM3');
        $this->assertQuantity(0, $launchpad, 'dailyVolumeM3');
    }

    public function testStorageFacilityIsNotALaunchpadAndARouteToItFeedsNothing(): void
    {
        $this->defineSchematic(65, [self::BACTERIA => 40], self::OXYGEN, 1, 3600);
        $colony = $this->colony(
            self::PLANET_ALPHA,
            $this->launchpadPin(self::LAUNCHPAD_A, null),
            $this->storagePin(self::STORAGE),
            $this->factoryPin(self::FACTORY, 65),
        );
        $this->route($colony, self::LAUNCHPAD_A, self::STORAGE, self::BACTERIA, 1800.0);

        $result = $this->calculator->calculate([$colony]);

        self::assertSame([self::LAUNCHPAD_A], array_column($result['launchpads'], 'pinId'));
        self::assertSame([], $this->launchpadOf($result, self::LAUNCHPAD_A)['feeds']);
    }

    public function testLaunchpadVolumesAreUnknownWhenTheSdeLacksCapacityOrVolume(): void
    {
        $this->defineSchematic(70, [self::PROTEINS => 40], self::OXYGEN, 1, 3600);
        $this->registerType(self::LAUNCHPAD_TYPE_ID, 'Launchpad', self::SPACEPORTS_GROUP_ID, null, null);
        $colony = $this->colony(
            self::PLANET_ALPHA,
            $this->launchpadPin(self::LAUNCHPAD_A, [['type_id' => self::PROTEINS, 'amount' => 30]]),
            $this->factoryPin(self::FACTORY, 70),
        );
        $this->route($colony, self::LAUNCHPAD_A, self::FACTORY, self::PROTEINS, 900.0);

        $result = $this->calculator->calculate([$colony]);

        $launchpad = $this->launchpadOf($result, self::LAUNCHPAD_A);
        $this->assertNullField($launchpad, 'capacityM3');
        $this->assertNullField($launchpad, 'usedVolumeM3');
        $this->assertNullField($launchpad, 'dailyVolumeM3');
        $feed = $this->feedOf($launchpad, self::PROTEINS);
        $this->assertQuantity(960, $feed, 'dailyRate');
        $this->assertQuantity(30, $feed, 'stock');
        $this->assertNullField($feed, 'volumePerUnit');
    }

    public function testWeeklyFiguresSumEveryColonyOfTheUserAndExtractionCoversTheConsumption(): void
    {
        $this->defineSchematic(65, [self::BACTERIA => 40], self::OXYGEN, 1, 3600);
        $alpha = $this->colony(
            self::PLANET_ALPHA,
            $this->factoryPin(self::FACTORY, 65),
            $this->extractorPin(self::EXTRACTOR, self::BACTERIA, 1800, 4400, self::TOMORROW),
        );
        $beta = $this->colony(self::PLANET_BETA, $this->factoryPin(self::FACTORY_UPSTREAM, 65));

        $result = $this->calculator->calculate([$alpha, $beta]);

        self::assertCount(1, $result['weekly']);
        $bacteria = $this->weeklyOf($result, self::BACTERIA);
        self::assertSame('Bacteria', $bacteria['typeName']);
        $this->assertQuantity(13440, $bacteria, 'weeklyConsumption');
        $this->assertQuantity(1478400, $bacteria, 'weeklyExtraction');
        $this->assertQuantity(0, $bacteria, 'weeklyFactoryOutput');
        $this->assertQuantity(0, $bacteria, 'weeklyNetNeed');
    }

    public function testWeeklyNetNeedSubtractsTheOutputOfTheFactoriesOfTheUser(): void
    {
        $result = $this->calculator->calculate([$this->chainColony()]);

        self::assertSame([self::BACTERIA, self::OXYGEN], array_column($result['weekly'], 'typeId'));
        $oxygen = $this->weeklyOf($result, self::OXYGEN);
        $this->assertQuantity(6720, $oxygen, 'weeklyConsumption');
        $this->assertQuantity(0, $oxygen, 'weeklyExtraction');
        $this->assertQuantity(3360, $oxygen, 'weeklyFactoryOutput');
        $this->assertQuantity(3360, $oxygen, 'weeklyNetNeed');
    }

    public function testWeeklyExtractionAndNetNeedAreUnknownWhenAnExtractorOfTheTypeIsUnknown(): void
    {
        $this->defineSchematic(65, [self::BACTERIA => 40], self::OXYGEN, 1, 3600);
        $colony = $this->colony(
            self::PLANET_ALPHA,
            $this->factoryPin(self::FACTORY, 65),
            $this->extractorPin(self::EXTRACTOR, self::BACTERIA, null, 4400, self::TOMORROW),
        );

        $result = $this->calculator->calculate([$colony]);

        $bacteria = $this->weeklyOf($result, self::BACTERIA);
        $this->assertQuantity(6720, $bacteria, 'weeklyConsumption');
        $this->assertNullField($bacteria, 'weeklyExtraction');
        $this->assertNullField($bacteria, 'weeklyNetNeed');
    }

    private function constantRateExtractorYield(): ExtractorYieldCalculator
    {
        $yield = $this->createStub(ExtractorYieldCalculator::class);
        $yield->method('programDailyAverage')->willReturnCallback(
            static fn (int $quantityPerCycle, int $cycleTime, ?\DateTimeImmutable $installTime, ?\DateTimeImmutable $expiryTime): ?float => $expiryTime === null
                ? null
                : $quantityPerCycle * (86400 / $cycleTime),
        );

        return $yield;
    }

    private function defaultFactorsRepository(): DgmAttributeTypeRepository
    {
        $repository = $this->createStub(DgmAttributeTypeRepository::class);
        $repository->method('find')->willReturnCallback(
            static fn (mixed $attributeId): ?DgmAttributeType => match ($attributeId) {
                1683 => (new DgmAttributeType())->setAttributeId(1683)->setDefaultValue(0.012),
                1687 => (new DgmAttributeType())->setAttributeId(1687)->setDefaultValue(0.8),
                default => null,
            },
        );

        return $repository;
    }

    private function chainColony(): PlanetaryColony
    {
        $this->defineSchematic(81, [self::BACTERIA => 100], self::OXYGEN, 20, 3600);
        $this->defineSchematic(82, [self::OXYGEN => 40], self::COOLANT, 5, 3600);

        return $this->colony(
            self::PLANET_ALPHA,
            $this->factoryPin(self::FACTORY_UPSTREAM, 81),
            $this->factoryPin(self::FACTORY, 82),
        );
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

    /**
     * @param array<int, int> $inputs
     */
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

    private function colony(int $planetId, PlanetaryPin ...$pins): PlanetaryColony
    {
        $character = (new Character())
            ->setEveCharacterId(91_112_001)
            ->setName('Mira Voss');
        $colony = (new PlanetaryColony())
            ->setCharacter($character)
            ->setPlanetId($planetId)
            ->setPlanetName('Aldranette II')
            ->setPlanetType('temperate')
            ->setSolarSystemName('Aldranette');
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

    private function extractorPin(int $pinId, int $productTypeId, ?int $cycleTime, ?int $quantityPerCycle, ?string $expiryTime): PlanetaryPin
    {
        return (new PlanetaryPin())
            ->setPinId($pinId)
            ->setTypeId(self::EXTRACTOR_TYPE_ID)
            ->setExtractorProductTypeId($productTypeId)
            ->setExtractorCycleTime($cycleTime)
            ->setExtractorQtyPerCycle($quantityPerCycle)
            ->setInstallTime($expiryTime === null ? null : (new \DateTimeImmutable($expiryTime))->modify('-24 hours'))
            ->setExpiryTime($expiryTime === null ? null : new \DateTimeImmutable($expiryTime));
    }

    /**
     * @param array<int, array{type_id: int, amount: int}>|null $contents
     */
    private function launchpadPin(int $pinId, ?array $contents): PlanetaryPin
    {
        return (new PlanetaryPin())
            ->setPinId($pinId)
            ->setTypeId(self::LAUNCHPAD_TYPE_ID)
            ->setContents($contents);
    }

    private function storagePin(int $pinId): PlanetaryPin
    {
        return (new PlanetaryPin())
            ->setPinId($pinId)
            ->setTypeId(self::STORAGE_FACILITY_TYPE_ID);
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

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function colonyOf(array $result, int $planetId): array
    {
        $byPlanet = array_column($result['colonies'], null, 'planetId');
        self::assertArrayHasKey($planetId, $byPlanet);

        return $byPlanet[$planetId];
    }

    /**
     * @param array<string, mixed> $colony
     * @return array<string, mixed>
     */
    private function needOf(array $colony, int $typeId): array
    {
        $byType = array_column($colony['needs'], null, 'typeId');
        self::assertArrayHasKey($typeId, $byType);

        return $byType[$typeId];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function launchpadOf(array $result, int $pinId): array
    {
        $byPin = array_column($result['launchpads'], null, 'pinId');
        self::assertArrayHasKey($pinId, $byPin);

        return $byPin[$pinId];
    }

    /**
     * @param array<string, mixed> $launchpad
     * @return array<string, mixed>
     */
    private function feedOf(array $launchpad, int $typeId): array
    {
        $byType = array_column($launchpad['feeds'], null, 'typeId');
        self::assertArrayHasKey($typeId, $byType);

        return $byType[$typeId];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function weeklyOf(array $result, int $typeId): array
    {
        $byType = array_column($result['weekly'], null, 'typeId');
        self::assertArrayHasKey($typeId, $byType);

        return $byType[$typeId];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertQuantity(int|float $expected, array $row, string $key): void
    {
        self::assertArrayHasKey($key, $row);
        self::assertIsNumeric($row[$key]);
        self::assertEqualsWithDelta($expected, $row[$key], 0.0001);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertNullField(array $row, string $key): void
    {
        self::assertArrayHasKey($key, $row);
        self::assertNull($row[$key]);
    }
}
