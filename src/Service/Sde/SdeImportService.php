<?php

declare(strict_types=1);

namespace App\Service\Sde;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SdeImportService
{
    private const SDE_URL = 'https://eve-static-data-export.s3-eu-west-1.amazonaws.com/tranquility/sde.zip';
    private const FUZZWORK_URL = 'https://www.fuzzwork.co.uk/dump/latest/';
    private const DOWNLOAD_TIMEOUT = 300;

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

    public function downloadAndImport(callable $progressCallback = null): void
    {
        $this->ensureTempDir();

        $this->notify($progressCallback, 'Downloading SDE files from Fuzzwork...');
        $this->downloadFuzzworkFiles();

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

        $this->notify($progressCallback, 'Importing solar system jumps...');
        $this->importSolarSystemJumps($progressCallback);

        // Industry
        $this->notify($progressCallback, 'Importing blueprints...');
        $this->importBlueprints($progressCallback);

        $this->notify($progressCallback, 'Importing industry activities...');
        $this->importIndustryActivities($progressCallback);

        $this->notify($progressCallback, 'Importing industry activity materials...');
        $this->importIndustryActivityMaterials($progressCallback);

        $this->notify($progressCallback, 'Importing industry activity products...');
        $this->importIndustryActivityProducts($progressCallback);

        $this->notify($progressCallback, 'Importing industry activity skills...');
        $this->importIndustryActivitySkills($progressCallback);

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

    private function downloadFuzzworkFiles(): void
    {
        $files = [
            // Core inventory
            'invCategories.csv',
            'invGroups.csv',
            'invTypes.csv',
            'invMarketGroups.csv',
            'invFlags.csv',
            // Map data
            'mapRegions.csv',
            'mapConstellations.csv',
            'mapSolarSystems.csv',
            'mapSolarSystemJumps.csv',
            'staStations.csv',
            // Industry
            'industryBlueprints.csv',
            'industryActivity.csv',
            'industryActivityMaterials.csv',
            'industryActivityProducts.csv',
            'industryActivitySkills.csv',
            // Dogma
            'dgmAttributeTypes.csv',
            'dgmTypeAttributes.csv',
            'dgmEffects.csv',
            'dgmTypeEffects.csv',
            // Reference
            'chrRaces.csv',
            'chrFactions.csv',
            'eveIcons.csv',
        ];

        foreach ($files as $file) {
            $url = self::FUZZWORK_URL . $file . '.bz2';
            $localPath = $this->tempDir . '/' . $file . '.bz2';
            $decompressedPath = $this->tempDir . '/' . $file;

            if (!$this->filesystem->exists($decompressedPath)) {
                $this->logger->info("Downloading {$file}...");

                try {
                    $response = $this->httpClient->request('GET', $url, [
                        'timeout' => self::DOWNLOAD_TIMEOUT,
                    ]);
                    $this->filesystem->dumpFile($localPath, $response->getContent());
                } catch (TransportExceptionInterface $e) {
                    throw new \RuntimeException("Failed to download {$file}: " . $e->getMessage());
                }

                // Decompress bz2 using shell command
                $result = null;
                $output = [];
                exec("bunzip2 -k {$localPath} 2>&1", $output, $result);

                if ($result !== 0) {
                    throw new \RuntimeException("Failed to decompress {$file}: " . implode("\n", $output));
                }

                $this->filesystem->remove($localPath);
            }
        }
    }

    private function importCategories(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_inv_categories');

        $file = $this->tempDir . '/invCategories.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        $count = 0;
        $connection = $this->entityManager->getConnection();

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            $connection->insert('sde_inv_categories', [
                'category_id' => (int) $data['categoryID'],
                'category_name' => $data['categoryName'],
                'published' => $this->toBool($data['published']),
                'icon_id' => $this->intOrNull($data['iconID']),
            ], [
                'published' => \Doctrine\DBAL\ParameterType::BOOLEAN,
            ]);

            $count++;
        }

        fclose($handle);
        $this->notify($progressCallback, "  Total: {$count} categories imported");
    }

