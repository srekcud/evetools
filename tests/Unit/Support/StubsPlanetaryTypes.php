<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Repository\Sde\DgmTypeAttributeRepository;
use App\Repository\Sde\InvTypeRepository;

trait StubsPlanetaryTypes
{
    private const int EXTRACTORS_GROUP_ID = 1026;
    private const int COMMAND_CENTERS_GROUP_ID = 1027;
    private const int PROCESSORS_GROUP_ID = 1028;
    private const int SPACEPORTS_GROUP_ID = 1030;

    private const int CPU_OUTPUT = 48;
    private const int POWER_OUTPUT = 11;
    private const int CPU_LOAD = 49;
    private const int POWER_LOAD = 15;
    private const int HARVESTER_TYPE = 709;

    private const int AQUEOUS_LIQUIDS_P0 = 91_001;
    private const int BASE_METALS_P0 = 91_002;

    private const int BARREN_LAUNCHPAD = 2544;
    private const int ELITE_BARREN_COMMAND_CENTER = 2133;
    private const int LEVEL_ONE_BARREN_COMMAND_CENTER = 2524;
    private const int BARREN_HIGH_TECH_PLANT = 2475;
    private const int ELITE_GAS_COMMAND_CENTER = 2138;
    private const int BARREN_ADVANCED_FACILITY = 91_531;
    private const int BARREN_AQUEOUS_EXTRACTOR = 91_541;

    /**
     * @return array{types: array<int, array{name: string, group: int, capacity: float|null}>, attributes: array<int, array<int, int|float|null>>}
     */
    private static function planetaryTypeTable(bool $withLowerCommandCenters = true): array
    {
        $types = [
            self::BARREN_LAUNCHPAD => ['name' => 'Barren Launchpad', 'group' => self::SPACEPORTS_GROUP_ID, 'capacity' => 10_000.0],
            91_510 => ['name' => 'Gas Launchpad', 'group' => self::SPACEPORTS_GROUP_ID, 'capacity' => 8_000.0],
            91_511 => ['name' => 'Ice Launchpad', 'group' => self::SPACEPORTS_GROUP_ID, 'capacity' => 12_000.0],
            self::ELITE_BARREN_COMMAND_CENTER => ['name' => 'Elite Barren Command Center', 'group' => self::COMMAND_CENTERS_GROUP_ID, 'capacity' => null],
            self::ELITE_GAS_COMMAND_CENTER => ['name' => 'Elite Gas Command Center', 'group' => self::COMMAND_CENTERS_GROUP_ID, 'capacity' => null],
            91_522 => ['name' => 'Elite Ice Command Center', 'group' => self::COMMAND_CENTERS_GROUP_ID, 'capacity' => null],
            91_524 => ['name' => 'Elite Lava Command Center', 'group' => self::COMMAND_CENTERS_GROUP_ID, 'capacity' => null],
            91_530 => ['name' => 'Barren Basic Industry Facility', 'group' => self::PROCESSORS_GROUP_ID, 'capacity' => null],
            self::BARREN_ADVANCED_FACILITY => ['name' => 'Barren Advanced Industry Facility', 'group' => self::PROCESSORS_GROUP_ID, 'capacity' => null],
            self::BARREN_HIGH_TECH_PLANT => ['name' => 'Barren High-Tech Production Plant', 'group' => self::PROCESSORS_GROUP_ID, 'capacity' => null],
            91_532 => ['name' => 'Gas Basic Industry Facility', 'group' => self::PROCESSORS_GROUP_ID, 'capacity' => null],
            91_533 => ['name' => 'Gas Advanced Industry Facility', 'group' => self::PROCESSORS_GROUP_ID, 'capacity' => null],
            91_534 => ['name' => 'Ice Advanced Industry Facility', 'group' => self::PROCESSORS_GROUP_ID, 'capacity' => null],
            91_535 => ['name' => 'Lava Advanced Industry Facility', 'group' => self::PROCESSORS_GROUP_ID, 'capacity' => null],
            91_545 => ['name' => 'Ice Base Metals Extractor', 'group' => self::EXTRACTORS_GROUP_ID, 'capacity' => null],
            self::BARREN_AQUEOUS_EXTRACTOR => ['name' => 'Barren Aqueous Liquids Extractor', 'group' => self::EXTRACTORS_GROUP_ID, 'capacity' => null],
            91_542 => ['name' => 'Gas Aqueous Liquids Extractor', 'group' => self::EXTRACTORS_GROUP_ID, 'capacity' => null],
            91_543 => ['name' => 'Barren Base Metals Extractor', 'group' => self::EXTRACTORS_GROUP_ID, 'capacity' => null],
            91_546 => ['name' => 'Barren Aqueous Liquids Extractor Prototype', 'group' => self::EXTRACTORS_GROUP_ID, 'capacity' => null],
        ];
        $attributes = [
            self::BARREN_LAUNCHPAD => [self::CPU_LOAD => 3_600, self::POWER_LOAD => 700],
            91_510 => [self::CPU_LOAD => 3_400, self::POWER_LOAD => 650],
            91_511 => [self::CPU_LOAD => 3_200, self::POWER_LOAD => 600],
            self::ELITE_BARREN_COMMAND_CENTER => [self::CPU_OUTPUT => 25_415, self::POWER_OUTPUT => 19_000],
            self::ELITE_GAS_COMMAND_CENTER => [self::CPU_OUTPUT => 22_750, self::POWER_OUTPUT => 17_250],
            91_522 => [self::CPU_OUTPUT => 20_500, self::POWER_OUTPUT => 15_000],
            91_524 => [self::CPU_OUTPUT => 28_000, self::POWER_OUTPUT => 21_000],
            91_530 => [self::CPU_LOAD => 200, self::POWER_LOAD => 800],
            self::BARREN_ADVANCED_FACILITY => [self::CPU_LOAD => 500, self::POWER_LOAD => 700],
            self::BARREN_HIGH_TECH_PLANT => [self::CPU_LOAD => 1_100, self::POWER_LOAD => 400],
            91_532 => [self::CPU_LOAD => 250, self::POWER_LOAD => 750],
            91_533 => [self::CPU_LOAD => 550, self::POWER_LOAD => 650],
            91_534 => [self::CPU_LOAD => 450, self::POWER_LOAD => 600],
            91_535 => [self::CPU_LOAD => 480, self::POWER_LOAD => 660],
            91_545 => [self::HARVESTER_TYPE => self::BASE_METALS_P0],
            self::BARREN_AQUEOUS_EXTRACTOR => [self::HARVESTER_TYPE => self::AQUEOUS_LIQUIDS_P0],
            91_542 => [self::HARVESTER_TYPE => self::AQUEOUS_LIQUIDS_P0],
            91_543 => [self::HARVESTER_TYPE => self::BASE_METALS_P0],
            91_546 => [self::HARVESTER_TYPE => self::AQUEOUS_LIQUIDS_P0],
        ];

        if ($withLowerCommandCenters) {
            $types += [
                self::LEVEL_ONE_BARREN_COMMAND_CENTER => ['name' => 'Barren Command Center', 'group' => self::COMMAND_CENTERS_GROUP_ID, 'capacity' => null],
                91_520 => ['name' => 'Limited Barren Command Center', 'group' => self::COMMAND_CENTERS_GROUP_ID, 'capacity' => null],
                91_521 => ['name' => 'Gas Command Center', 'group' => self::COMMAND_CENTERS_GROUP_ID, 'capacity' => null],
                91_523 => ['name' => 'Ice Command Center', 'group' => self::COMMAND_CENTERS_GROUP_ID, 'capacity' => null],
            ];
            $attributes += [
                self::LEVEL_ONE_BARREN_COMMAND_CENTER => [self::CPU_OUTPUT => 1_675, self::POWER_OUTPUT => 6_000],
                91_520 => [self::CPU_OUTPUT => 6_000, self::POWER_OUTPUT => 7_000],
                91_521 => [self::CPU_OUTPUT => 1_675, self::POWER_OUTPUT => 6_000],
                91_523 => [self::CPU_OUTPUT => 1_675, self::POWER_OUTPUT => 6_000],
            ];
        }

        return ['types' => $types, 'attributes' => $attributes];
    }

