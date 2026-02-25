<?php

declare(strict_types=1);

namespace App\Service\Sde;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class SdeMapImporter
{
    use SdeImportTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    private string $tempDir = '';

    public function setTempDir(string $tempDir): void
    {
        $this->tempDir = $tempDir;
    }

    private function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    private function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    private function getTempDir(): string
    {
        return $this->tempDir;
    }

    public function importRegions(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_map_regions');

        $count = 0;
        $connection = $this->getConnection();

        foreach ($this->readJsonlFile('mapRegions.jsonl') as $regionId => $region) {
            $position = $region['position'] ?? [];
            $x = $position['x'] ?? ($position[0] ?? null);
            $y = $position['y'] ?? ($position[1] ?? null);
            $z = $position['z'] ?? ($position[2] ?? null);

            $connection->insert('sde_map_regions', [
                'region_id' => (int) $regionId,
                'region_name' => $this->getName($region),
                'x' => $x,
                'y' => $y,
                'z' => $z,
                'x_min' => null,
                'x_max' => null,
                'y_min' => null,
                'y_max' => null,
                'z_min' => null,
                'z_max' => null,
                'faction_id' => $region['factionID'] ?? null,
                'radius' => $region['radius'] ?? null,
            ]);

            $count++;
        }

        $this->notify($progressCallback, "  Total: {$count} regions imported");
    }

    public function importConstellations(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_map_constellations');

        $validRegionIds = [];
        $result = $this->getConnection()->executeQuery('SELECT region_id FROM sde_map_regions');
        while ($row = $result->fetchAssociative()) {
            $validRegionIds[(int) $row['region_id']] = true;
        }

        $count = 0;
        $batchSize = 500;
        $connection = $this->getConnection();
        $batch = [];

        foreach ($this->readJsonlFile('mapConstellations.jsonl') as $constellationId => $constellation) {
            $regionId = (int) ($constellation['regionID'] ?? 0);
            if (!isset($validRegionIds[$regionId])) {
                continue;
            }

            $position = $constellation['position'] ?? [];
            $x = $position['x'] ?? ($position[0] ?? null);
            $y = $position['y'] ?? ($position[1] ?? null);
            $z = $position['z'] ?? ($position[2] ?? null);

            $batch[] = [
                'constellation_id' => (int) $constellationId,
                'constellation_name' => $this->getName($constellation),
                'region_id' => $regionId,
                'x' => $x,
                'y' => $y,
                'z' => $z,
                'x_min' => null,
                'x_max' => null,
                'y_min' => null,
                'y_max' => null,
                'z_min' => null,
                'z_max' => null,
                'faction_id' => $constellation['factionID'] ?? null,
                'radius' => $constellation['radius'] ?? null,
            ];

            $count++;

            if (count($batch) >= $batchSize) {
                foreach ($batch as $r) {
                    $connection->insert('sde_map_constellations', $r);
                }
                $batch = [];
            }
        }

        if (!empty($batch)) {
            foreach ($batch as $r) {
                $connection->insert('sde_map_constellations', $r);
            }
        }

        $this->notify($progressCallback, "  Total: {$count} constellations imported");
    }