    private function importGroups(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_inv_groups');

        $file = $this->tempDir . '/invGroups.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        $count = 0;
        $batchSize = 500;

        // Pre-load category IDs only
        $validCategoryIds = [];
        $result = $this->entityManager->getConnection()->executeQuery('SELECT category_id FROM sde_inv_categories');
        while ($row = $result->fetchAssociative()) {
            $validCategoryIds[(int) $row['category_id']] = true;
        }

        $connection = $this->entityManager->getConnection();
        $batch = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            $categoryId = (int) $data['categoryID'];
            if (!isset($validCategoryIds[$categoryId])) {
                continue;
            }

            $batch[] = [
                'group_id' => (int) $data['groupID'],
                'group_name' => $data['groupName'],
                'category_id' => $categoryId,
                'published' => in_array($data['published'], ['1', 'True', 'true'], true),
                'icon_id' => $data['iconID'] && is_numeric($data['iconID']) ? (int) $data['iconID'] : null,
                'use_base_price' => in_array($data['useBasePrice'], ['1', 'True', 'true'], true),
                'anchored' => in_array($data['anchored'], ['1', 'True', 'true'], true),
                'anchorable' => in_array($data['anchorable'], ['1', 'True', 'true'], true),
                'fittable_non_singleton' => in_array($data['fittableNonSingleton'], ['1', 'True', 'true'], true),
            ];

            $count++;

            if (count($batch) >= $batchSize) {
                foreach ($batch as $r) {
                    $connection->insert('sde_inv_groups', $r, [
                        'published' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                        'use_base_price' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                        'anchored' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                        'anchorable' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                        'fittable_non_singleton' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                    ]);
                }
                $batch = [];
                $this->notify($progressCallback, "  Imported {$count} groups...");
            }
        }

        if (!empty($batch)) {
            foreach ($batch as $r) {
                $connection->insert('sde_inv_groups', $r, [
                    'published' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                    'use_base_price' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                    'anchored' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                    'anchorable' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                    'fittable_non_singleton' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                ]);
            }
        }

