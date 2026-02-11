<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\IndustryRigCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-rig-categories',
    description: 'Seed industry rig category mappings (group_id -> category)',
)]
class SeedIndustryRigCategoriesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Force re-seeding (deletes existing data)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $force = $input->getOption('force');

        // Check if data exists
        $existingCount = $this->entityManager
            ->getRepository(IndustryRigCategory::class)
            ->count([]);

        if ($existingCount > 0 && !$force) {
            $io->warning("Found {$existingCount} existing mappings. Use --force to re-seed.");
            return Command::SUCCESS;
        }

        if ($force && $existingCount > 0) {
            $io->info("Deleting {$existingCount} existing mappings...");
            $this->entityManager->createQuery('DELETE FROM App\Entity\IndustryRigCategory')->execute();
        }

        $mappings = $this->getCategoryMappings();
        $count = 0;

        foreach ($mappings as $category => $groupIds) {
            foreach ($groupIds as $groupId) {
                $entity = new IndustryRigCategory();
                $entity->setCategory($category);
                $entity->setGroupId($groupId);
                $this->entityManager->persist($entity);
                $count++;
            }
        }

        $this->entityManager->flush();

        $io->success("Seeded {$count} category mappings for " . count($mappings) . " categories.");

        // Display summary
        $io->table(
            ['Category', 'Groups'],
            array_map(
                fn($cat, $groups) => [$cat, count($groups)],
                array_keys($mappings),
                array_values($mappings)
            )
        );

        return Command::SUCCESS;
    }

    /**
     * @return array<string, int[]>
     */
    private function getCategoryMappings(): array
    {
        return [
            // =====================
            // SHIPS - Manufacturing
            // =====================

            // Basic Small Ships (T1 frigates, destroyers, shuttles)
            'basic_small_ship' => [
                25,   // Frigate
                420,  // Destroyer
                31,   // Shuttle
                237,  // Corvette
            ],

            // Basic Medium Ships (T1 cruisers, battlecruisers, industrials)
            'basic_medium_ship' => [
                26,   // Cruiser
                419,  // Combat Battlecruiser
                1201, // Attack Battlecruiser
                28,   // Hauler (Industrial)
                463,  // Mining Barge
            ],

            // Basic Large Ships (T1 battleships)
            'basic_large_ship' => [
                27,   // Battleship
            ],

            // Advanced Small Ships (T2/Faction frigates, destroyers)
            'advanced_small_ship' => [
                324,  // Assault Frigate
                831,  // Interceptor
                830,  // Covert Ops
                893,  // Electronic Attack Ship
                834,  // Stealth Bomber
                1527, // Logistics Frigate
                1534, // Command Destroyer
                1305, // Tactical Destroyer
                1283, // Expedition Frigate
            ],

            // Advanced Medium Ships (T2/Faction cruisers, battlecruisers)
            'advanced_medium_ship' => [
                358,  // Heavy Assault Cruiser
                894,  // Heavy Interdiction Cruiser
                832,  // Logistics
                833,  // Force Recon Ship
                906,  // Combat Recon Ship
                963,  // Strategic Cruiser
                1972, // Flag Cruiser
                541,  // Interdictor
                540,  // Command Ship
                543,  // Exhumer
                380,  // Deep Space Transport
                1202, // Blockade Runner
            ],

            // Advanced Large Ships (T2/Faction battleships)
            'advanced_large_ship' => [
                898,  // Black Ops
                900,  // Marauder
                381,  // Elite Battleship (Faction BS)
            ],

            // Capital Ships
            'capital_ship' => [
                547,  // Carrier
                485,  // Dreadnought
                659,  // Supercarrier
                30,   // Titan
                513,  // Freighter
                902,  // Jump Freighter
                1538, // Force Auxiliary
                883,  // Capital Industrial Ship
                941,  // Industrial Command Ship (Orca, Porpoise)
                4594, // Lancer Dreadnought
            ],

            // =====================
            // COMPONENTS
            // =====================

            // Basic Capital Components
            'basic_capital_component' => [
                873,  // Capital Construction Components
            ],

            // Advanced Components (T2, T3)
            'advanced_component' => [
                334,  // Construction Components (T2 components)
                913,  // Advanced Capital Construction Components
                964,  // Hybrid Tech Components (T3)
            ],

            // Structure Components
            'structure_component' => [
                536,  // Structure Components
            ],

            // =====================
            // EQUIPMENT & CONSUMABLE (Modules, Deployables, Containers)
            // =====================
            'equipment' => [
                // --- Deployables (category 22) ---
                1273, // Encounter Surveillance System
                4810, // Mercenary Den
                4137, // Mobile Analysis Beacon
                1249, // Mobile Cyno Inhibitor
                4093, // Mobile Cynosural Beacon
                1274, // Mobile Decoy Unit
                1246, // Mobile Depot
                1149, // Mobile Jump Disruptor
                1276, // Mobile Micro Jump Unit
                4107, // Mobile Observatory
                4913, // Mobile Phase Anchor
                1275, // Mobile Scan Inhibitor
                1247, // Mobile Siphon Unit
                1250, // Mobile Tractor Unit
                1297, // Mobile Vault
                361,  // Mobile Warp Disruptor

                // --- Cargo Containers (Celestial category) ---
                12,   // Cargo Container
                340,  // Secure Cargo Container
                448,  // Audit Log Secure Container
                649,  // Freight Container

                // --- Modules ---
                62,   // Armor Repair Unit
                63,   // Hull Repair Unit
                328,  // Armor Hardener
                329,  // Armor Plate
                326,  // Energized Armor Membrane
                98,   // Armor Coating
                1150, // Armor Resistance Shift Hardener
                77,   // Shield Booster
                295,  // Shield Extender
                774,  // Shield Hardener
                773,  // Shield Resistance Amplifier
                1156, // Ancillary Shield Booster
                1199, // Ancillary Armor Repairer
                60,   // Damage Control
                367,  // Ballistic Control System
                59,   // Gyrostabilizer
                302,  // Magnetic Field Stabilizer
                205,  // Heat Sink
                1988, // Entropic Radiation Sink
                53,   // Energy Weapon
                74,   // Hybrid Weapon
                55,   // Projectile Weapon
                56,   // Missile Launcher
                506,  // Missile Launcher Cruise
                510,  // Missile Launcher Heavy
                509,  // Missile Launcher Light
                511,  // Missile Launcher Rapid Light
                1245, // Missile Launcher Rapid Heavy
                771,  // Missile Launcher Heavy Assault
                862,  // Missile Launcher Bomb
                475,  // Microwarpdrive
                46,   // Afterburner
                1189, // Micro Jump Drive
                52,   // Warp Disruptor
                1145, // Warp Scrambler
                65,   // Stasis Web
                76,   // Capacitor Booster
                61,   // Capacitor Battery
                43,   // Capacitor Recharger
                768,  // Capacitor Flux Coil
                767,  // Capacitor Power Relay
                339,  // Auxiliary Power Core
                285,  // CPU Enhancer
                765,  // Expanded Cargohold
                762,  // Inertial Stabilizer
                71,   // Energy Neutralizer
                68,   // Energy Nosferatu
                330,  // Cloaking Device
                201,  // ECM
                202,  // ECCM
                753,  // ECM Enhancer
                291,  // Sensor Booster
                208,  // Remote Sensor Booster
                212,  // Sensor Dampener
                209,  // Tracking Disruptor
                213,  // Target Painter
                379,  // Remote Tracking Computer
                67,   // Tracking Computer
                289,  // Tracking Enhancer
                1396, // Missile Guidance Computer
                1395, // Missile Guidance Enhancer
                54,   // Mining Laser
                483,  // Frequency Mining Laser
                546,  // Mining Upgrade
                901,  // Mining Enhancer
                69,   // Remote Armor Repairer
                70,   // Remote Shield Booster
                295,  // Shield Extender
                72,   // Remote Capacitor Transmitter
                40,   // Tracking Link
                41,   // Sensor Link
                325,  // Remote Hull Repairer
                1313, // Entosis Link
                1770, // Command Burst
                589,  // Interdiction Sphere Launcher
                590,  // Jump Portal Generator
                658,  // Cynosural Field Generator
                905,  // Covert Cynosural Field Generator
                815,  // Clone Vat Bay
                538,  // Data Miners
                96,   // Automated Targeting System
                645,  // Drone Damage Modules
                644,  // Drone Navigation Computer
                1292, // Drone Tracking Enhancer
                646,  // Drone Tracking Modules
                647,  // Drone Control Range Module
                357,  // Drone Bay Expander
                1533, // Micro Jump Field Generators
                407,  // Fighter Support Unit
            ],

            // =====================
            // AMMUNITION (Charges)
            // =====================
            'ammunition' => [
                86,   // Frequency Crystal
                374,  // Advanced Beam Laser Crystal
                375,  // Advanced Pulse Laser Crystal
                85,   // Hybrid Charge
                373,  // Advanced Railgun Charge
                377,  // Advanced Blaster Charge
                83,   // Projectile Ammo
                372,  // Advanced Autocannon Ammo
                376,  // Advanced Artillery Ammo
                384,  // Light Missile
                653,  // Advanced Light Missile
                385,  // Heavy Missile
                655,  // Advanced Heavy Missile
                772,  // Heavy Assault Missile
                654,  // Advanced Heavy Assault Missile
                386,  // Cruise Missile
                656,  // Advanced Cruise Missile
                1019, // XL Cruise Missile
                1678, // Advanced XL Cruise Missile
                387,  // Rocket
                648,  // Advanced Rocket
                89,   // Torpedo
                657,  // Advanced Torpedo
                476,  // XL Torpedo
                1010, // Compact XL Torpedo
                1677, // Advanced XL Torpedo
                90,   // Bomb
                863,  // Bomb ECM
                864,  // Bomb Energy
                1548, // Guided Bomb
                87,   // Capacitor Booster Charge
                479,  // Scanner Probe
                492,  // Survey Probe
                548,  // Interdiction Probe
                4088, // Interdiction Burst Probes
                482,  // Mining Crystal
                663,  // Mercoxit Mining Crystal
                910,  // Sensor Booster Script
                911,  // Sensor Dampener Script
                907,  // Tracking Script
                909,  // Tracking Disruption Script
                908,  // Warp Disruption Script
                1153, // Shield Booster Script
                1400, // Missile Guidance Script
                1569, // Guidance Disruption Script
                1701, // Flex Armor Hardener Script
                1702, // Flex Shield Hardener Script
                916,  // Nanite Repair Paste
                1136, // Fuel Block
                1769, // Shield Command Burst Charges
                1771, // Mining Foreman Burst Charges
                1772, // Skirmish Command Burst Charges
                1773, // Information Command Burst Charges
                1774, // Armor Command Burst Charges
                4062, // Condenser Pack
                4061, // Advanced Condenser Pack
                1987, // Exotic Plasma Charge
                1989, // Advanced Exotic Plasma Charge
            ],

            // =====================
            // DRONES
            // =====================
            'drone' => [
                100,  // Combat Drone
                101,  // Mining Drone
                639,  // Electronic Warfare Drone
                640,  // Logistic Drone
                299,  // Repair Drone
                641,  // Stasis Webifying Drone
                544,  // Energy Neutralizer Drone
                545,  // Warp Scrambling Drone
                1159, // Salvage Drone
                97,   // Proximity Drone
            ],

            // =====================
            // FIGHTERS
            // =====================
            'fighter' => [
                549,  // Fighter Drone (legacy)
                1023, // Fighter Bomber
                1652, // Light Fighter
                1653, // Heavy Fighter
                1537, // Support Fighter
                4777, // Structure Light Fighter
                4778, // Structure Support Fighter
                4779, // Structure Heavy Fighter
            ],

            // =====================
            // STRUCTURES
            // =====================
            'structure' => [
                1657, // Citadel
                1404, // Engineering Complex
                1406, // Refinery
                1405, // Laboratory
                1407, // Observatory Array
                1408, // Upwell Jump Bridge (Ansiblex)
                2016, // Upwell Cyno Jammer
                2017, // Upwell Cyno Beacon
                1409, // Administration Hub
                1410, // Advertisement Center
            ],

            // =====================
            // REACTIONS
            // =====================

            // Composite Reactions (Moon materials -> Composites)
            'composite_reaction' => [
                436,  // Simple Reaction
                484,  // Complex Reactions
            ],

            // Biochemical Reactions (Boosters)
            'biochemical_reaction' => [
                661,  // Simple Biochemical Reactions
                662,  // Complex Biochemical Reactions
            ],

            // Hybrid Reactions (T3 materials)
            'hybrid_reaction' => [
                977,  // Hybrid Reactions
            ],
        ];
    }
}
