<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Planetary;

use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\Sde\InvMarketGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\PlanetSchematic;
use App\Entity\Sde\PlanetSchematicType;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\Sde\DgmAttributeTypeRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\Sde\PlanetSchematicRepository;
use App\Repository\UserNotificationPreferenceRepository;
use App\Service\Planetary\ExtractorYieldCalculator;
use App\Service\Planetary\PlanetaryExpiryThresholdResolver;
use App\Service\Planetary\PlanetaryFactoryColonyTracker;
use App\Service\Planetary\PlanetaryPowerCalculator;
use App\Service\Planetary\PlanetaryProductionCalculator;
use App\Service\TypeNameResolver;
use App\State\Provider\Planetary\PlanetaryResourceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryResourceMapper::class)]
#[Group('issue-1383')]
final class PlanetaryResourceMapperTierAndStorageTest extends TestCase
{
    private const int MARKET_GROUP_P1 = 1334;
    private const int MARKET_GROUP_P2 = 1335;
    private const int MARKET_GROUP_P4 = 1337;

    private const int BACTERIA = 2393;
    private const int NANITES = 2463;
    private const int BROADCAST_NODE = 2867;
    private const int MICROORGANISMS = 2073;
    private const int UNKNOWN_TO_THE_SDE = 2999;
    private const int BASIC_INDUSTRY_FACILITY = 2473;
    private const int EXTRACTOR_CONTROL_UNIT = 2848;
    private const int STORAGE_FACILITY = 2541;
    private const int STORAGE_FACILITY_WITHOUT_CAPACITY = 2542;
    private const int STORAGE_FACILITY_ABSENT_FROM_THE_SDE = 2543;

    private const int SCHEMATIC_BACTERIA = 131;
    private const int SCHEMATIC_NANITES = 97;
    private const int SCHEMATIC_BROADCAST_NODE = 121;
    private const int SCHEMATIC_ABSENT_FROM_THE_SDE = 999;

    private const array SDE_TYPES = [
        self::BACTERIA => ['name' => 'Bacteria', 'volume' => 0.38, 'capacity' => null, 'marketGroup' => self::MARKET_GROUP_P1],
        self::NANITES => ['name' => 'Nanites', 'volume' => 6.0, 'capacity' => null, 'marketGroup' => self::MARKET_GROUP_P2],
        self::BROADCAST_NODE => ['name' => 'Broadcast Node', 'volume' => 200.0, 'capacity' => null, 'marketGroup' => self::MARKET_GROUP_P4],
        self::MICROORGANISMS => ['name' => 'Microorganisms', 'volume' => 0.01, 'capacity' => null, 'marketGroup' => null],
        self::BASIC_INDUSTRY_FACILITY => ['name' => 'Barren Basic Industry Facility', 'volume' => 0.0, 'capacity' => null, 'marketGroup' => null],
        self::EXTRACTOR_CONTROL_UNIT => ['name' => 'Barren Extractor Control Unit', 'volume' => 0.0, 'capacity' => null, 'marketGroup' => null],
        self::STORAGE_FACILITY => ['name' => 'Barren Storage Facility', 'volume' => 0.0, 'capacity' => 12000.0, 'marketGroup' => null],
        self::STORAGE_FACILITY_WITHOUT_CAPACITY => ['name' => 'Barren Storage Facility', 'volume' => 0.0, 'capacity' => null, 'marketGroup' => null],
    ];

    private const array SCHEMATICS = [
        self::SCHEMATIC_BACTERIA => ['output' => self::BACTERIA, 'inputs' => [self::MICROORGANISMS => 3000]],
        self::SCHEMATIC_NANITES => ['output' => self::NANITES, 'inputs' => [self::BACTERIA => 40]],
        self::SCHEMATIC_BROADCAST_NODE => ['output' => self::BROADCAST_NODE, 'inputs' => [self::NANITES => 6]],
    ];

    private int $nextPinId = 1;

    public function testColonyWithOnlyAP1FactoryHasTheP1Tier(): void
    {
        $colony = $this->colony()->addPin($this->factoryPin(self::SCHEMATIC_BACTERIA));

        $this->assertSame('P1', $this->mapper()->toResource($colony)->tier);
    }

    public function testColonyTierIsTheHighestTierOfItsFactories(): void
    {
        $colony = $this->colony()
            ->addPin($this->factoryPin(self::SCHEMATIC_NANITES))
            ->addPin($this->factoryPin(self::SCHEMATIC_BROADCAST_NODE))
            ->addPin($this->factoryPin(self::SCHEMATIC_BACTERIA));

        $this->assertSame('P4', $this->mapper()->toResource($colony)->tier);
    }

