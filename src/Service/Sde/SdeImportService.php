<?php

declare(strict_types=1);

namespace App\Service\Sde;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SdeImportService
{
    private const SDE_URL = 'https://developers.eveonline.com/static-data/eve-online-static-data-latest-yaml.zip';
    private const DOWNLOAD_TIMEOUT = 600;

    // Activity ID mapping (name in YAML -> ID in database)
    private const ACTIVITY_IDS = [
        'manufacturing' => 1,
        'research_time' => 2,
        'research_material' => 3,
        'copying' => 4,
        'invention' => 5,
        'reaction' => 6,
    ];

    private string $tempDir;
    private Filesystem $filesystem;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        string $projectDir,
    ) {
        $this->tempDir = $projectDir . '/var/sde';
        $this->filesystem = new Filesystem();
    }

    public function downloadAndImport(?callable $progressCallback = null): void
    {
        $this->ensureTempDir();

        $this->notify($progressCallback, 'Downloading SDE from CCP...');
        $this->downloadSde();

        $this->notify($progressCallback, 'Importing categories...');
        $this->importCategories($progressCallback);

        $this->notify($progressCallback, 'Importing groups...');
        $this->importGroups($progressCallback);

        $this->notify($progressCallback, 'Importing market groups...');
        $this->importMarketGroups($progressCallback);

        $this->notify($progressCallback, 'Importing types...');
        $this->importTypes($progressCallback);

        $this->notify($progressCallback, 'Importing regions...');
        $this->importRegions($progressCallback);

        $this->notify($progressCallback, 'Importing constellations...');
        $this->importConstellations($progressCallback);

        $this->notify($progressCallback, 'Importing solar systems...');
        $this->importSolarSystems($progressCallback);

        $this->notify($progressCallback, 'Importing stations...');
        $this->importStations($progressCallback);

        $this->notify($progressCallback, 'Importing stargates (solar system jumps)...');
        $this->importStargates($progressCallback);

        // Industry
        $this->notify($progressCallback, 'Importing blueprints and industry activities...');
        $this->importBlueprints($progressCallback);

        // Dogma
        $this->notify($progressCallback, 'Importing attribute types...');
        $this->importAttributeTypes($progressCallback);

        $this->notify($progressCallback, 'Importing type attributes...');
        $this->importTypeAttributes($progressCallback);

        $this->notify($progressCallback, 'Importing effects...');
        $this->importEffects($progressCallback);

        $this->notify($progressCallback, 'Importing type effects...');
        $this->importTypeEffects($progressCallback);

        // Reference
        $this->notify($progressCallback, 'Importing races...');
        $this->importRaces($progressCallback);

        $this->notify($progressCallback, 'Importing factions...');
        $this->importFactions($progressCallback);

        $this->notify($progressCallback, 'Importing flags...');
        $this->importFlags($progressCallback);

        $this->notify($progressCallback, 'Importing icons...');
        $this->importIcons($progressCallback);

        $this->notify($progressCallback, 'Cleaning up...');
        $this->cleanup();

        $this->notify($progressCallback, 'Import completed successfully!');
    }

    private function ensureTempDir(): void
    {
        if (!$this->filesystem->exists($this->tempDir)) {
            $this->filesystem->mkdir($this->tempDir);
        }
    }

    private function downloadSde(): void
    {
        $zipPath = $this->tempDir . '/sde.zip';

        // Check if already extracted (flat structure - files directly in tempDir)
        if ($this->filesystem->exists($this->tempDir . '/types.yaml')) {
            $this->logger->info('SDE already extracted, skipping download');

            return;
        }

        if (!$this->filesystem->exists($zipPath)) {
            $this->logger->info('Downloading SDE from CCP...');

            try {
                $response = $this->httpClient->request('GET', self::SDE_URL, [
                    'timeout' => self::DOWNLOAD_TIMEOUT,
                ]);
                $this->filesystem->dumpFile($zipPath, $response->getContent());
            } catch (TransportExceptionInterface $e) {
                throw new \RuntimeException('Failed to download SDE: ' . $e->getMessage());
            }
        }

        // Extract ZIP
        $this->logger->info('Extracting SDE...');
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) === true) {
            $zip->extractTo($this->tempDir);
            $zip->close();
        } else {
            throw new \RuntimeException('Failed to extract SDE zip file');
        }

        // Remove ZIP after extraction
        $this->filesystem->remove($zipPath);
    }

    private function parseYamlFile(string $filename, bool $optional = false): array|\stdClass
    {
        // New SDE structure: flat directory with all YAML files
        $path = $this->tempDir . '/' . $filename;

        if (!$this->filesystem->exists($path)) {
            if ($optional) {
                return [];
            }
            throw new \RuntimeException("SDE file not found: {$filename}");
        }

        // For large files, use object for map to reduce memory usage
        $fileSize = filesize($path);
        if ($fileSize > 50 * 1024 * 1024) { // > 50MB
            $this->logger->info("Parsing large YAML file: {$filename} ({$fileSize} bytes)");

            return Yaml::parseFile($path, Yaml::PARSE_OBJECT_FOR_MAP);
        }

        return Yaml::parseFile($path);
    }

    private function getName(array|object $data): string
    {
        if (is_object($data)) {
            $data = (array) $data;
        }

        if (isset($data['name'])) {
            if (is_string($data['name'])) {
                return $data['name'];
            }
            if (is_int($data['name']) || is_float($data['name'])) {
                return (string) $data['name'];
            }
            if (is_array($data['name']) || is_object($data['name'])) {
                $names = (array) $data['name'];
                $value = $names['en'] ?? reset($names) ?? '';

                return is_string($value) ? $value : (string) $value;
            }
        }

        return '';
    }

    private function getDescription(array|object $data): ?string
    {
        if (is_object($data)) {
            $data = (array) $data;
        }

        if (isset($data['description'])) {
            if (is_string($data['description'])) {
                return $data['description'] ?: null;
            }
            if (is_array($data['description']) || is_object($data['description'])) {
                $descriptions = (array) $data['description'];

                return $descriptions['en'] ?? reset($descriptions) ?? null;
            }
        }

        return null;
    }

    // ==================== INVENTORY ====================

    private function importCategories(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_inv_categories');

        $data = $this->parseYamlFile('categories.yaml');

        $count = 0;
        $connection = $this->entityManager->getConnection();

        foreach ($data as $categoryId => $category) {
            $category = (array) $category;

            $connection->insert('sde_inv_categories', [
                'category_id' => (int) $categoryId,
                'category_name' => $this->getName($category),
                'published' => $category['published'] ?? false,
                'icon_id' => $category['iconID'] ?? null,
            ], [
                'published' => \Doctrine\DBAL\ParameterType::BOOLEAN,
            ]);

            $count++;
        }

        $this->notify($progressCallback, "  Total: {$count} categories imported");
    }

    private function importGroups(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_inv_groups');

        // Pre-load category IDs
        $validCategoryIds = [];
        $result = $this->entityManager->getConnection()->executeQuery('SELECT category_id FROM sde_inv_categories');
        while ($row = $result->fetchAssociative()) {
            $validCategoryIds[(int) $row['category_id']] = true;
        }

        $data = $this->parseYamlFile('groups.yaml');

        $count = 0;
        $batchSize = 500;
        $connection = $this->entityManager->getConnection();
        $batch = [];

        foreach ($data as $groupId => $group) {
            $group = (array) $group;

            $categoryId = (int) ($group['categoryID'] ?? 0);
            if (!isset($validCategoryIds[$categoryId])) {
                continue;
            }

            $batch[] = [
                'group_id' => (int) $groupId,
                'group_name' => $this->getName($group),
                'category_id' => $categoryId,
                'published' => $group['published'] ?? false,
                'icon_id' => $group['iconID'] ?? null,
                'use_base_price' => $group['useBasePrice'] ?? false,
                'anchored' => $group['anchored'] ?? false,
                'anchorable' => $group['anchorable'] ?? false,
                'fittable_non_singleton' => $group['fittableNonSingleton'] ?? false,
            ];

            $count++;

            if (count($batch) >= $batchSize) {
                $this->insertGroupsBatch($connection, $batch);
                $batch = [];
                $this->notify($progressCallback, "  Imported {$count} groups...");
            }
        }

        if (!empty($batch)) {
            $this->insertGroupsBatch($connection, $batch);
        }

        $this->notify($progressCallback, "  Total: {$count} groups imported");
    }

    private function insertGroupsBatch($connection, array $batch): void
    {
        foreach ($batch as $row) {
            $connection->insert('sde_inv_groups', $row, [
                'published' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                'use_base_price' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                'anchored' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                'anchorable' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                'fittable_non_singleton' => \Doctrine\DBAL\ParameterType::BOOLEAN,
            ]);
        }
    }

    private function importMarketGroups(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_inv_market_groups');

        $data = $this->parseYamlFile('marketGroups.yaml');

        $connection = $this->entityManager->getConnection();
        $count = 0;
        $rows = [];

        // First pass: insert all without parent references
        foreach ($data as $marketGroupId => $marketGroup) {
            $marketGroup = (array) $marketGroup;
            $rows[$marketGroupId] = $marketGroup;

            $connection->insert('sde_inv_market_groups', [
                'market_group_id' => (int) $marketGroupId,
                'market_group_name' => $this->getName($marketGroup),
                'description' => $this->getDescription($marketGroup),
                'icon_id' => $marketGroup['iconID'] ?? null,
                'has_types' => $marketGroup['hasTypes'] ?? false,
                'parent_group_id' => null,
            ], [
                'has_types' => \Doctrine\DBAL\ParameterType::BOOLEAN,
            ]);

            $count++;
        }

        $this->notify($progressCallback, "  Created {$count} market groups...");

        // Second pass: update parent references
        $updated = 0;
        foreach ($rows as $marketGroupId => $marketGroup) {
            $parentId = $marketGroup['parentGroupID'] ?? null;
            if ($parentId !== null) {
                $connection->update('sde_inv_market_groups', [
                    'parent_group_id' => (int) $parentId,
                ], [
                    'market_group_id' => (int) $marketGroupId,
                ]);
                $updated++;
            }
        }

        $this->notify($progressCallback, "  Total: {$count} market groups imported ({$updated} with parents)");
    }

    private function importTypes(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_inv_types');

        // Pre-load valid group and market group IDs
        $validGroupIds = [];
        $result = $this->entityManager->getConnection()->executeQuery('SELECT group_id FROM sde_inv_groups');
        while ($row = $result->fetchAssociative()) {
            $validGroupIds[(int) $row['group_id']] = true;
        }

        $validMarketGroupIds = [];
        $result = $this->entityManager->getConnection()->executeQuery('SELECT market_group_id FROM sde_inv_market_groups');
        while ($row = $result->fetchAssociative()) {
            $validMarketGroupIds[(int) $row['market_group_id']] = true;
        }

        $data = $this->parseYamlFile('types.yaml');

        $count = 0;
        $batchSize = 500;
        $connection = $this->entityManager->getConnection();
        $batch = [];

        foreach ($data as $typeId => $type) {
            $type = (array) $type;

            $groupId = (int) ($type['groupID'] ?? 0);
            if (!isset($validGroupIds[$groupId])) {
                continue;
            }

            $marketGroupId = $type['marketGroupID'] ?? null;
            if ($marketGroupId !== null && !isset($validMarketGroupIds[$marketGroupId])) {
                $marketGroupId = null;
            }

            $batch[] = [
                'type_id' => (int) $typeId,
                'type_name' => $this->getName($type),
                'description' => $this->getDescription($type),
                'group_id' => $groupId,
                'mass' => $type['mass'] ?? null,
                'volume' => $type['volume'] ?? null,
                'capacity' => $type['capacity'] ?? null,
                'portion_size' => $type['portionSize'] ?? null,
                'base_price' => $type['basePrice'] ?? null,
                'published' => $type['published'] ?? false,
                'market_group_id' => $marketGroupId,
                'icon_id' => $type['iconID'] ?? null,
                'graphic_id' => $type['graphicID'] ?? null,
                'race_id' => $type['raceID'] ?? null,
                'sof_faction_name' => $type['sofFactionName'] ?? null,
                'sound_id' => $type['soundID'] ?? null,
            ];

            $count++;

            if (count($batch) >= $batchSize) {
                $this->insertTypesBatch($connection, $batch);
                $batch = [];
                $this->notify($progressCallback, "  Imported {$count} types...");
            }
        }

        if (!empty($batch)) {
            $this->insertTypesBatch($connection, $batch);
        }

        $this->notify($progressCallback, "  Total: {$count} types imported");
    }

    private function insertTypesBatch($connection, array $batch): void
    {
        foreach ($batch as $row) {
            $connection->insert('sde_inv_types', $row, [
                'published' => \Doctrine\DBAL\ParameterType::BOOLEAN,
            ]);
        }
    }

    // ==================== MAP ====================

    private function importRegions(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_map_regions');

        $data = $this->parseYamlFile('mapRegions.yaml');

        $count = 0;
        $connection = $this->entityManager->getConnection();

        foreach ($data as $regionId => $region) {
            $region = (array) $region;

            // Position can be array [x,y,z] or object {x,y,z}
            $position = $region['position'] ?? [];
            if (is_object($position)) {
                $position = (array) $position;
            }
            $x = $position['x'] ?? ($position[0] ?? null);
            $y = $position['y'] ?? ($position[1] ?? null);
            $z = $position['z'] ?? ($position[2] ?? null);

            $connection->insert('sde_map_regions', [
                'region_id' => (int) $regionId,
                'region_name' => $this->getName($region),
                'x' => $x,
                'y' => $y,
                'z' => $z,
                'x_min' => null, // Not in new SDE format
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

    private function importConstellations(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_map_constellations');

        // Pre-load region IDs
        $validRegionIds = [];
        $result = $this->entityManager->getConnection()->executeQuery('SELECT region_id FROM sde_map_regions');
        while ($row = $result->fetchAssociative()) {
            $validRegionIds[(int) $row['region_id']] = true;
        }

        $data = $this->parseYamlFile('mapConstellations.yaml');

        $count = 0;
        $batchSize = 500;
        $connection = $this->entityManager->getConnection();
        $batch = [];

        foreach ($data as $constellationId => $constellation) {
            $constellation = (array) $constellation;

            $regionId = (int) ($constellation['regionID'] ?? 0);
            if (!isset($validRegionIds[$regionId])) {
                continue;
            }

            // Position can be array or object
            $position = $constellation['position'] ?? [];
            if (is_object($position)) {
                $position = (array) $position;
            }
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

    private function importSolarSystems(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_map_solar_systems');

        // Pre-load constellation IDs and their region IDs
        $constellationToRegion = [];
        $result = $this->entityManager->getConnection()->executeQuery('SELECT constellation_id, region_id FROM sde_map_constellations');
        while ($row = $result->fetchAssociative()) {
            $constellationToRegion[(int) $row['constellation_id']] = (int) $row['region_id'];
        }

        $data = $this->parseYamlFile('mapSolarSystems.yaml');

        $count = 0;
        $batchSize = 500;
        $connection = $this->entityManager->getConnection();
        $batch = [];

        foreach ($data as $solarSystemId => $system) {
            $system = (array) $system;

            $constellationId = (int) ($system['constellationID'] ?? 0);
            if (!isset($constellationToRegion[$constellationId])) {
                continue;
            }

            $regionId = $system['regionID'] ?? $constellationToRegion[$constellationId];

            // Position can be array or object
            $position = $system['position'] ?? [];
            if (is_object($position)) {
                $position = (array) $position;
            }
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

    private function insertSolarSystemsBatch($connection, array $batch): void
    {
        foreach ($batch as $row) {
            $connection->insert('sde_map_solar_systems', $row, [
                'border' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                'fringe' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                'corridor' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                'hub' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                'international' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                'regional' => \Doctrine\DBAL\ParameterType::BOOLEAN,
            ]);
        }
    }

    private function importStations(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_sta_stations');

        // Pre-load valid solar system IDs
        $validSolarSystemIds = [];
        $result = $this->entityManager->getConnection()->executeQuery('SELECT solar_system_id, constellation_id, region_id FROM sde_map_solar_systems');
        while ($row = $result->fetchAssociative()) {
            $validSolarSystemIds[(int) $row['solar_system_id']] = [
                'constellation_id' => (int) $row['constellation_id'],
                'region_id' => (int) $row['region_id'],
            ];
        }

        $data = $this->parseYamlFile('npcStations.yaml');

        $count = 0;
        $batchSize = 500;
        $connection = $this->entityManager->getConnection();
        $batch = [];

        foreach ($data as $stationId => $station) {
            $station = (array) $station;

            $solarSystemId = (int) ($station['solarSystemID'] ?? 0);
            if (!isset($validSolarSystemIds[$solarSystemId])) {
                continue;
            }

            $systemInfo = $validSolarSystemIds[$solarSystemId];

            // Position can be array or object
            $position = $station['position'] ?? [];
            if (is_object($position)) {
                $position = (array) $position;
            }
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

    private function insertStationsBatch($connection, array $batch): void
    {
        foreach ($batch as $row) {
            $connection->insert('sde_sta_stations', $row);
        }
    }

    private function importStargates(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_map_solar_system_jumps');

        // Pre-load solar system info
        $solarSystemInfo = [];
        $result = $this->entityManager->getConnection()->executeQuery(
            'SELECT solar_system_id, constellation_id, region_id FROM sde_map_solar_systems'
        );
        while ($row = $result->fetchAssociative()) {
            $solarSystemInfo[(int) $row['solar_system_id']] = [
                'constellation_id' => (int) $row['constellation_id'],
                'region_id' => (int) $row['region_id'],
            ];
        }

        $data = $this->parseYamlFile('mapStargates.yaml');

        // Build unique jumps (avoid duplicates A->B and B->A)
        $jumps = [];
        foreach ($data as $stargateId => $stargate) {
            $stargate = (array) $stargate;

            $fromSystemId = (int) ($stargate['solarSystemID'] ?? 0);
            $destination = $stargate['destination'] ?? [];
            if (is_object($destination)) {
                $destination = (array) $destination;
            }
            $toSystemId = (int) ($destination['solarSystemID'] ?? 0);

            if ($fromSystemId && $toSystemId && isset($solarSystemInfo[$fromSystemId]) && isset($solarSystemInfo[$toSystemId])) {
                // Create unique key to avoid duplicates
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
        $connection = $this->entityManager->getConnection();
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

    private function insertJumpsBatch($connection, array $batch): void
    {
        foreach ($batch as $row) {
            $connection->insert('sde_map_solar_system_jumps', $row);
        }
    }

    // ==================== INDUSTRY ====================

    private function importBlueprints(?callable $progressCallback = null): void
    {
        // Truncate all industry tables
        $this->truncateTable('sde_industry_activity_skills');
        $this->truncateTable('sde_industry_activity_products');
        $this->truncateTable('sde_industry_activity_materials');
        $this->truncateTable('sde_industry_activities');
        $this->truncateTable('sde_industry_blueprints');

        $data = $this->parseYamlFile('blueprints.yaml');

        $blueprintCount = 0;
        $activityCount = 0;
        $materialCount = 0;
        $productCount = 0;
        $skillCount = 0;

        $connection = $this->entityManager->getConnection();
        $seenSkills = [];

        foreach ($data as $blueprintTypeId => $blueprint) {
            $blueprint = (array) $blueprint;

            // Insert blueprint
            $connection->insert('sde_industry_blueprints', [
                'type_id' => (int) $blueprintTypeId,
                'max_production_limit' => $blueprint['maxProductionLimit'] ?? 0,
            ]);
            $blueprintCount++;

            // Process activities
            $activities = $blueprint['activities'] ?? [];
            foreach ($activities as $activityName => $activity) {
                $activity = (array) $activity;

                $activityId = self::ACTIVITY_IDS[$activityName] ?? null;
                if ($activityId === null) {
                    continue;
                }

                // Insert activity
                $connection->insert('sde_industry_activities', [
                    'type_id' => (int) $blueprintTypeId,
                    'activity_id' => $activityId,
                    'time' => $activity['time'] ?? 0,
                ]);
                $activityCount++;

                // Materials
                $materials = $activity['materials'] ?? [];
                foreach ($materials as $material) {
                    $material = (array) $material;
                    $connection->insert('sde_industry_activity_materials', [
                        'type_id' => (int) $blueprintTypeId,
                        'activity_id' => $activityId,
                        'material_type_id' => (int) $material['typeID'],
                        'quantity' => (int) $material['quantity'],
                    ]);
                    $materialCount++;
                }

                // Products
                $products = $activity['products'] ?? [];
                foreach ($products as $product) {
                    $product = (array) $product;
                    $connection->insert('sde_industry_activity_products', [
                        'type_id' => (int) $blueprintTypeId,
                        'activity_id' => $activityId,
                        'product_type_id' => (int) $product['typeID'],
                        'quantity' => (int) $product['quantity'],
                    ]);
                    $productCount++;
                }

                // Skills
                $skills = $activity['skills'] ?? [];
                foreach ($skills as $skill) {
                    $skill = (array) $skill;
                    $skillId = (int) $skill['typeID'];
                    $level = (int) $skill['level'];

                    // Deduplicate skills
                    $key = $blueprintTypeId . '-' . $activityId . '-' . $skillId;
                    if (isset($seenSkills[$key])) {
                        continue;
                    }
                    $seenSkills[$key] = true;

                    $connection->insert('sde_industry_activity_skills', [
                        'type_id' => (int) $blueprintTypeId,
                        'activity_id' => $activityId,
                        'skill_id' => $skillId,
                        'level' => $level,
                    ]);
                    $skillCount++;
                }
            }

            if ($blueprintCount % 1000 === 0) {
                $this->notify($progressCallback, "  Imported {$blueprintCount} blueprints...");
            }
        }

        $this->notify($progressCallback, "  Total: {$blueprintCount} blueprints, {$activityCount} activities, {$materialCount} materials, {$productCount} products, {$skillCount} skills");
    }

    // ==================== DOGMA ====================

    private function importAttributeTypes(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_dgm_attribute_types');

        $data = $this->parseYamlFile('dogmaAttributes.yaml');

        $count = 0;
        $batchSize = 500;
        $connection = $this->entityManager->getConnection();
        $batch = [];

        foreach ($data as $attributeId => $attribute) {
            $attribute = (array) $attribute;

            // displayName can be multilingual (array) or simple string
            $displayName = $attribute['displayName'] ?? null;
            if (is_array($displayName) || is_object($displayName)) {
                $displayName = (array) $displayName;
                $displayName = $displayName['en'] ?? reset($displayName) ?? null;
            }

            $batch[] = [
                'attribute_id' => (int) $attributeId,
                'attribute_name' => $attribute['name'] ?? null,
                'description' => $this->getDescription($attribute),
                'icon_id' => $attribute['iconID'] ?? null,
                'default_value' => $attribute['defaultValue'] ?? null,
                'published' => $attribute['published'] ?? false,
                'display_name' => $displayName,
                'unit_id' => $attribute['unitID'] ?? null,
                'stackable' => $attribute['stackable'] ?? false,
                'high_is_good' => $attribute['highIsGood'] ?? false,
                'category_id' => $attribute['attributeCategoryID'] ?? ($attribute['categoryID'] ?? null),
            ];

            $count++;

            if (count($batch) >= $batchSize) {
                $this->insertAttributeTypesBatch($connection, $batch);
                $batch = [];
                $this->notify($progressCallback, "  Imported {$count} attribute types...");
            }
        }

        if (!empty($batch)) {
            $this->insertAttributeTypesBatch($connection, $batch);
        }

        $this->notify($progressCallback, "  Total: {$count} attribute types imported");
    }

    private function insertAttributeTypesBatch($connection, array $batch): void
    {
        foreach ($batch as $row) {
            $connection->insert('sde_dgm_attribute_types', $row, [
                'published' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                'stackable' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                'high_is_good' => \Doctrine\DBAL\ParameterType::BOOLEAN,
            ]);
        }
    }

    private function importTypeAttributes(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_dgm_type_attributes');

        $data = $this->parseYamlFile('typeDogma.yaml');

        $count = 0;
        $batchSize = 5000;
        $connection = $this->entityManager->getConnection();
        $batch = [];

        foreach ($data as $typeId => $typeData) {
            $typeData = (array) $typeData;
            $attributes = $typeData['dogmaAttributes'] ?? [];

            foreach ($attributes as $attribute) {
                $attribute = (array) $attribute;

                $value = $attribute['value'] ?? null;
                $valueInt = null;
                $valueFloat = null;

                if ($value !== null) {
                    // Check if it's a whole number and fits in PostgreSQL integer range
                    $isWholeNumber = is_int($value) || (is_float($value) && floor($value) == $value);
                    $fitsInInteger = $value >= -2147483648 && $value <= 2147483647;

                    if ($isWholeNumber && $fitsInInteger) {
                        $valueInt = (int) $value;
                    } else {
                        $valueFloat = (float) $value;
                    }
                }

                $batch[] = [
                    'type_id' => (int) $typeId,
                    'attribute_id' => (int) $attribute['attributeID'],
                    'value_int' => $valueInt,
                    'value_float' => $valueFloat,
                ];

                $count++;

                if (count($batch) >= $batchSize) {
                    $this->insertTypeAttributesBatch($connection, $batch);
                    $batch = [];
                    $this->notify($progressCallback, "  Imported {$count} type attributes...");
                }
            }
        }

        if (!empty($batch)) {
            $this->insertTypeAttributesBatch($connection, $batch);
        }

        $this->notify($progressCallback, "  Total: {$count} type attributes imported");
    }

    private function insertTypeAttributesBatch($connection, array $batch): void
    {
        foreach ($batch as $row) {
            $connection->insert('sde_dgm_type_attributes', $row);
        }
    }

    private function importEffects(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_dgm_effects');

        $data = $this->parseYamlFile('dogmaEffects.yaml');

        $count = 0;
        $connection = $this->entityManager->getConnection();

        foreach ($data as $effectId => $effect) {
            $effect = (array) $effect;

            $connection->insert('sde_dgm_effects', [
                'effect_id' => (int) $effectId,
                'effect_name' => $effect['effectName'] ?? null,
                'effect_category' => $effect['effectCategory'] ?? null,
                'pre_expression' => $effect['preExpression'] ?? null,
                'post_expression' => $effect['postExpression'] ?? null,
                'description' => $this->getDescription($effect),
                'guid' => $effect['guid'] ?? null,
                'icon_id' => $effect['iconID'] ?? null,
                'is_offensive' => $effect['isOffensive'] ?? false,
                'is_assistance' => $effect['isAssistance'] ?? false,
                'duration_attribute_id' => $effect['durationAttributeID'] ?? null,
                'tracking_speed_attribute_id' => $effect['trackingSpeedAttributeID'] ?? null,
                'discharge_attribute_id' => $effect['dischargeAttributeID'] ?? null,
                'range_attribute_id' => $effect['rangeAttributeID'] ?? null,
                'falloff_attribute_id' => $effect['falloffAttributeID'] ?? null,
                'disallow_auto_repeat' => $effect['disallowAutoRepeat'] ?? false,
                'published' => $effect['published'] ?? false,
                'display_name' => $effect['displayName'] ?? null,
                'is_warp_safe' => $effect['isWarpSafe'] ?? false,
                'range_chance' => $effect['rangeChance'] ?? false,
                'electronic_chance' => $effect['electronicChance'] ?? false,
                'propulsion_chance' => $effect['propulsionChance'] ?? false,
                'distribution' => $effect['distribution'] ?? null,
                'sfx_name' => $effect['sfxName'] ?? null,
                'npc_usage_chance_attribute_id' => $effect['npcUsageChanceAttributeID'] ?? null,
                'npc_activation_chance_attribute_id' => $effect['npcActivationChanceAttributeID'] ?? null,
                'fitting_usage_chance_attribute_id' => $effect['fittingUsageChanceAttributeID'] ?? null,
                'modifier_info' => isset($effect['modifierInfo']) ? json_encode($effect['modifierInfo']) : null,
            ], [
                'is_offensive' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                'is_assistance' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                'disallow_auto_repeat' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                'published' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                'is_warp_safe' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                'range_chance' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                'electronic_chance' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                'propulsion_chance' => \Doctrine\DBAL\ParameterType::BOOLEAN,
            ]);

            $count++;
        }

        $this->notify($progressCallback, "  Total: {$count} effects imported");
    }

    private function importTypeEffects(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_dgm_type_effects');

        $data = $this->parseYamlFile('typeDogma.yaml');

        $count = 0;
        $batchSize = 5000;
        $connection = $this->entityManager->getConnection();
        $batch = [];

        foreach ($data as $typeId => $typeData) {
            $typeData = (array) $typeData;
            $effects = $typeData['dogmaEffects'] ?? [];

            foreach ($effects as $effect) {
                $effect = (array) $effect;

                $batch[] = [
                    'type_id' => (int) $typeId,
                    'effect_id' => (int) $effect['effectID'],
                    'is_default' => $effect['isDefault'] ?? false,
                ];

                $count++;

                if (count($batch) >= $batchSize) {
                    $this->insertTypeEffectsBatch($connection, $batch);
                    $batch = [];
                    $this->notify($progressCallback, "  Imported {$count} type effects...");
                }
            }
        }

        if (!empty($batch)) {
            $this->insertTypeEffectsBatch($connection, $batch);
        }

        $this->notify($progressCallback, "  Total: {$count} type effects imported");
    }

    private function insertTypeEffectsBatch($connection, array $batch): void
    {
        foreach ($batch as $row) {
            $connection->insert('sde_dgm_type_effects', $row, [
                'is_default' => \Doctrine\DBAL\ParameterType::BOOLEAN,
            ]);
        }
    }

    // ==================== REFERENCE ====================

    private function importRaces(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_chr_races');

        $data = $this->parseYamlFile('races.yaml');

        $count = 0;
        $connection = $this->entityManager->getConnection();

        foreach ($data as $raceId => $race) {
            $race = (array) $race;

            $connection->insert('sde_chr_races', [
                'race_id' => (int) $raceId,
                'race_name' => $this->getName($race),
                'description' => $this->getDescription($race),
                'icon_id' => $race['iconID'] ?? null,
                'short_description' => null, // Not in new SDE
            ]);

            $count++;
        }

        $this->notify($progressCallback, "  Total: {$count} races imported");
    }

    private function importFactions(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_chr_factions');

        $data = $this->parseYamlFile('factions.yaml');

        $count = 0;
        $connection = $this->entityManager->getConnection();

        foreach ($data as $factionId => $faction) {
            $faction = (array) $faction;

            $connection->insert('sde_chr_factions', [
                'faction_id' => (int) $factionId,
                'faction_name' => $this->getName($faction),
                'description' => $this->getDescription($faction),
                'race_ids' => $faction['raceID'] ?? null,
                'solar_system_id' => $faction['solarSystemID'] ?? null,
                'corporation_id' => $faction['corporationID'] ?? null,
                'size_factor' => $faction['sizeFactor'] ?? null,
                'station_count' => $faction['stationCount'] ?? null,
                'station_system_count' => $faction['stationSystemCount'] ?? null,
                'militia_corporation_id' => $faction['militiaCorporationID'] ?? null,
                'icon_id' => $faction['iconID'] ?? null,
            ]);

            $count++;
        }

        $this->notify($progressCallback, "  Total: {$count} factions imported");
    }

    private function importFlags(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_inv_flags');

        // invFlags.yaml doesn't exist in new SDE, but we have hardcoded data based on EVE's location flags
        // These are the standard inventory flags used by ESI
        $flags = [
            ['flag_id' => 0, 'flag_name' => 'None', 'flag_text' => 'None', 'order_id' => 0],
            ['flag_id' => 4, 'flag_name' => 'Hangar', 'flag_text' => 'Hangar', 'order_id' => 4],
            ['flag_id' => 5, 'flag_name' => 'Cargo', 'flag_text' => 'Cargo', 'order_id' => 5],
            ['flag_id' => 11, 'flag_name' => 'LoSlot0', 'flag_text' => 'Low power slot 1', 'order_id' => 11],
            ['flag_id' => 12, 'flag_name' => 'LoSlot1', 'flag_text' => 'Low power slot 2', 'order_id' => 12],
            ['flag_id' => 13, 'flag_name' => 'LoSlot2', 'flag_text' => 'Low power slot 3', 'order_id' => 13],
            ['flag_id' => 14, 'flag_name' => 'LoSlot3', 'flag_text' => 'Low power slot 4', 'order_id' => 14],
            ['flag_id' => 15, 'flag_name' => 'LoSlot4', 'flag_text' => 'Low power slot 5', 'order_id' => 15],
            ['flag_id' => 16, 'flag_name' => 'LoSlot5', 'flag_text' => 'Low power slot 6', 'order_id' => 16],
            ['flag_id' => 17, 'flag_name' => 'LoSlot6', 'flag_text' => 'Low power slot 7', 'order_id' => 17],
            ['flag_id' => 18, 'flag_name' => 'LoSlot7', 'flag_text' => 'Low power slot 8', 'order_id' => 18],
            ['flag_id' => 19, 'flag_name' => 'MedSlot0', 'flag_text' => 'Medium power slot 1', 'order_id' => 19],
            ['flag_id' => 20, 'flag_name' => 'MedSlot1', 'flag_text' => 'Medium power slot 2', 'order_id' => 20],
            ['flag_id' => 21, 'flag_name' => 'MedSlot2', 'flag_text' => 'Medium power slot 3', 'order_id' => 21],
            ['flag_id' => 22, 'flag_name' => 'MedSlot3', 'flag_text' => 'Medium power slot 4', 'order_id' => 22],
            ['flag_id' => 23, 'flag_name' => 'MedSlot4', 'flag_text' => 'Medium power slot 5', 'order_id' => 23],
            ['flag_id' => 24, 'flag_name' => 'MedSlot5', 'flag_text' => 'Medium power slot 6', 'order_id' => 24],
            ['flag_id' => 25, 'flag_name' => 'MedSlot6', 'flag_text' => 'Medium power slot 7', 'order_id' => 25],
            ['flag_id' => 26, 'flag_name' => 'MedSlot7', 'flag_text' => 'Medium power slot 8', 'order_id' => 26],
            ['flag_id' => 27, 'flag_name' => 'HiSlot0', 'flag_text' => 'High power slot 1', 'order_id' => 27],
            ['flag_id' => 28, 'flag_name' => 'HiSlot1', 'flag_text' => 'High power slot 2', 'order_id' => 28],
            ['flag_id' => 29, 'flag_name' => 'HiSlot2', 'flag_text' => 'High power slot 3', 'order_id' => 29],
            ['flag_id' => 30, 'flag_name' => 'HiSlot3', 'flag_text' => 'High power slot 4', 'order_id' => 30],
            ['flag_id' => 31, 'flag_name' => 'HiSlot4', 'flag_text' => 'High power slot 5', 'order_id' => 31],
            ['flag_id' => 32, 'flag_name' => 'HiSlot5', 'flag_text' => 'High power slot 6', 'order_id' => 32],
            ['flag_id' => 33, 'flag_name' => 'HiSlot6', 'flag_text' => 'High power slot 7', 'order_id' => 33],
            ['flag_id' => 34, 'flag_name' => 'HiSlot7', 'flag_text' => 'High power slot 8', 'order_id' => 34],
            ['flag_id' => 87, 'flag_name' => 'DroneBay', 'flag_text' => 'Drone Bay', 'order_id' => 87],
            ['flag_id' => 88, 'flag_name' => 'Booster', 'flag_text' => 'Booster', 'order_id' => 88],
            ['flag_id' => 89, 'flag_name' => 'Implant', 'flag_text' => 'Implant', 'order_id' => 89],
            ['flag_id' => 90, 'flag_name' => 'ShipHangar', 'flag_text' => 'Ship Hangar', 'order_id' => 90],
            ['flag_id' => 92, 'flag_name' => 'RigSlot0', 'flag_text' => 'Rig slot 1', 'order_id' => 92],
            ['flag_id' => 93, 'flag_name' => 'RigSlot1', 'flag_text' => 'Rig slot 2', 'order_id' => 93],
            ['flag_id' => 94, 'flag_name' => 'RigSlot2', 'flag_text' => 'Rig slot 3', 'order_id' => 94],
            ['flag_id' => 116, 'flag_name' => 'CorpSAG1', 'flag_text' => 'Corporation Hangar 1', 'order_id' => 116],
            ['flag_id' => 117, 'flag_name' => 'CorpSAG2', 'flag_text' => 'Corporation Hangar 2', 'order_id' => 117],
            ['flag_id' => 118, 'flag_name' => 'CorpSAG3', 'flag_text' => 'Corporation Hangar 3', 'order_id' => 118],
            ['flag_id' => 119, 'flag_name' => 'CorpSAG4', 'flag_text' => 'Corporation Hangar 4', 'order_id' => 119],
            ['flag_id' => 120, 'flag_name' => 'CorpSAG5', 'flag_text' => 'Corporation Hangar 5', 'order_id' => 120],
            ['flag_id' => 121, 'flag_name' => 'CorpSAG6', 'flag_text' => 'Corporation Hangar 6', 'order_id' => 121],
            ['flag_id' => 122, 'flag_name' => 'CorpSAG7', 'flag_text' => 'Corporation Hangar 7', 'order_id' => 122],
            ['flag_id' => 125, 'flag_name' => 'SubSystem0', 'flag_text' => 'Subsystem slot 1', 'order_id' => 125],
            ['flag_id' => 126, 'flag_name' => 'SubSystem1', 'flag_text' => 'Subsystem slot 2', 'order_id' => 126],
            ['flag_id' => 127, 'flag_name' => 'SubSystem2', 'flag_text' => 'Subsystem slot 3', 'order_id' => 127],
            ['flag_id' => 128, 'flag_name' => 'SubSystem3', 'flag_text' => 'Subsystem slot 4', 'order_id' => 128],
            ['flag_id' => 129, 'flag_name' => 'SubSystem4', 'flag_text' => 'Subsystem slot 5', 'order_id' => 129],
            ['flag_id' => 130, 'flag_name' => 'SubSystem5', 'flag_text' => 'Subsystem slot 6', 'order_id' => 130],
            ['flag_id' => 131, 'flag_name' => 'SubSystem6', 'flag_text' => 'Subsystem slot 7', 'order_id' => 131],
            ['flag_id' => 132, 'flag_name' => 'SubSystem7', 'flag_text' => 'Subsystem slot 8', 'order_id' => 132],
            ['flag_id' => 133, 'flag_name' => 'SpecializedFuelBay', 'flag_text' => 'Fuel Bay', 'order_id' => 133],
            ['flag_id' => 134, 'flag_name' => 'SpecializedOreHold', 'flag_text' => 'Ore Hold', 'order_id' => 134],
            ['flag_id' => 135, 'flag_name' => 'SpecializedGasHold', 'flag_text' => 'Gas Hold', 'order_id' => 135],
            ['flag_id' => 136, 'flag_name' => 'SpecializedMineralHold', 'flag_text' => 'Mineral Hold', 'order_id' => 136],
            ['flag_id' => 137, 'flag_name' => 'SpecializedSalvageHold', 'flag_text' => 'Salvage Hold', 'order_id' => 137],
            ['flag_id' => 138, 'flag_name' => 'SpecializedShipHold', 'flag_text' => 'Ship Hold', 'order_id' => 138],
            ['flag_id' => 139, 'flag_name' => 'SpecializedSmallShipHold', 'flag_text' => 'Small Ship Hold', 'order_id' => 139],
            ['flag_id' => 140, 'flag_name' => 'SpecializedMediumShipHold', 'flag_text' => 'Medium Ship Hold', 'order_id' => 140],
            ['flag_id' => 141, 'flag_name' => 'SpecializedLargeShipHold', 'flag_text' => 'Large Ship Hold', 'order_id' => 141],
            ['flag_id' => 142, 'flag_name' => 'SpecializedIndustrialShipHold', 'flag_text' => 'Industrial Ship Hold', 'order_id' => 142],
            ['flag_id' => 143, 'flag_name' => 'SpecializedAmmoHold', 'flag_text' => 'Ammo Hold', 'order_id' => 143],
            ['flag_id' => 144, 'flag_name' => 'SpecializedCommandCenterHold', 'flag_text' => 'Command Center Hold', 'order_id' => 144],
            ['flag_id' => 145, 'flag_name' => 'SpecializedPlanetaryCommoditiesHold', 'flag_text' => 'Planetary Commodities Hold', 'order_id' => 145],
            ['flag_id' => 146, 'flag_name' => 'SpecializedMaterialBay', 'flag_text' => 'Material Bay', 'order_id' => 146],
            ['flag_id' => 148, 'flag_name' => 'FighterBay', 'flag_text' => 'Fighter Bay', 'order_id' => 148],
            ['flag_id' => 149, 'flag_name' => 'FighterTube0', 'flag_text' => 'Fighter Tube 1', 'order_id' => 149],
            ['flag_id' => 150, 'flag_name' => 'FighterTube1', 'flag_text' => 'Fighter Tube 2', 'order_id' => 150],
            ['flag_id' => 151, 'flag_name' => 'FighterTube2', 'flag_text' => 'Fighter Tube 3', 'order_id' => 151],
            ['flag_id' => 152, 'flag_name' => 'FighterTube3', 'flag_text' => 'Fighter Tube 4', 'order_id' => 152],
            ['flag_id' => 153, 'flag_name' => 'FighterTube4', 'flag_text' => 'Fighter Tube 5', 'order_id' => 153],
            ['flag_id' => 154, 'flag_name' => 'Module', 'flag_text' => 'Module', 'order_id' => 154],
            ['flag_id' => 155, 'flag_name' => 'Wardrobe', 'flag_text' => 'Wardrobe', 'order_id' => 155],
            ['flag_id' => 156, 'flag_name' => 'FleetHangar', 'flag_text' => 'Fleet Hangar', 'order_id' => 156],
            ['flag_id' => 157, 'flag_name' => 'HiddenModifers', 'flag_text' => 'Hidden Modifiers', 'order_id' => 157],
            ['flag_id' => 158, 'flag_name' => 'StructureFuel', 'flag_text' => 'Structure Fuel', 'order_id' => 158],
            ['flag_id' => 159, 'flag_name' => 'StructureServiceSlot0', 'flag_text' => 'Structure Service Slot 1', 'order_id' => 159],
            ['flag_id' => 160, 'flag_name' => 'StructureServiceSlot1', 'flag_text' => 'Structure Service Slot 2', 'order_id' => 160],
            ['flag_id' => 161, 'flag_name' => 'StructureServiceSlot2', 'flag_text' => 'Structure Service Slot 3', 'order_id' => 161],
            ['flag_id' => 162, 'flag_name' => 'StructureServiceSlot3', 'flag_text' => 'Structure Service Slot 4', 'order_id' => 162],
            ['flag_id' => 163, 'flag_name' => 'StructureServiceSlot4', 'flag_text' => 'Structure Service Slot 5', 'order_id' => 163],
            ['flag_id' => 164, 'flag_name' => 'StructureServiceSlot5', 'flag_text' => 'Structure Service Slot 6', 'order_id' => 164],
            ['flag_id' => 165, 'flag_name' => 'StructureServiceSlot6', 'flag_text' => 'Structure Service Slot 7', 'order_id' => 165],
            ['flag_id' => 166, 'flag_name' => 'StructureServiceSlot7', 'flag_text' => 'Structure Service Slot 8', 'order_id' => 166],
            ['flag_id' => 175, 'flag_name' => 'SubSystemBay', 'flag_text' => 'Subsystem Bay', 'order_id' => 175],
            ['flag_id' => 176, 'flag_name' => 'SpecializedIceHold', 'flag_text' => 'Ice Hold', 'order_id' => 176],
            ['flag_id' => 177, 'flag_name' => 'CorpFleetMemberHangar', 'flag_text' => 'Corp Fleet Member Hangar', 'order_id' => 177],
            ['flag_id' => 178, 'flag_name' => 'CorpDeliveriesHangar', 'flag_text' => 'Corp Deliveries Hangar', 'order_id' => 178],
            ['flag_id' => 179, 'flag_name' => 'SpecializedMobileDepotHold', 'flag_text' => 'Mobile Depot Hold', 'order_id' => 179],
        ];

        $count = 0;
        $connection = $this->entityManager->getConnection();

        foreach ($flags as $flag) {
            $connection->insert('sde_inv_flags', $flag);
            $count++;
        }

        $this->notify($progressCallback, "  Total: {$count} flags imported (hardcoded values)");
    }

    private function importIcons(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_eve_icons');

        $data = $this->parseYamlFile('icons.yaml');

        $count = 0;
        $batchSize = 1000;
        $connection = $this->entityManager->getConnection();
        $batch = [];

        foreach ($data as $iconId => $icon) {
            $icon = (array) $icon;

            $batch[] = [
                'icon_id' => (int) $iconId,
                'icon_file' => $icon['iconFile'] ?? '',
                'description' => $this->getDescription($icon),
            ];

            $count++;

            if (count($batch) >= $batchSize) {
                foreach ($batch as $r) {
                    $connection->insert('sde_eve_icons', $r);
                }
                $batch = [];
                $this->notify($progressCallback, "  Imported {$count} icons...");
            }
        }

        if (!empty($batch)) {
            foreach ($batch as $r) {
                $connection->insert('sde_eve_icons', $r);
            }
        }

        $this->notify($progressCallback, "  Total: {$count} icons imported");
    }

    // ==================== HELPERS ====================

    private function truncateTable(string $tableName): void
    {
        $connection = $this->entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();

        $connection->executeStatement('SET session_replication_role = replica');
        $connection->executeStatement($platform->getTruncateTableSQL($tableName, true));
        $connection->executeStatement('SET session_replication_role = DEFAULT');
    }

    private function cleanup(): void
    {
        $this->filesystem->remove($this->tempDir);
    }

    private function notify(?callable $callback, string $message): void
    {
        $this->logger->info($message);
        if ($callback) {
            $callback($message);
        }
    }
}
