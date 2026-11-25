<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\Sde\InvType;
use App\Exception\PlanetaryCommandCenterMissingFromSdeException;
use App\Exception\PlanetaryInstallationAttributeMissingException;
use App\Exception\PlanetaryInstallationNameUnrecognizedException;
use App\Repository\Sde\DgmTypeAttributeRepository;
use App\Repository\Sde\InvTypeRepository;

final readonly class PlanetaryPlanetTypeCatalog
{
    private const int EXTRACTORS_GROUP_ID = 1026;
    private const int COMMAND_CENTERS_GROUP_ID = 1027;
    private const int PROCESSORS_GROUP_ID = 1028;
    private const int SPACEPORTS_GROUP_ID = 1030;

    private const int POWER_OUTPUT_ATTRIBUTE_ID = 11;
    private const int POWER_LOAD_ATTRIBUTE_ID = 15;
    private const int CPU_OUTPUT_ATTRIBUTE_ID = 48;
    private const int CPU_LOAD_ATTRIBUTE_ID = 49;
    private const int HARVESTER_TYPE_ATTRIBUTE_ID = 709;

    private const string LAUNCHPAD_NAME_PATTERN = '/^(\S+) Launchpad$/';
    private const string COMMAND_CENTER_SUFFIX = ' Command Center';
    private const string BASIC_FACILITY_SUFFIX = ' Basic Industry Facility';
    private const string ADVANCED_FACILITY_SUFFIX = ' Advanced Industry Facility';
    private const string HIGH_TECH_FACILITY_SUFFIX = ' High-Tech Production Plant';

    public function __construct(
        private InvTypeRepository $invTypeRepository,
        private DgmTypeAttributeRepository $attributeRepository,
    ) {
    }

    /**
     * @return array{
     *     planetTypes: list<array{name: string, commandCenter: array{cpu: int, pg: int}, launchpad: array{cpu: int, pg: int, capacity: float}, facilities: array{basic: array{cpu: int, pg: int}|null, advanced: array{cpu: int, pg: int}|null, highTech: array{cpu: int, pg: int}|null}}>,
     *     extractionPlanetTypes: list<array{typeId: int, planetTypes: list<string>}>
     * }
     */
    public function build(): array
    {
        $launchpadsByPlanet = $this->launchpadsByPlanet();
        if ($launchpadsByPlanet === []) {
            return ['planetTypes' => [], 'extractionPlanetTypes' => []];
        }

        $commandCenters = $this->invTypeRepository->findByGroupIds([self::COMMAND_CENTERS_GROUP_ID]);
        $processors = $this->invTypeRepository->findByGroupIds([self::PROCESSORS_GROUP_ID]);
        $extractors = $this->invTypeRepository->findByGroupIds([self::EXTRACTORS_GROUP_ID]);

        $attributes = $this->attributeRepository->findValuesByTypeIds(
            array_map(static fn (InvType $type): int => $type->getTypeId(), [...array_values($launchpadsByPlanet), ...$commandCenters, ...$processors, ...$extractors]),
            [
                self::POWER_OUTPUT_ATTRIBUTE_ID,
                self::POWER_LOAD_ATTRIBUTE_ID,
                self::CPU_OUTPUT_ATTRIBUTE_ID,
                self::CPU_LOAD_ATTRIBUTE_ID,
                self::HARVESTER_TYPE_ATTRIBUTE_ID,
            ],
        );

        $processorsByName = [];
        foreach ($processors as $processor) {
            $processorsByName[$processor->getTypeName()] = $processor;
        }

        $planetTypes = [];
        foreach ($launchpadsByPlanet as $planet => $launchpad) {
            $planetTypes[] = [
                'name' => $planet,
                'commandCenter' => $this->commandCenterOf($planet, $commandCenters, $attributes),
                'launchpad' => [
                    ...$this->loadOf($launchpad, $attributes),
                    'capacity' => $launchpad->getCapacity() ?? throw new PlanetaryInstallationAttributeMissingException($launchpad->getTypeId(), 'capacity'),
                ],
                'facilities' => [
                    'basic' => $this->facilityOf($processorsByName[$planet . self::BASIC_FACILITY_SUFFIX] ?? null, $attributes),
                    'advanced' => $this->facilityOf($processorsByName[$planet . self::ADVANCED_FACILITY_SUFFIX] ?? null, $attributes),
                    'highTech' => $this->facilityOf($processorsByName[$planet . self::HIGH_TECH_FACILITY_SUFFIX] ?? null, $attributes),
                ],
            ];
        }

        return [
            'planetTypes' => $planetTypes,
            'extractionPlanetTypes' => $this->extractionPlanetTypes($extractors, array_keys($launchpadsByPlanet), $attributes),
        ];
    }

    /**
     * @return array<string, InvType>
     */
    private function launchpadsByPlanet(): array
    {
        $launchpadsByPlanet = [];
        foreach ($this->invTypeRepository->findByGroupIds([self::SPACEPORTS_GROUP_ID]) as $launchpad) {
            if (preg_match(self::LAUNCHPAD_NAME_PATTERN, $launchpad->getTypeName(), $matches) !== 1) {
                throw new PlanetaryInstallationNameUnrecognizedException($launchpad->getTypeId(), $launchpad->getTypeName());
            }
            $launchpadsByPlanet[$matches[1]] = $launchpad;
        }
        ksort($launchpadsByPlanet);

        return $launchpadsByPlanet;
    }

    /**
     * @param list<InvType> $commandCenters
     * @param array<int, array<int, float|int|null>> $attributes
     * @return array{cpu: int, pg: int}
     */
    private function commandCenterOf(string $planet, array $commandCenters, array $attributes): array
    {
        $best = null;
        $bestCpu = null;
        foreach ($commandCenters as $commandCenter) {
            $name = $commandCenter->getTypeName();
            if ($name !== $planet . self::COMMAND_CENTER_SUFFIX && !str_ends_with($name, ' ' . $planet . self::COMMAND_CENTER_SUFFIX)) {
                continue;
            }
            $cpu = $this->requiredAttribute($commandCenter->getTypeId(), 'cpuOutput', self::CPU_OUTPUT_ATTRIBUTE_ID, $attributes);
            if ($bestCpu === null || $cpu > $bestCpu) {
                $best = $commandCenter;
                $bestCpu = $cpu;
            }
        }

        if ($best === null) {
            throw new PlanetaryCommandCenterMissingFromSdeException($planet);
        }

        return [
            'cpu' => (int) $this->requiredAttribute($best->getTypeId(), 'cpuOutput', self::CPU_OUTPUT_ATTRIBUTE_ID, $attributes),
            'pg' => (int) $this->requiredAttribute($best->getTypeId(), 'powerOutput', self::POWER_OUTPUT_ATTRIBUTE_ID, $attributes),
        ];
    }

    /**
     * @param array<int, array<int, float|int|null>> $attributes
     * @return array{cpu: int, pg: int}|null
     */
    private function facilityOf(?InvType $facility, array $attributes): ?array
    {
        return $facility === null ? null : $this->loadOf($facility, $attributes);
    }

    /**
     * @param array<int, array<int, float|int|null>> $attributes
     * @return array{cpu: int, pg: int}
     */
    private function loadOf(InvType $type, array $attributes): array
    {
        return [
            'cpu' => (int) $this->requiredAttribute($type->getTypeId(), 'cpuLoad', self::CPU_LOAD_ATTRIBUTE_ID, $attributes),
            'pg' => (int) $this->requiredAttribute($type->getTypeId(), 'powerLoad', self::POWER_LOAD_ATTRIBUTE_ID, $attributes),
        ];
    }

    /**
     * @param list<InvType> $extractors
     * @param list<string> $planets
     * @param array<int, array<int, float|int|null>> $attributes
     * @return list<array{typeId: int, planetTypes: list<string>}>
     */
    private function extractionPlanetTypes(array $extractors, array $planets, array $attributes): array
    {
        $planetsByResource = [];
        foreach ($extractors as $extractor) {
            $planet = explode(' ', $extractor->getTypeName(), 2)[0];
            if (!\in_array($planet, $planets, true)) {
                throw new PlanetaryInstallationNameUnrecognizedException($extractor->getTypeId(), $extractor->getTypeName());
            }
            $resourceTypeId = (int) $this->requiredAttribute($extractor->getTypeId(), 'harvesterType', self::HARVESTER_TYPE_ATTRIBUTE_ID, $attributes);
            $planetsByResource[$resourceTypeId][$planet] = $planet;
        }
        ksort($planetsByResource);

        $extractionPlanetTypes = [];
        foreach ($planetsByResource as $resourceTypeId => $resourcePlanets) {
            sort($resourcePlanets);
            $extractionPlanetTypes[] = ['typeId' => $resourceTypeId, 'planetTypes' => $resourcePlanets];
        }

        return $extractionPlanetTypes;
    }

    /**
     * @param array<int, array<int, float|int|null>> $attributes
     */
    private function requiredAttribute(int $typeId, string $attributeName, int $attributeId, array $attributes): float|int
    {
        return $attributes[$typeId][$attributeId] ?? throw new PlanetaryInstallationAttributeMissingException($typeId, $attributeName);
    }
}