    public function testDetailResourceCarriesTheSameTier(): void
    {
        $colony = $this->colony()
            ->addPin($this->factoryPin(self::SCHEMATIC_BACTERIA))
            ->addPin($this->factoryPin(self::SCHEMATIC_NANITES));

        $resource = $this->mapper()->toDetailResource($colony);

        $this->assertSame(['P1', 'P2'], array_column($resource->pins, 'outputTier'));
        $this->assertSame('P2', $resource->tier);
    }

    public function testColonyWithoutFactoryHasNoTierEvenWithAnExtractorOfAP1Product(): void
    {
        $colony = $this->colony()->addPin(
            $this->pin(self::EXTRACTOR_CONTROL_UNIT)
                ->setExtractorProductTypeId(self::BACTERIA)
                ->setExtractorCycleTime(900)
                ->setExtractorQtyPerCycle(6420)
                ->setExpiryTime(new \DateTimeImmutable('+3 days')),
        );

        $resource = $this->mapper()->toResource($colony);

        $this->assertNull($resource->tier);
    }

    public function testColonyWithoutAnyPinHasNoTier(): void
    {
        $this->assertNull($this->mapper()->toResource($this->colony())->tier);
    }

    public function testUnreadableSchematicLeavesTheTierUnknownEvenNextToAReadableFactory(): void
    {
        $colony = $this->colony()
            ->addPin($this->factoryPin(self::SCHEMATIC_NANITES))
            ->addPin($this->factoryPin(self::SCHEMATIC_ABSENT_FROM_THE_SDE));

        $this->assertNull($this->mapper()->toResource($colony)->tier);
    }

    public function testStorageFacilityAboveItsSdeCapacityMakesTheStorageFull(): void
    {
        $colony = $this->colony()->addPin($this->storagePin([self::BACTERIA => 20000, self::NANITES => 800]));

        $this->assertTrue($this->mapper()->toResource($colony)->storageFull);
    }

    public function testStorageFacilityExactlyAtItsSdeCapacityIsFull(): void
    {
        $colony = $this->colony()->addPin($this->storagePin([self::NANITES => 2000]));

        $this->assertTrue($this->mapper()->toResource($colony)->storageFull);
    }

    public function testStorageFacilityBelowItsSdeCapacityIsNotFull(): void
    {
        $colony = $this->colony()->addPin($this->storagePin([self::BACTERIA => 20000, self::NANITES => 700]));

        $this->assertFalse($this->mapper()->toResource($colony)->storageFull);
    }

    public function testEmptyStorageFacilityIsNotFull(): void
    {
        $colony = $this->colony()->addPin($this->storagePin([]));

        $this->assertFalse($this->mapper()->toResource($colony)->storageFull);
    }

    public function testOneFullStorageFacilityAmongSeveralMakesTheStorageFull(): void
    {
        $colony = $this->colony()
            ->addPin($this->storagePin([self::NANITES => 100]))
            ->addPin($this->storagePin([self::NANITES => 2500]));

        $this->assertTrue($this->mapper()->toResource($colony)->storageFull);
    }

    public function testColonyWithoutStorageFacilityIsNotFull(): void
    {
        $colony = $this->colony()->addPin($this->factoryPin(self::SCHEMATIC_BACTERIA));

        $this->assertFalse($this->mapper()->toResource($colony)->storageFull);
    }

    public function testStorageFacilityWithoutSdeCapacityLeavesTheStorageUnknown(): void
    {
        $colony = $this->colony()->addPin($this->storagePin([self::NANITES => 100], self::STORAGE_FACILITY_WITHOUT_CAPACITY));

        $this->assertNull($this->mapper()->toResource($colony)->storageFull);
    }

    public function testStorageFacilityTypeAbsentFromTheSdeLeavesTheStorageUnknown(): void
    {
        $colony = $this->colony()->addPin($this->storagePin([self::NANITES => 100], self::STORAGE_FACILITY_ABSENT_FROM_THE_SDE));

        $this->assertNull($this->mapper()->toResource($colony)->storageFull);
    }

    public function testContentTypeAbsentFromTheSdeLeavesTheStorageUnknownWhenNoStorageIsFull(): void
    {
        $colony = $this->colony()->addPin($this->storagePin([self::NANITES => 100, self::UNKNOWN_TO_THE_SDE => 5]));

        $this->assertNull($this->mapper()->toResource($colony)->storageFull);
    }

    public function testAFullStorageFacilityWinsOverAnUnreadableOne(): void
    {
        $colony = $this->colony()
            ->addPin($this->storagePin([self::NANITES => 100], self::STORAGE_FACILITY_WITHOUT_CAPACITY))
            ->addPin($this->storagePin([self::NANITES => 2500]));

        $this->assertTrue($this->mapper()->toResource($colony)->storageFull);
    }

