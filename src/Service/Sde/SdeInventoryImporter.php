<?php

declare(strict_types=1);

namespace App\Service\Sde;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class SdeInventoryImporter
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

    public function importCategories(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_inv_categories');

        $count = 0;
        $connection = $this->getConnection();

        foreach ($this->readJsonlFile('categories.jsonl') as $categoryId => $category) {
            $connection->insert('sde_inv_categories', [
                'category_id' => (int) $categoryId,
                'category_name' => $this->getName($category),
                'published' => $category['published'] ?? false,
                'icon_id' => $category['iconID'] ?? null,
            ], [
                'published' => ParameterType::BOOLEAN,
            ]);

            $count++;
        }

        $this->notify($progressCallback, "  Total: {$count} categories imported");
    }

    public function importGroups(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_inv_groups');

        $validCategoryIds = [];
        $result = $this->getConnection()->executeQuery('SELECT category_id FROM sde_inv_categories');
        while ($row = $result->fetchAssociative()) {
            $validCategoryIds[(int) $row['category_id']] = true;
        }

        $count = 0;
        $batchSize = 500;
        $connection = $this->getConnection();
        $batch = [];

        foreach ($this->readJsonlFile('groups.jsonl') as $groupId => $group) {
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

    /** @param list<array<string, mixed>> $batch */
    private function insertGroupsBatch(\Doctrine\DBAL\Connection $connection, array $batch): void
    {
        foreach ($batch as $row) {
            $connection->insert('sde_inv_groups', $row, [
                'published' => ParameterType::BOOLEAN,
                'use_base_price' => ParameterType::BOOLEAN,
                'anchored' => ParameterType::BOOLEAN,
                'anchorable' => ParameterType::BOOLEAN,
                'fittable_non_singleton' => ParameterType::BOOLEAN,
            ]);
        }
    }

    public function importMarketGroups(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_inv_market_groups');

        $connection = $this->getConnection();
        $count = 0;
        $rows = [];

        foreach ($this->readJsonlFile('marketGroups.jsonl') as $marketGroupId => $marketGroup) {
            $rows[$marketGroupId] = $marketGroup;

            $connection->insert('sde_inv_market_groups', [
                'market_group_id' => (int) $marketGroupId,
                'market_group_name' => $this->getName($marketGroup),
                'description' => $this->getDescription($marketGroup),
                'icon_id' => $marketGroup['iconID'] ?? null,
                'has_types' => $marketGroup['hasTypes'] ?? false,
                'parent_group_id' => null,
            ], [
                'has_types' => ParameterType::BOOLEAN,
            ]);

            $count++;
        }

        $this->notify($progressCallback, "  Created {$count} market groups...");

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

    public function importTypes(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_inv_types');

        $validGroupIds = [];
        $result = $this->getConnection()->executeQuery('SELECT group_id FROM sde_inv_groups');
        while ($row = $result->fetchAssociative()) {
            $validGroupIds[(int) $row['group_id']] = true;
        }

        $validMarketGroupIds = [];
        $result = $this->getConnection()->executeQuery('SELECT market_group_id FROM sde_inv_market_groups');
        while ($row = $result->fetchAssociative()) {
            $validMarketGroupIds[(int) $row['market_group_id']] = true;
        }

        $count = 0;
        $batchSize = 500;
        $connection = $this->getConnection();
        $batch = [];

        foreach ($this->readJsonlFile('types.jsonl') as $typeId => $type) {
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

    /** @param list<array<string, mixed>> $batch */
    private function insertTypesBatch(\Doctrine\DBAL\Connection $connection, array $batch): void
    {
        foreach ($batch as $row) {
            $connection->insert('sde_inv_types', $row, [
                'published' => ParameterType::BOOLEAN,
            ]);
        }
    }

    public function importTypeMaterials(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_inv_type_materials');

        $count = 0;
        $batchSize = 5000;
        $connection = $this->getConnection();
        $batch = [];

        foreach ($this->readJsonlFile('typeMaterials.jsonl') as $typeId => $typeData) {
            $materials = $typeData['materials'] ?? [];

            foreach ($materials as $material) {
                $batch[] = [
                    'type_id' => (int) $typeId,
                    'material_type_id' => (int) $material['materialTypeID'],
                    'quantity' => (int) $material['quantity'],
                ];

                $count++;

                if (count($batch) >= $batchSize) {
                    $this->insertTypeMaterialsBatch($connection, $batch);
                    $batch = [];
                    $this->notify($progressCallback, "  Imported {$count} type materials...");
                }
            }
        }

        if (!empty($batch)) {
            $this->insertTypeMaterialsBatch($connection, $batch);
        }

        $this->notify($progressCallback, "  Total: {$count} type materials imported");
    }

    /** @param list<array<string, mixed>> $batch */
    private function insertTypeMaterialsBatch(\Doctrine\DBAL\Connection $connection, array $batch): void
    {
        foreach ($batch as $row) {
            $connection->insert('sde_inv_type_materials', $row);
        }
    }
}
