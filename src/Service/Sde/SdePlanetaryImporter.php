<?php

declare(strict_types=1);

namespace App\Service\Sde;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class SdePlanetaryImporter
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

    public function importPlanetSchematics(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_planet_schematic_types');
        $this->truncateTable('sde_planet_schematics');

        $count = 0;
        $connection = $this->getConnection();

        foreach ($this->readJsonlFile('planetSchematics.jsonl') as $schematicId => $schematic) {
            $connection->insert('sde_planet_schematics', [
                'schematic_id' => (int) $schematicId,
                'schematic_name' => $this->getName($schematic),
                'cycle_time' => (int) ($schematic['cycleTime'] ?? 0),
            ]);

            foreach ($schematic['types'] ?? [] as $type) {
                $connection->insert('sde_planet_schematic_types', [
                    'schematic_id' => (int) $schematicId,
                    'type_id' => (int) $type['_key'],
                    'is_input' => $type['isInput'] ?? false,
                    'quantity' => (int) ($type['quantity'] ?? 0),
                ], [
                    'is_input' => ParameterType::BOOLEAN,
                ]);
            }

            $count++;
        }

        $this->notify($progressCallback, "  Total: {$count} planet schematics imported");
    }
}
