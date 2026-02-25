<?php

declare(strict_types=1);

namespace App\Service\Sde;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class SdeReferenceImporter
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

    public function importRaces(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_chr_races');

        $count = 0;
        $connection = $this->getConnection();

        foreach ($this->readJsonlFile('races.jsonl') as $raceId => $race) {
            $connection->insert('sde_chr_races', [
                'race_id' => (int) $raceId,
                'race_name' => $this->getName($race),
                'description' => $this->getDescription($race),
                'icon_id' => $race['iconID'] ?? null,
                'short_description' => null,
            ]);

            $count++;
        }

        $this->notify($progressCallback, "  Total: {$count} races imported");
    }

    public function importFactions(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_chr_factions');

        $count = 0;
        $connection = $this->getConnection();

        foreach ($this->readJsonlFile('factions.jsonl') as $factionId => $faction) {
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

    public function importFlags(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_inv_flags');

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
        $connection = $this->getConnection();

        foreach ($flags as $flag) {
            $connection->insert('sde_inv_flags', $flag);
            $count++;
        }

        $this->notify($progressCallback, "  Total: {$count} flags imported (hardcoded values)");
    }

    public function importIcons(?callable $progressCallback = null): void
    {
        $this->truncateTable('sde_eve_icons');

        $count = 0;
        $batchSize = 1000;
        $connection = $this->getConnection();
        $batch = [];

        foreach ($this->readJsonlFile('icons.jsonl') as $iconId => $icon) {
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
}