    private function mapper(): PlanetaryResourceMapper
    {
        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturnCallback(
            static function (mixed $typeId): ?InvType {
                if (!isset(self::SDE_TYPES[$typeId])) {
                    return null;
                }

                $definition = self::SDE_TYPES[$typeId];
                $type = (new InvType())
                    ->setTypeId($typeId)
                    ->setTypeName($definition['name'])
                    ->setVolume($definition['volume'])
                    ->setCapacity($definition['capacity']);

                if ($definition['marketGroup'] !== null) {
                    $planetaryCommodities = (new InvMarketGroup())
                        ->setMarketGroupId($definition['marketGroup'])
                        ->setMarketGroupName('Planetary Commodities');
                    $leafGroup = (new InvMarketGroup())
                        ->setMarketGroupId($definition['marketGroup'] + 1000)
                        ->setMarketGroupName('Leaf')
                        ->setParentGroup($planetaryCommodities);
                    $type->setMarketGroup($leafGroup);
                }

                return $type;
            },
        );

        $schematicRepository = $this->createStub(PlanetSchematicRepository::class);
        $schematicRepository->method('find')->willReturnCallback($this->sdeSchematic(...));

        $solarSystemRepository = $this->createStub(MapSolarSystemRepository::class);
        $solarSystemRepository->method('findBySolarSystemId')->willReturn(null);

        $preferenceRepository = $this->createStub(UserNotificationPreferenceRepository::class);
        $preferenceRepository->method('findByUserAndCategory')->willReturn(null);

        $productionCalculator = $this->createStub(PlanetaryProductionCalculator::class);
        $productionCalculator->method('calculateAutonomyDays')->willReturn(null);

        $powerCalculator = $this->createStub(PlanetaryPowerCalculator::class);
        $powerCalculator->method('outputs')->willReturn(null);
        $powerCalculator->method('load')->willReturn(null);

        $skillRepository = $this->createStub(CachedCharacterSkillRepository::class);
        $skillRepository->method('findLevelsByEveCharacterId')->willReturn([CachedCharacterSkill::SKILL_COMMAND_CENTER_UPGRADES => 4]);

        return new PlanetaryResourceMapper(
            $invTypeRepository,
            new TypeNameResolver($invTypeRepository),
            $schematicRepository,
            $solarSystemRepository,
            new PlanetaryExpiryThresholdResolver($preferenceRepository),
            $this->createStub(PlanetaryFactoryColonyTracker::class),
            $productionCalculator,
            $powerCalculator,
            $skillRepository,
            new ExtractorYieldCalculator($this->createStub(DgmAttributeTypeRepository::class)),
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
            ->setSchematicName('Schematic '.$schematicId)
            ->setCycleTime(1800);

        foreach ($definition['inputs'] as $typeId => $quantity) {
            $schematic->getSchematicTypes()->add($this->schematicType($schematic, $typeId, $quantity, isInput: true));
        }
        $schematic->getSchematicTypes()->add($this->schematicType($schematic, $definition['output'], 1, isInput: false));

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
        $character->setEveCharacterId(2112000301);
        $character->setName('Planetary Pilot');
        $character->setUser(new User());

        return (new PlanetaryColony())
            ->setCharacter($character)
            ->setPlanetId(40009177)
            ->setPlanetType('barren')
            ->setSolarSystemId(30000142)
            ->setSolarSystemName('Jita')
            ->setPlanetName('Jita V')
            ->setUpgradeLevel(4)
            ->setNumPins(1)
            ->setLastUpdate(new \DateTimeImmutable('2026-11-26T09:00:00Z'));
    }

    private function pin(int $typeId): PlanetaryPin
    {
        return (new PlanetaryPin())
            ->setPinId($this->nextPinId++)
            ->setTypeId($typeId);
    }

    private function factoryPin(int $schematicId): PlanetaryPin
    {
        return $this->pin(self::BASIC_INDUSTRY_FACILITY)
            ->setTypeName('Barren Basic Industry Facility')
            ->setSchematicId($schematicId);
    }

    /** @param array<int, int> $amountsByTypeId */
    private function storagePin(array $amountsByTypeId, int $storageTypeId = self::STORAGE_FACILITY): PlanetaryPin
    {
        $contents = [];
        foreach ($amountsByTypeId as $typeId => $amount) {
            $contents[] = ['type_id' => $typeId, 'amount' => $amount];
        }

        return $this->pin($storageTypeId)
            ->setTypeName('Barren Storage Facility')
            ->setContents($contents === [] ? null : $contents);
    }
}
