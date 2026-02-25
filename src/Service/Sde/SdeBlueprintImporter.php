<?php

declare(strict_types=1);

namespace App\Service\Sde;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class SdeBlueprintImporter
{
    use SdeImportTrait;

    // Activity ID mapping (name in JSONL -> EVE standard ramActivities ID)
    private const ACTIVITY_IDS = [
        'manufacturing' => 1,
        'research_time' => 3,
        'research_material' => 4,
        'copying' => 5,
        'invention' => 8,
        'reaction' => 11,
    ];

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

    public function importBlueprints(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_industry_activity_skills');
        $this->truncateTable('sde_industry_activity_products');
        $this->truncateTable('sde_industry_activity_materials');
        $this->truncateTable('sde_industry_activities');
        $this->truncateTable('sde_industry_blueprints');

        $blueprintCount = 0;
        $activityCount = 0;
        $materialCount = 0;
        $productCount = 0;
        $skillCount = 0;

        $connection = $this->getConnection();
        $seenSkills = [];

        foreach ($this->readJsonlFile('blueprints.jsonl') as $blueprintTypeId => $blueprint) {
            $connection->insert('sde_industry_blueprints', [
                'type_id' => (int) $blueprintTypeId,
                'max_production_limit' => $blueprint['maxProductionLimit'] ?? 0,
            ]);
            $blueprintCount++;

            $activities = $blueprint['activities'] ?? [];
            foreach ($activities as $activityName => $activity) {
                $activityId = self::ACTIVITY_IDS[$activityName] ?? null;
                if ($activityId === null) {
                    continue;
                }

                $connection->insert('sde_industry_activities', [
                    'type_id' => (int) $blueprintTypeId,
                    'activity_id' => $activityId,
                    'time' => $activity['time'] ?? 0,
                ]);
                $activityCount++;

                $materials = $activity['materials'] ?? [];
                foreach ($materials as $material) {
                    $connection->insert('sde_industry_activity_materials', [
                        'type_id' => (int) $blueprintTypeId,
                        'activity_id' => $activityId,
                        'material_type_id' => (int) $material['typeID'],
                        'quantity' => (int) $material['quantity'],
                    ]);
                    $materialCount++;
                }

                $products = $activity['products'] ?? [];
                foreach ($products as $product) {
                    $connection->insert('sde_industry_activity_products', [
                        'type_id' => (int) $blueprintTypeId,
                        'activity_id' => $activityId,
                        'product_type_id' => (int) $product['typeID'],
                        'quantity' => (int) $product['quantity'],
                        'probability' => $product['probability'] ?? null,
                    ]);
                    $productCount++;
                }

                $skills = $activity['skills'] ?? [];
                foreach ($skills as $skill) {
                    $skillId = (int) $skill['typeID'];
                    $level = (int) $skill['level'];

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
}