    public function importSolarSystems(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_map_solar_systems');

        $constellationToRegion = [];
        $result = $this->getConnection()->executeQuery('SELECT constellation_id, region_id FROM sde_map_constellations');
        while ($row = $result->fetchAssociative()) {
            $constellationToRegion[(int) $row['constellation_id']] = (int) $row['region_id'];
        }

        $count = 0;
        $batchSize = 500;
        $connection = $this->getConnection();
        $batch = [];

        foreach ($this->readJsonlFile('mapSolarSystems.jsonl') as $solarSystemId => $system) {
            $constellationId = (int) ($system['constellationID'] ?? 0);
            if (!isset($constellationToRegion[$constellationId])) {
                continue;
            }

            $regionId = $system['regionID'] ?? $constellationToRegion[$constellationId];

            $position = $system['position'] ?? [];
            $x = $position['x'] ?? ($position[0] ?? null);
            $y = $position['y'] ?? ($position[1] ?? null);
            $z = $position['z'] ?? ($position[2] ?? null);

            $batch[] = [
                'solar_system_id' => (int) $solarSystemId,
                'solar_system_name' => $this->getName($system),
                'constellation_id' => $constellationId,
                'region_id' => (int) $regionId,
                'x' => $x,
                'y' => $y,
                'z' => $z,
                'x_min' => null,
                'x_max' => null,
                'y_min' => null,
                'y_max' => null,
                'z_min' => null,
                'z_max' => null,
                'security' => $system['securityStatus'] ?? ($system['security'] ?? 0),
                'true_security_status' => $system['securityStatus'] ?? null,
                'faction_id' => $system['factionID'] ?? null,
                'radius' => $system['radius'] ?? null,
                'sun_type_id' => $system['sunTypeID'] ?? null,
                'security_class' => $system['securityClass'] ?? null,
                'border' => $system['border'] ?? false,
                'fringe' => $system['fringe'] ?? false,
                'corridor' => $system['corridor'] ?? false,
                'hub' => $system['hub'] ?? false,
                'international' => $system['international'] ?? false,
                'regional' => $system['regional'] ?? false,
            ];

            $count++;

            if (count($batch) >= $batchSize) {
                $this->insertSolarSystemsBatch($connection, $batch);
                $batch = [];
                $this->notify($progressCallback, "  Imported {$count} solar systems...");
            }
        }

        if (!empty($batch)) {
            $this->insertSolarSystemsBatch($connection, $batch);
        }

        $this->notify($progressCallback, "  Total: {$count} solar systems imported");
    }

    /** @param list<array<string, mixed>> $batch */
    private function insertSolarSystemsBatch(\Doctrine\DBAL\Connection $connection, array $batch): void
    {
        foreach ($batch as $row) {
            $connection->insert('sde_map_solar_systems', $row, [
                'border' => ParameterType::BOOLEAN,
                'fringe' => ParameterType::BOOLEAN,
                'corridor' => ParameterType::BOOLEAN,
                'hub' => ParameterType::BOOLEAN,
                'international' => ParameterType::BOOLEAN,
                'regional' => ParameterType::BOOLEAN,
            ]);
        }
    }

    public function importStations(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_sta_stations');

        $validSolarSystemIds = [];
        $result = $this->getConnection()->executeQuery('SELECT solar_system_id, constellation_id, region_id FROM sde_map_solar_systems');
        while ($row = $result->fetchAssociative()) {
            $validSolarSystemIds[(int) $row['solar_system_id']] = [
                'constellation_id' => (int) $row['constellation_id'],
                'region_id' => (int) $row['region_id'],
            ];
        }

        $count = 0;
        $batchSize = 500;
        $connection = $this->getConnection();
        $batch = [];

        foreach ($this->readJsonlFile('npcStations.jsonl') as $stationId => $station) {
            $solarSystemId = (int) ($station['solarSystemID'] ?? 0);
            if (!isset($validSolarSystemIds[$solarSystemId])) {
                continue;
            }

            $systemInfo = $validSolarSystemIds[$solarSystemId];

            $position = $station['position'] ?? [];
            $x = $position['x'] ?? ($position[0] ?? null);
            $y = $position['y'] ?? ($position[1] ?? null);
            $z = $position['z'] ?? ($position[2] ?? null);

            $batch[] = [
                'station_id' => (int) $stationId,
                'station_name' => $this->getName($station),
                'solar_system_id' => $solarSystemId,
                'constellation_id' => $systemInfo['constellation_id'],
                'region_id' => $systemInfo['region_id'],
                'station_type_id' => $station['stationTypeID'] ?? null,
                'corporation_id' => $station['corporationID'] ?? null,
                'x' => $x,
                'y' => $y,
                'z' => $z,
                'security' => $station['security'] ?? null,
                'docking_cost_per_volume' => $station['dockingCostPerVolume'] ?? null,
                'max_ship_volume_dockable' => $station['maxShipVolumeDockable'] ?? null,
                'office_rental_cost' => $station['officeRentalCost'] ?? null,
                'reprocessing_efficiency' => $station['reprocessingEfficiency'] ?? null,
                'reprocessing_stations_take' => $station['reprocessingStationsTake'] ?? null,
                'operation_id' => $station['operationID'] ?? null,
            ];

            $count++;

            if (count($batch) >= $batchSize) {
                $this->insertStationsBatch($connection, $batch);
                $batch = [];
                $this->notify($progressCallback, "  Imported {$count} stations...");
            }
        }

        if (!empty($batch)) {
            $this->insertStationsBatch($connection, $batch);
        }

        $this->notify($progressCallback, "  Total: {$count} stations imported");
    }

