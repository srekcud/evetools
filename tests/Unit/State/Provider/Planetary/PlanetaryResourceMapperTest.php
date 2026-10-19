<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Planetary;

use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\PlanetaryRoute;
use App\Entity\Sde\InvType;
use App\Entity\Sde\PlanetSchematic;
use App\Entity\Sde\PlanetSchematicType;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\Sde\PlanetSchematicRepository;
use App\Service\TypeNameResolver;
use App\State\Provider\Planetary\PlanetaryResourceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
                'quantity' => 1200.0,
                'waypoints' => [],
            ],
        ], $routes);
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

        return new PlanetaryResourceMapper(
            $invTypeRepository,
            new TypeNameResolver($invTypeRepository),
            $schematicRepository,
            $solarSystemRepository,
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