        fclose($handle);
        $this->notify($progressCallback, "  Total: {$count} groups imported");
    }

    private function importMarketGroups(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_inv_market_groups');

        $file = $this->tempDir . '/invMarketGroups.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        // First pass: read all rows and insert without parent references
        $rows = [];
        $connection = $this->entityManager->getConnection();
        $count = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);
            $rows[] = $data;

            $connection->insert('sde_inv_market_groups', [
                'market_group_id' => (int) $data['marketGroupID'],
                'market_group_name' => $data['marketGroupName'] ?? '',
                'description' => $data['description'] ?: null,
                'icon_id' => $data['iconID'] && is_numeric($data['iconID']) ? (int) $data['iconID'] : null,
                'has_types' => in_array($data['hasTypes'], ['1', 'True', 'true'], true),
                'parent_group_id' => null,
            ], [
                'has_types' => \Doctrine\DBAL\ParameterType::BOOLEAN,
            ]);

            $count++;
        }
        fclose($handle);

        $this->notify($progressCallback, "  Created {$count} market groups...");

        // Second pass: update parent references
        $updated = 0;
        foreach ($rows as $data) {
            $parentId = $data['parentGroupID'] && is_numeric($data['parentGroupID']) ? (int) $data['parentGroupID'] : null;
            if ($parentId) {
                $marketGroupId = (int) $data['marketGroupID'];
                $connection->update('sde_inv_market_groups', [
                    'parent_group_id' => $parentId,
                ], [
                    'market_group_id' => $marketGroupId,
                ]);
                $updated++;
            }
        }

        $this->notify($progressCallback, "  Total: {$count} market groups imported ({$updated} with parents)");
    }

    private function importTypes(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_inv_types');

        $file = $this->tempDir . '/invTypes.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        $count = 0;
        $batchSize = 500;

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

        $connection = $this->entityManager->getConnection();
        $batch = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            $groupId = (int) $data['groupID'];
            if (!isset($validGroupIds[$groupId])) {
                continue;
            }

            $marketGroupId = $data['marketGroupID'] && is_numeric($data['marketGroupID']) ? (int) $data['marketGroupID'] : null;
            if ($marketGroupId && !isset($validMarketGroupIds[$marketGroupId])) {
                $marketGroupId = null;
            }

            $batch[] = [
                'type_id' => (int) $data['typeID'],
                'type_name' => $data['typeName'],
                'description' => $data['description'] ?: null,
                'group_id' => $groupId,
                'mass' => $data['mass'] && is_numeric($data['mass']) ? (float) $data['mass'] : null,
                'volume' => $data['volume'] && is_numeric($data['volume']) ? (float) $data['volume'] : null,
                'capacity' => $data['capacity'] && is_numeric($data['capacity']) ? (float) $data['capacity'] : null,
                'portion_size' => $data['portionSize'] && is_numeric($data['portionSize']) ? (int) $data['portionSize'] : null,
                'base_price' => $data['basePrice'] && is_numeric($data['basePrice']) ? $data['basePrice'] : null,
                'published' => in_array($data['published'], ['1', 'True', 'true'], true),
                'market_group_id' => $marketGroupId,
                'icon_id' => $data['iconID'] && is_numeric($data['iconID']) ? (int) $data['iconID'] : null,
                'graphic_id' => $data['graphicID'] && is_numeric($data['graphicID']) ? (int) $data['graphicID'] : null,
                'race_id' => $data['raceID'] && is_numeric($data['raceID']) ? (int) $data['raceID'] : null,
                'sof_faction_name' => null,
                'sound_id' => $data['soundID'] && is_numeric($data['soundID']) ? (int) $data['soundID'] : null,
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

        fclose($handle);

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

    private function importRegions(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_map_regions');

        $file = $this->tempDir . '/mapRegions.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        $count = 0;
        $connection = $this->entityManager->getConnection();

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            $connection->insert('sde_map_regions', [
                'region_id' => (int) $data['regionID'],
                'region_name' => $data['regionName'],
                'x' => $data['x'] && is_numeric($data['x']) ? (float) $data['x'] : null,
                'y' => $data['y'] && is_numeric($data['y']) ? (float) $data['y'] : null,
                'z' => $data['z'] && is_numeric($data['z']) ? (float) $data['z'] : null,
                'x_min' => $data['xMin'] && is_numeric($data['xMin']) ? (float) $data['xMin'] : null,
                'x_max' => $data['xMax'] && is_numeric($data['xMax']) ? (float) $data['xMax'] : null,
                'y_min' => $data['yMin'] && is_numeric($data['yMin']) ? (float) $data['yMin'] : null,
                'y_max' => $data['yMax'] && is_numeric($data['yMax']) ? (float) $data['yMax'] : null,
                'z_min' => $data['zMin'] && is_numeric($data['zMin']) ? (float) $data['zMin'] : null,
                'z_max' => $data['zMax'] && is_numeric($data['zMax']) ? (float) $data['zMax'] : null,
                'faction_id' => $data['factionID'] && is_numeric($data['factionID']) ? (int) $data['factionID'] : null,
                'radius' => $data['radius'] && is_numeric($data['radius']) ? (float) $data['radius'] : null,
            ]);

            $count++;
        }

        fclose($handle);
        $this->notify($progressCallback, "  Total: {$count} regions imported");
    }

    private function importConstellations(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_map_constellations');

        $file = $this->tempDir . '/mapConstellations.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        $count = 0;
        $batchSize = 500;

        // Pre-load region IDs only
        $validRegionIds = [];
        $result = $this->entityManager->getConnection()->executeQuery('SELECT region_id FROM sde_map_regions');
        while ($row = $result->fetchAssociative()) {
            $validRegionIds[(int) $row['region_id']] = true;
        }

        $connection = $this->entityManager->getConnection();
        $batch = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            $regionId = (int) $data['regionID'];
            if (!isset($validRegionIds[$regionId])) {
                continue;
            }

            $batch[] = [
                'constellation_id' => (int) $data['constellationID'],
                'constellation_name' => $data['constellationName'],
                'region_id' => $regionId,
                'x' => $data['x'] && is_numeric($data['x']) ? (float) $data['x'] : null,
                'y' => $data['y'] && is_numeric($data['y']) ? (float) $data['y'] : null,
                'z' => $data['z'] && is_numeric($data['z']) ? (float) $data['z'] : null,
                'x_min' => $data['xMin'] && is_numeric($data['xMin']) ? (float) $data['xMin'] : null,
                'x_max' => $data['xMax'] && is_numeric($data['xMax']) ? (float) $data['xMax'] : null,
                'y_min' => $data['yMin'] && is_numeric($data['yMin']) ? (float) $data['yMin'] : null,
                'y_max' => $data['yMax'] && is_numeric($data['yMax']) ? (float) $data['yMax'] : null,
                'z_min' => $data['zMin'] && is_numeric($data['zMin']) ? (float) $data['zMin'] : null,
                'z_max' => $data['zMax'] && is_numeric($data['zMax']) ? (float) $data['zMax'] : null,
                'faction_id' => $data['factionID'] && is_numeric($data['factionID']) ? (int) $data['factionID'] : null,
                'radius' => $data['radius'] && is_numeric($data['radius']) ? (float) $data['radius'] : null,
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

        fclose($handle);
        $this->notify($progressCallback, "  Total: {$count} constellations imported");
    }

    private function importSolarSystems(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_map_solar_systems');

        $file = $this->tempDir . '/mapSolarSystems.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        $count = 0;
        $batchSize = 500;

        // Pre-load constellation IDs only (not entities to save memory)
        $validConstellationIds = [];
        $result = $this->entityManager->getConnection()->executeQuery('SELECT constellation_id FROM sde_map_constellations');
        while ($row = $result->fetchAssociative()) {
            $validConstellationIds[(int) $row['constellation_id']] = true;
        }

        $connection = $this->entityManager->getConnection();
        $batch = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            $constellationId = (int) $data['constellationID'];
            if (!isset($validConstellationIds[$constellationId])) {
                continue;
            }

            $batch[] = [
                'solar_system_id' => (int) $data['solarSystemID'],
                'solar_system_name' => $data['solarSystemName'],
                'constellation_id' => $constellationId,
                'region_id' => (int) $data['regionID'],
                'x' => $data['x'] && is_numeric($data['x']) ? (float) $data['x'] : null,
                'y' => $data['y'] && is_numeric($data['y']) ? (float) $data['y'] : null,
                'z' => $data['z'] && is_numeric($data['z']) ? (float) $data['z'] : null,
                'x_min' => $data['xMin'] && is_numeric($data['xMin']) ? (float) $data['xMin'] : null,
                'x_max' => $data['xMax'] && is_numeric($data['xMax']) ? (float) $data['xMax'] : null,
                'y_min' => $data['yMin'] && is_numeric($data['yMin']) ? (float) $data['yMin'] : null,
                'y_max' => $data['yMax'] && is_numeric($data['yMax']) ? (float) $data['yMax'] : null,
                'z_min' => $data['zMin'] && is_numeric($data['zMin']) ? (float) $data['zMin'] : null,
                'z_max' => $data['zMax'] && is_numeric($data['zMax']) ? (float) $data['zMax'] : null,
                'security' => (float) ($data['security'] ?? 0),
                'true_security_status' => isset($data['trueSecurityStatus']) && is_numeric($data['trueSecurityStatus']) ? (float) $data['trueSecurityStatus'] : null,
                'faction_id' => $data['factionID'] && is_numeric($data['factionID']) ? (int) $data['factionID'] : null,
                'radius' => $data['radius'] && is_numeric($data['radius']) ? (float) $data['radius'] : null,
                'sun_type_id' => $data['sunTypeID'] && is_numeric($data['sunTypeID']) ? (int) $data['sunTypeID'] : null,
                'security_class' => $data['securityClass'] ?: null,
                'border' => in_array($data['border'], ['1', 'True', 'true'], true),
                'fringe' => in_array($data['fringe'], ['1', 'True', 'true'], true),
                'corridor' => in_array($data['corridor'], ['1', 'True', 'true'], true),
                'hub' => in_array($data['hub'], ['1', 'True', 'true'], true),
                'international' => in_array($data['international'], ['1', 'True', 'true'], true),
                'regional' => in_array($data['regional'], ['1', 'True', 'true'], true),
            ];

            $count++;

            if (count($batch) >= $batchSize) {
                foreach ($batch as $r) {
                    $connection->insert('sde_map_solar_systems', $r, [
                        'border' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                        'fringe' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                        'corridor' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                        'hub' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                        'international' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                        'regional' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                    ]);
                }
                $batch = [];
                $this->notify($progressCallback, "  Imported {$count} solar systems...");
            }
        }

        if (!empty($batch)) {
            foreach ($batch as $r) {
                $connection->insert('sde_map_solar_systems', $r, [
                    'border' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                    'fringe' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                    'corridor' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                    'hub' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                    'international' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                    'regional' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                ]);
            }
        }

        fclose($handle);
        $this->notify($progressCallback, "  Total: {$count} solar systems imported");
    }

    private function importStations(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_sta_stations');

        $file = $this->tempDir . '/staStations.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        $count = 0;
        $batchSize = 500;

        // Pre-load valid solar system IDs
        $validSolarSystemIds = [];
        $result = $this->entityManager->getConnection()->executeQuery('SELECT solar_system_id FROM sde_map_solar_systems');
        while ($row = $result->fetchAssociative()) {
            $validSolarSystemIds[(int) $row['solar_system_id']] = true;
        }

        $connection = $this->entityManager->getConnection();
        $batch = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            $solarSystemId = (int) $data['solarSystemID'];
            if (!isset($validSolarSystemIds[$solarSystemId])) {
                continue;
            }

            $batch[] = [
                'station_id' => (int) $data['stationID'],
                'station_name' => $data['stationName'],
                'solar_system_id' => $solarSystemId,
                'constellation_id' => (int) $data['constellationID'],
                'region_id' => (int) $data['regionID'],
                'station_type_id' => $data['stationTypeID'] && is_numeric($data['stationTypeID']) ? (int) $data['stationTypeID'] : null,
                'corporation_id' => $data['corporationID'] && is_numeric($data['corporationID']) ? (int) $data['corporationID'] : null,
                'x' => $data['x'] && is_numeric($data['x']) ? (float) $data['x'] : null,
                'y' => $data['y'] && is_numeric($data['y']) ? (float) $data['y'] : null,
                'z' => $data['z'] && is_numeric($data['z']) ? (float) $data['z'] : null,
                'security' => $data['security'] && is_numeric($data['security']) ? (float) $data['security'] : null,
                'docking_cost_per_volume' => $data['dockingCostPerVolume'] && is_numeric($data['dockingCostPerVolume']) ? (float) $data['dockingCostPerVolume'] : null,
                'max_ship_volume_dockable' => $data['maxShipVolumeDockable'] && is_numeric($data['maxShipVolumeDockable']) ? (float) $data['maxShipVolumeDockable'] : null,
                'office_rental_cost' => $data['officeRentalCost'] && is_numeric($data['officeRentalCost']) ? (int) $data['officeRentalCost'] : null,
                'reprocessing_efficiency' => $data['reprocessingEfficiency'] && is_numeric($data['reprocessingEfficiency']) ? (float) $data['reprocessingEfficiency'] : null,
                'reprocessing_stations_take' => $data['reprocessingStationsTake'] && is_numeric($data['reprocessingStationsTake']) ? (float) $data['reprocessingStationsTake'] : null,
                'operation_id' => $data['operationID'] && is_numeric($data['operationID']) ? (int) $data['operationID'] : null,
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

        fclose($handle);

        $this->notify($progressCallback, "  Total: {$count} stations imported");
    }

    private function insertStationsBatch($connection, array $batch): void
    {
        foreach ($batch as $row) {
            $connection->insert('sde_sta_stations', $row);
        }
    }

    private function importSolarSystemJumps(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_map_solar_system_jumps');

        $file = $this->tempDir . '/mapSolarSystemJumps.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        $count = 0;
        $batchSize = 1000;

        $connection = $this->entityManager->getConnection();
        $batch = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            $batch[] = [
                'from_solar_system_id' => (int) $data['fromSolarSystemID'],
                'to_solar_system_id' => (int) $data['toSolarSystemID'],
                'from_region_id' => (int) $data['fromRegionID'],
                'from_constellation_id' => (int) $data['fromConstellationID'],
                'to_region_id' => (int) $data['toRegionID'],
                'to_constellation_id' => (int) $data['toConstellationID'],
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

        fclose($handle);

        $this->notify($progressCallback, "  Total: {$count} jumps imported");
    }

    private function insertJumpsBatch($connection, array $batch): void
    {
        foreach ($batch as $row) {
            $connection->insert('sde_map_solar_system_jumps', $row);
        }
    }

    // ==================== INDUSTRY ====================

    private function importBlueprints(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_industry_blueprints');

        $file = $this->tempDir . '/industryBlueprints.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        $count = 0;
        $batchSize = 1000;
        $connection = $this->entityManager->getConnection();
        $batch = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            $batch[] = [
                'type_id' => (int) $data['typeID'],
                'max_production_limit' => (int) $data['maxProductionLimit'],
            ];

            $count++;

            if (count($batch) >= $batchSize) {
                foreach ($batch as $r) {
                    $connection->insert('sde_industry_blueprints', $r);
                }
                $batch = [];
                $this->notify($progressCallback, "  Imported {$count} blueprints...");
            }
        }

        if (!empty($batch)) {
            foreach ($batch as $r) {
                $connection->insert('sde_industry_blueprints', $r);
            }
        }

        fclose($handle);
        $this->notify($progressCallback, "  Total: {$count} blueprints imported");
    }

    private function importIndustryActivities(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_industry_activities');

        $file = $this->tempDir . '/industryActivity.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        $count = 0;
        $batchSize = 1000;
        $connection = $this->entityManager->getConnection();
        $batch = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            $batch[] = [
                'type_id' => (int) $data['typeID'],
                'activity_id' => (int) $data['activityID'],
                'time' => (int) $data['time'],
            ];

            $count++;

            if (count($batch) >= $batchSize) {
                foreach ($batch as $r) {
                    $connection->insert('sde_industry_activities', $r);
                }
                $batch = [];
                $this->notify($progressCallback, "  Imported {$count} activities...");
            }
        }

        if (!empty($batch)) {
            foreach ($batch as $r) {
                $connection->insert('sde_industry_activities', $r);
            }
        }

        fclose($handle);
        $this->notify($progressCallback, "  Total: {$count} industry activities imported");
    }

    private function importIndustryActivityMaterials(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_industry_activity_materials');

        $file = $this->tempDir . '/industryActivityMaterials.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        $count = 0;
        $batchSize = 1000;
        $connection = $this->entityManager->getConnection();
        $batch = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            $batch[] = [
                'type_id' => (int) $data['typeID'],
                'activity_id' => (int) $data['activityID'],
                'material_type_id' => (int) $data['materialTypeID'],
                'quantity' => (int) $data['quantity'],
            ];

            $count++;

            if (count($batch) >= $batchSize) {
                foreach ($batch as $r) {
                    $connection->insert('sde_industry_activity_materials', $r);
                }
                $batch = [];
                $this->notify($progressCallback, "  Imported {$count} materials...");
            }
        }

        if (!empty($batch)) {
            foreach ($batch as $r) {
                $connection->insert('sde_industry_activity_materials', $r);
            }
        }

        fclose($handle);
        $this->notify($progressCallback, "  Total: {$count} industry activity materials imported");
    }

    private function importIndustryActivityProducts(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_industry_activity_products');

        $file = $this->tempDir . '/industryActivityProducts.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        $count = 0;
        $batchSize = 1000;
        $connection = $this->entityManager->getConnection();
        $batch = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            $batch[] = [
                'type_id' => (int) $data['typeID'],
                'activity_id' => (int) $data['activityID'],
                'product_type_id' => (int) $data['productTypeID'],
                'quantity' => (int) $data['quantity'],
            ];

            $count++;

            if (count($batch) >= $batchSize) {
                foreach ($batch as $r) {
                    $connection->insert('sde_industry_activity_products', $r);
                }
                $batch = [];
                $this->notify($progressCallback, "  Imported {$count} products...");
            }
        }

        if (!empty($batch)) {
            foreach ($batch as $r) {
                $connection->insert('sde_industry_activity_products', $r);
            }
        }

        fclose($handle);
        $this->notify($progressCallback, "  Total: {$count} industry activity products imported");
    }

    private function importIndustryActivitySkills(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_industry_activity_skills');

        $file = $this->tempDir . '/industryActivitySkills.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        $count = 0;
        $skipped = 0;
        $batchSize = 1000;
        $connection = $this->entityManager->getConnection();
        $seen = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            $key = $data['typeID'] . '-' . $data['activityID'] . '-' . $data['skillID'];
            if (isset($seen[$key])) {
                $skipped++;
                continue;
            }
            $seen[$key] = true;

            $connection->insert('sde_industry_activity_skills', [
                'type_id' => (int) $data['typeID'],
                'activity_id' => (int) $data['activityID'],
                'skill_id' => (int) $data['skillID'],
                'level' => (int) $data['level'],
            ]);

            $count++;

            if ($count % $batchSize === 0) {
                $this->notify($progressCallback, "  Imported {$count} skills...");
            }
        }

        fclose($handle);
        $this->notify($progressCallback, "  Total: {$count} industry activity skills imported ({$skipped} duplicates skipped)");
    }

    // ==================== DOGMA ====================

    private function importAttributeTypes(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_dgm_attribute_types');

        $file = $this->tempDir . '/dgmAttributeTypes.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        $count = 0;
        $batchSize = 500;
        $connection = $this->entityManager->getConnection();
        $batch = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            $batch[] = [
                'attribute_id' => (int) $data['attributeID'],
                'attribute_name' => $this->nullIfNone($data['attributeName']),
                'description' => $this->nullIfNone($data['description']),
                'icon_id' => $this->intOrNull($data['iconID']),
                'default_value' => $this->floatOrNull($data['defaultValue']),
                'published' => in_array($data['published'], ['1', 'True', 'true'], true),
                'display_name' => $this->nullIfNone($data['displayName']),
                'unit_id' => $this->intOrNull($data['unitID']),
                'stackable' => in_array($data['stackable'], ['1', 'True', 'true'], true),
                'high_is_good' => in_array($data['highIsGood'], ['1', 'True', 'true'], true),
                'category_id' => $this->intOrNull($data['categoryID']),
            ];

            $count++;

            if (count($batch) >= $batchSize) {
                foreach ($batch as $r) {
                    $connection->insert('sde_dgm_attribute_types', $r, [
                        'published' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                        'stackable' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                        'high_is_good' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                    ]);
                }
                $batch = [];
                $this->notify($progressCallback, "  Imported {$count} attribute types...");
            }
        }

        if (!empty($batch)) {
            foreach ($batch as $r) {
                $connection->insert('sde_dgm_attribute_types', $r, [
                    'published' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                    'stackable' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                    'high_is_good' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                ]);
            }
        }

        fclose($handle);
        $this->notify($progressCallback, "  Total: {$count} attribute types imported");
    }

    private function importTypeAttributes(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_dgm_type_attributes');

        $file = $this->tempDir . '/dgmTypeAttributes.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        $count = 0;
        $batchSize = 5000;
        $connection = $this->entityManager->getConnection();
        $batch = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            $batch[] = [
                'type_id' => (int) $data['typeID'],
                'attribute_id' => (int) $data['attributeID'],
                'value_int' => $this->intOrNull($data['valueInt']),
                'value_float' => $this->floatOrNull($data['valueFloat']),
            ];

            $count++;

            if (count($batch) >= $batchSize) {
                foreach ($batch as $r) {
                    $connection->insert('sde_dgm_type_attributes', $r);
                }
                $batch = [];
                $this->notify($progressCallback, "  Imported {$count} type attributes...");
            }
        }

        if (!empty($batch)) {
            foreach ($batch as $r) {
                $connection->insert('sde_dgm_type_attributes', $r);
            }
        }

        fclose($handle);
        $this->notify($progressCallback, "  Total: {$count} type attributes imported");
    }

    private function importEffects(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_dgm_effects');

        $file = $this->tempDir . '/dgmEffects.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        $count = 0;
        $connection = $this->entityManager->getConnection();

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            $connection->insert('sde_dgm_effects', [
                'effect_id' => (int) $data['effectID'],
                'effect_name' => $this->nullIfNone($data['effectName']),
                'effect_category' => $this->intOrNull($data['effectCategory']),
                'pre_expression' => $this->intOrNull($data['preExpression']),
                'post_expression' => $this->intOrNull($data['postExpression']),
                'description' => $this->nullIfNone($data['description']),
                'guid' => $this->nullIfNone($data['guid']),
                'icon_id' => $this->intOrNull($data['iconID']),
                'is_offensive' => in_array($data['isOffensive'], ['1', 'True', 'true'], true),
                'is_assistance' => in_array($data['isAssistance'], ['1', 'True', 'true'], true),
                'duration_attribute_id' => $this->intOrNull($data['durationAttributeID']),
                'tracking_speed_attribute_id' => $this->intOrNull($data['trackingSpeedAttributeID']),
                'discharge_attribute_id' => $this->intOrNull($data['dischargeAttributeID']),
                'range_attribute_id' => $this->intOrNull($data['rangeAttributeID']),
                'falloff_attribute_id' => $this->intOrNull($data['falloffAttributeID']),
                'disallow_auto_repeat' => in_array($data['disallowAutoRepeat'], ['1', 'True', 'true'], true),
                'published' => in_array($data['published'], ['1', 'True', 'true'], true),
                'display_name' => $this->nullIfNone($data['displayName']),
                'is_warp_safe' => in_array($data['isWarpSafe'], ['1', 'True', 'true'], true),
                'range_chance' => in_array($data['rangeChance'], ['1', 'True', 'true'], true),
                'electronic_chance' => in_array($data['electronicChance'], ['1', 'True', 'true'], true),
                'propulsion_chance' => in_array($data['propulsionChance'], ['1', 'True', 'true'], true),
                'distribution' => $this->intOrNull($data['distribution']),
                'sfx_name' => $this->nullIfNone($data['sfxName']),
                'npc_usage_chance_attribute_id' => $this->intOrNull($data['npcUsageChanceAttributeID']),
                'npc_activation_chance_attribute_id' => $this->intOrNull($data['npcActivationChanceAttributeID']),
                'fitting_usage_chance_attribute_id' => $this->intOrNull($data['fittingUsageChanceAttributeID']),
                'modifier_info' => $this->nullIfNone($data['modifierInfo']),
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

        fclose($handle);
        $this->notify($progressCallback, "  Total: {$count} effects imported");
    }

    private function importTypeEffects(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_dgm_type_effects');

        $file = $this->tempDir . '/dgmTypeEffects.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        $count = 0;
        $batchSize = 5000;
        $connection = $this->entityManager->getConnection();
        $batch = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            $batch[] = [
                'type_id' => (int) $data['typeID'],
                'effect_id' => (int) $data['effectID'],
                'is_default' => in_array($data['isDefault'], ['1', 'True', 'true'], true),
            ];

            $count++;

            if (count($batch) >= $batchSize) {
                foreach ($batch as $r) {
                    $connection->insert('sde_dgm_type_effects', $r, [
                        'is_default' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                    ]);
                }
                $batch = [];
                $this->notify($progressCallback, "  Imported {$count} type effects...");
            }
        }

        if (!empty($batch)) {
            foreach ($batch as $r) {
                $connection->insert('sde_dgm_type_effects', $r, [
                    'is_default' => \Doctrine\DBAL\ParameterType::BOOLEAN,
                ]);
            }
        }

        fclose($handle);
        $this->notify($progressCallback, "  Total: {$count} type effects imported");
    }

    // ==================== REFERENCE ====================

    private function importRaces(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_chr_races');

        $file = $this->tempDir . '/chrRaces.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        $count = 0;
        $connection = $this->entityManager->getConnection();

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            $connection->insert('sde_chr_races', [
                'race_id' => (int) $data['raceID'],
                'race_name' => $data['raceName'],
                'description' => $this->nullIfNone($data['description']),
                'icon_id' => $this->intOrNull($data['iconID']),
                'short_description' => $this->nullIfNone($data['shortDescription']),
            ]);

            $count++;
        }

        fclose($handle);
        $this->notify($progressCallback, "  Total: {$count} races imported");
    }

    private function importFactions(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_chr_factions');

        $file = $this->tempDir . '/chrFactions.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        $count = 0;
        $connection = $this->entityManager->getConnection();

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            $connection->insert('sde_chr_factions', [
                'faction_id' => (int) $data['factionID'],
                'faction_name' => $data['factionName'],
                'description' => $this->nullIfNone($data['description']),
                'race_ids' => $this->intOrNull($data['raceIDs']),
                'solar_system_id' => $this->intOrNull($data['solarSystemID']),
                'corporation_id' => $this->intOrNull($data['corporationID']),
                'size_factor' => $this->floatOrNull($data['sizeFactor']),
                'station_count' => $this->intOrNull($data['stationCount']),
                'station_system_count' => $this->intOrNull($data['stationSystemCount']),
                'militia_corporation_id' => $this->intOrNull($data['militiaCorporationID']),
                'icon_id' => $this->intOrNull($data['iconID']),
            ]);

            $count++;
        }

        fclose($handle);
        $this->notify($progressCallback, "  Total: {$count} factions imported");
    }

    private function importFlags(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_inv_flags');

        $file = $this->tempDir . '/invFlags.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        $count = 0;
        $connection = $this->entityManager->getConnection();

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            $connection->insert('sde_inv_flags', [
                'flag_id' => (int) $data['flagID'],
                'flag_name' => $this->nullIfNone($data['flagName']),
                'flag_text' => $this->nullIfNone($data['flagText']),
                'order_id' => (int) $data['orderID'],
            ]);

            $count++;
        }

        fclose($handle);
        $this->notify($progressCallback, "  Total: {$count} flags imported");
    }

    private function importIcons(callable $progressCallback = null): void
    {
        $this->truncateTable('sde_eve_icons');

        $file = $this->tempDir . '/eveIcons.csv';
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);

        $count = 0;
        $batchSize = 1000;
        $connection = $this->entityManager->getConnection();
        $batch = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            $batch[] = [
                'icon_id' => (int) $data['iconID'],
                'icon_file' => $data['iconFile'],
                'description' => $this->nullIfNone($data['description']),
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

        fclose($handle);
        $this->notify($progressCallback, "  Total: {$count} icons imported");
    }

    // ==================== HELPERS ====================

    private function toBool(mixed $value): bool
    {
        return in_array($value, ['1', 'True', 'true'], true);
    }

    private function nullIfNone(?string $value): ?string
    {
        return ($value === null || $value === 'None' || $value === '') ? null : $value;
    }

    private function intOrNull(?string $value): ?int
    {
        return ($value === null || $value === 'None' || $value === '' || !is_numeric($value))
            ? null
            : (int) $value;
    }

    private function floatOrNull(?string $value): ?float
    {
        return ($value === null || $value === 'None' || $value === '' || !is_numeric($value))
            ? null
            : (float) $value;
    }

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
