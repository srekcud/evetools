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

#[Group('issue-1452')]
#[CoversClass(PlanetaryInputNeedsCalculator::class)]
final class PlanetaryInputNeedsCalculatorLaunchpadNetImportTest extends TestCase
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

    public function testIntermediateMadeOnTheColonyAndRoutedThroughALaunchpadIsNoLongerAFeed(): void
    {
        $this->defineSchematic(81, [self::BACTERIA => 100], self::OXYGEN, 40, 3600);
        $this->defineSchematic(82, [self::OXYGEN => 40], self::COOLANT, 5, 3600);
        $colony = $this->colony(
            self::PLANET_ALPHA,
            $this->launchpadPin(self::LAUNCHPAD_A, null),
            $this->factoryPin(self::FACTORY_UPSTREAM, 81),
            $this->factoryPin(self::FACTORY, 82),
        );
        $this->route($colony, self::LAUNCHPAD_A, self::FACTORY_UPSTREAM, self::BACTERIA, 100.0);
        $this->route($colony, self::LAUNCHPAD_A, self::FACTORY, self::OXYGEN, 40.0);

        $result = $this->calculator->calculate([$colony]);

        $launchpad = $this->launchpadOf($result, self::LAUNCHPAD_A);
        self::assertSame([self::BACTERIA], array_column($launchpad['feeds'], 'typeId'));
        $this->assertQuantity(2400, $this->feedOf($launchpad, self::BACTERIA), 'dailyRate');
        $this->assertQuantity(912, $launchpad, 'dailyVolumeM3');
    }

    public function testFeedRatesOfATypeAreScaledDownToTheNetImportAcrossLaunchpads(): void
    {
        $this->defineSchematic(81, [self::BACTERIA => 100], self::OXYGEN, 20, 3600);
        $this->defineSchematic(82, [self::OXYGEN => 40], self::COOLANT, 5, 3600);
        $colony = $this->colony(
            self::PLANET_ALPHA,
            $this->launchpadPin(self::LAUNCHPAD_A, null),
            $this->launchpadPin(self::LAUNCHPAD_B, null),
            $this->factoryPin(self::FACTORY_UPSTREAM, 81),
            $this->factoryPin(self::FACTORY, 82),
        );
        $this->route($colony, self::LAUNCHPAD_A, self::FACTORY, self::OXYGEN, 1800.0);
        $this->route($colony, self::LAUNCHPAD_B, self::FACTORY, self::OXYGEN, 600.0);

        $result = $this->calculator->calculate([$colony]);

        $this->assertQuantity(360, $this->feedOf($this->launchpadOf($result, self::LAUNCHPAD_A), self::OXYGEN), 'dailyRate');
        $this->assertQuantity(120, $this->feedOf($this->launchpadOf($result, self::LAUNCHPAD_B), self::OXYGEN), 'dailyRate');
        $this->assertQuantity(480, $this->needOf($this->colonyOf($result, self::PLANET_ALPHA), self::OXYGEN), 'dailyNetImport');
    }

    public function testFeedRatesKeepTheirValueWhenNothingIsMadeOnTheColony(): void
    {
        $this->defineSchematic(82, [self::OXYGEN => 40], self::COOLANT, 5, 3600);
        $colony = $this->colony(
            self::PLANET_ALPHA,
            $this->launchpadPin(self::LAUNCHPAD_A, null),
            $this->factoryPin(self::FACTORY, 82),
        );
        $this->route($colony, self::LAUNCHPAD_A, self::FACTORY, self::OXYGEN, 40.0);

        $result = $this->calculator->calculate([$colony]);

        $this->assertQuantity(960, $this->feedOf($this->launchpadOf($result, self::LAUNCHPAD_A), self::OXYGEN), 'dailyRate');
    }

    public function testFeedRatesKeepTheirValueWhenTheExtractionOfTheTypeIsUnknown(): void
    {
        $this->defineSchematic(81, [self::BACTERIA => 100], self::OXYGEN, 20, 3600);
        $this->defineSchematic(82, [self::OXYGEN => 40], self::COOLANT, 5, 3600);
        $colony = $this->colony(
            self::PLANET_ALPHA,
            $this->launchpadPin(self::LAUNCHPAD_A, null),
            $this->factoryPin(self::FACTORY_UPSTREAM, 81),
            $this->factoryPin(self::FACTORY, 82),
            $this->extractorPin(self::EXTRACTOR, self::OXYGEN, 1800, 4400, null),
        );
        $this->route($colony, self::LAUNCHPAD_A, self::FACTORY, self::OXYGEN, 40.0);

        $result = $this->calculator->calculate([$colony]);

        $this->assertQuantity(960, $this->feedOf($this->launchpadOf($result, self::LAUNCHPAD_A), self::OXYGEN), 'dailyRate');
    }

    private function extractionCalculator(): PlanetaryInputNeedsCalculator
    {
        return new PlanetaryInputNeedsCalculator(
            $this->schematicRepository,
            $this->schematicTypeRepository,
            $this->invTypeRepository,
            $this->typeNameResolver,
            new MockClock(self::NOW),
            new ExtractorYieldCalculator($this->defaultFactorsRepository()),
        );
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

    private function extractorPin(int $pinId, int $productTypeId, ?int $cycleTime, ?int $quantityPerCycle, ?string $expiryTime, ?string $installTime = null): PlanetaryPin
    {
        return (new PlanetaryPin())
            ->setPinId($pinId)
            ->setTypeId(self::EXTRACTOR_TYPE_ID)
            ->setExtractorProductTypeId($productTypeId)
            ->setExtractorCycleTime($cycleTime)
            ->setExtractorQtyPerCycle($quantityPerCycle)
            ->setInstallTime($installTime !== null ? new \DateTimeImmutable($installTime) : ($expiryTime === null ? null : (new \DateTimeImmutable($expiryTime))->modify('-24 hours')))
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