    /**
     * @param array{types: array<int, array{name: string, group: int, capacity: float|null}>, attributes: array<int, array<int, int|float|null>>} $table
     * @return array{0: InvTypeRepository, 1: DgmTypeAttributeRepository}
     */
    private function planetaryTypeRepositories(array $table): array
    {
        ksort($table['types']);
        $invTypes = [];
        foreach ($table['types'] as $typeId => $definition) {
            $invTypes[$typeId] = (new InvType())
                ->setTypeId($typeId)
                ->setTypeName($definition['name'])
                ->setGroup((new InvGroup())->setGroupId($definition['group']))
                ->setCapacity($definition['capacity']);
        }
        $attributes = $table['attributes'];

        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('findByGroupIds')->willReturnCallback(
            static fn (array $groupIds): array => array_values(array_filter(
                $invTypes,
                static fn (InvType $type): bool => \in_array($type->getGroup()->getGroupId(), $groupIds, true),
            )),
        );

        $attributeRepository = $this->createStub(DgmTypeAttributeRepository::class);
        $attributeRepository->method('findValuesByTypeIds')->willReturnCallback(
            static function (array $typeIds, array $attributeIds) use ($attributes): array {
                $values = [];
                foreach ($typeIds as $typeId) {
                    $rows = array_intersect_key($attributes[$typeId] ?? [], array_flip($attributeIds));
                    if ($rows !== []) {
                        $values[$typeId] = $rows;
                    }
                }

                return $values;
            },
        );

        return [$invTypeRepository, $attributeRepository];
    }
}
