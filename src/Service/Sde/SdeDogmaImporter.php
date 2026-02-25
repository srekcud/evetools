<?php

declare(strict_types=1);

namespace App\Service\Sde;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class SdeDogmaImporter
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

    public function importAttributeTypes(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_dgm_attribute_types');

        $count = 0;
        $batchSize = 500;
        $connection = $this->getConnection();
        $batch = [];

        foreach ($this->readJsonlFile('dogmaAttributes.jsonl') as $attributeId => $attribute) {
            $displayName = $attribute['displayName'] ?? null;
            if (is_array($displayName)) {
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

    /** @param list<array<string, mixed>> $batch */
    private function insertAttributeTypesBatch(\Doctrine\DBAL\Connection $connection, array $batch): void
    {
        foreach ($batch as $row) {
            $connection->insert('sde_dgm_attribute_types', $row, [
                'published' => ParameterType::BOOLEAN,
                'stackable' => ParameterType::BOOLEAN,
                'high_is_good' => ParameterType::BOOLEAN,
            ]);
        }
    }

    public function importTypeAttributes(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_dgm_type_attributes');

        $count = 0;
        $batchSize = 5000;
        $connection = $this->getConnection();
        $batch = [];

        foreach ($this->readJsonlFile('typeDogma.jsonl') as $typeId => $typeData) {
            $attributes = $typeData['dogmaAttributes'] ?? [];

            foreach ($attributes as $attribute) {
                $value = $attribute['value'] ?? null;
                $valueInt = null;
                $valueFloat = null;

                if ($value !== null) {
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

    /** @param list<array<string, mixed>> $batch */
    private function insertTypeAttributesBatch(\Doctrine\DBAL\Connection $connection, array $batch): void
    {
        foreach ($batch as $row) {
            $connection->insert('sde_dgm_type_attributes', $row);
        }
    }

    public function importEffects(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_dgm_effects');

        $count = 0;
        $connection = $this->getConnection();

        foreach ($this->readJsonlFile('dogmaEffects.jsonl') as $effectId => $effect) {
            $connection->insert('sde_dgm_effects', [
                'effect_id' => (int) $effectId,
                'effect_name' => $this->getString($effect, 'effectName'),
                'effect_category' => $effect['effectCategory'] ?? null,
                'pre_expression' => $effect['preExpression'] ?? null,
                'post_expression' => $effect['postExpression'] ?? null,
                'description' => $this->getDescription($effect),
                'guid' => $this->getString($effect, 'guid'),
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
                'display_name' => $this->getString($effect, 'displayName'),
                'is_warp_safe' => $effect['isWarpSafe'] ?? false,
                'range_chance' => $effect['rangeChance'] ?? false,
                'electronic_chance' => $effect['electronicChance'] ?? false,
                'propulsion_chance' => $effect['propulsionChance'] ?? false,
                'distribution' => $effect['distribution'] ?? null,
                'sfx_name' => $this->getString($effect, 'sfxName'),
                'npc_usage_chance_attribute_id' => $effect['npcUsageChanceAttributeID'] ?? null,
                'npc_activation_chance_attribute_id' => $effect['npcActivationChanceAttributeID'] ?? null,
                'fitting_usage_chance_attribute_id' => $effect['fittingUsageChanceAttributeID'] ?? null,
                'modifier_info' => isset($effect['modifierInfo']) ? json_encode($effect['modifierInfo']) : null,
            ], [
                'is_offensive' => ParameterType::BOOLEAN,
                'is_assistance' => ParameterType::BOOLEAN,
                'disallow_auto_repeat' => ParameterType::BOOLEAN,
                'published' => ParameterType::BOOLEAN,
                'is_warp_safe' => ParameterType::BOOLEAN,
                'range_chance' => ParameterType::BOOLEAN,
                'electronic_chance' => ParameterType::BOOLEAN,
                'propulsion_chance' => ParameterType::BOOLEAN,
            ]);

            $count++;
        }

        $this->notify($progressCallback, "  Total: {$count} effects imported");
    }

    public function importTypeEffects(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_dgm_type_effects');

        $count = 0;
        $batchSize = 5000;
        $connection = $this->getConnection();
        $batch = [];

        foreach ($this->readJsonlFile('typeDogma.jsonl') as $typeId => $typeData) {
            $effects = $typeData['dogmaEffects'] ?? [];

            foreach ($effects as $effect) {
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

    /** @param list<array<string, mixed>> $batch */
    private function insertTypeEffectsBatch(\Doctrine\DBAL\Connection $connection, array $batch): void
    {
        foreach ($batch as $row) {
            $connection->insert('sde_dgm_type_effects', $row, [
                'is_default' => ParameterType::BOOLEAN,
            ]);
        }
    }
}