    /** @param list<array<string, mixed>> $batch */
    private function insertStationsBatch(\Doctrine\DBAL\Connection $connection, array $batch): void
    {
        foreach ($batch as $row) {
            $connection->insert('sde_sta_stations', $row);
        }
    }

    public function importStargates(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_map_solar_system_jumps');

        $solarSystemInfo = [];
        $result = $this->getConnection()->executeQuery(
            'SELECT solar_system_id, constellation_id, region_id FROM sde_map_solar_systems'
        );
        while ($row = $result->fetchAssociative()) {
            $solarSystemInfo[(int) $row['solar_system_id']] = [
                'constellation_id' => (int) $row['constellation_id'],
                'region_id' => (int) $row['region_id'],
            ];
        }

        $jumps = [];
        foreach ($this->readJsonlFile('mapStargates.jsonl') as $stargateId => $stargate) {
            $fromSystemId = (int) ($stargate['solarSystemID'] ?? 0);
            $destination = $stargate['destination'] ?? [];
            $toSystemId = (int) ($destination['solarSystemID'] ?? 0);

            if ($fromSystemId && $toSystemId && isset($solarSystemInfo[$fromSystemId]) && isset($solarSystemInfo[$toSystemId])) {
                $key = min($fromSystemId, $toSystemId) . '-' . max($fromSystemId, $toSystemId);
                if (!isset($jumps[$key])) {
                    $jumps[$key] = [
                        'from' => $fromSystemId,
                        'to' => $toSystemId,
                    ];
                }
            }
        }

        $count = 0;
        $batchSize = 1000;
        $connection = $this->getConnection();
        $batch = [];

        foreach ($jumps as $jump) {
            $fromSystemId = $jump['from'];
            $toSystemId = $jump['to'];

            $fromInfo = $solarSystemInfo[$fromSystemId];
            $toInfo = $solarSystemInfo[$toSystemId];

            $batch[] = [
                'from_solar_system_id' => $fromSystemId,
                'to_solar_system_id' => $toSystemId,
                'from_region_id' => $fromInfo['region_id'],
                'from_constellation_id' => $fromInfo['constellation_id'],
                'to_region_id' => $toInfo['region_id'],
                'to_constellation_id' => $toInfo['constellation_id'],
            ];

            $count++;

            if (count($batch) >= $batchSize) {
                $this->insertJumpsBatch($connection, $batch);
                $batch = [];
                $this->notify($progressCallback, "  Imported {$count} jumps...");
            }
        }

        if (!empty($batch)) {
            $this->insertJumpsBatch($connection, $batch);
        }

        $this->notify($progressCallback, "  Total: {$count} jumps imported");
    }

    /** @param list<array<string, mixed>> $batch */
    private function insertJumpsBatch(\Doctrine\DBAL\Connection $connection, array $batch): void
    {
        foreach ($batch as $row) {
            $connection->insert('sde_map_solar_system_jumps', $row);
        }
    }
}
