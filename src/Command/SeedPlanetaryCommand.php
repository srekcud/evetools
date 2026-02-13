<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\PlanetaryRoute;
use App\Repository\CharacterRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-planetary',
    description: 'Seed fake planetary interaction data for UI testing'
)]
class SeedPlanetaryCommand extends Command
{
    // Real EVE type IDs
    private const ECU = 2848; // Extractor Control Unit
    private const BASIC_FACTORY = 2469; // Basic Industry Facility
    private const ADVANCED_FACTORY = 2470; // Advanced Industry Facility
    private const HIGHTECH_FACTORY = 2471; // High-Tech Production Plant
    private const STORAGE = 2541; // Storage Facility
    private const LAUNCHPAD = 2256; // Launchpad
    private const COMMAND_CENTER_TEMPERATE = 2254;
    private const COMMAND_CENTER_BARREN = 2494;
    private const COMMAND_CENTER_LAVA = 2495;
    private const COMMAND_CENTER_ICE = 2496;
    private const COMMAND_CENTER_GAS = 2497;
    private const COMMAND_CENTER_OCEANIC = 2498;

    // P0 raw materials (real type IDs)
    private const P0_BASE_METALS = 2267;
    private const P0_AQUEOUS_LIQUIDS = 2268;
    private const P0_NOBLE_METALS = 2270;
    private const P0_HEAVY_METALS = 2272;
    private const P0_MICRO_ORGANISMS = 2073;
    private const P0_CARBON_COMPOUNDS = 2287;
    private const P0_COMPLEX_ORGANISMS = 2286;
    private const P0_PLANKTIC_COLONIES = 2076;
    private const P0_FELSIC_MAGMA = 2307;
    private const P0_NOBLE_GAS = 2310;
    private const P0_IONIC_SOLUTIONS = 2309;

    // P1 schematics (Basic Industry Facility, cycle 1800s)
    private const SCHEMATIC_REACTIVE_METALS = 126;
    private const SCHEMATIC_WATER = 121;
    private const SCHEMATIC_PRECIOUS_METALS = 127;
    private const SCHEMATIC_TOXIC_METALS = 128;
    private const SCHEMATIC_BACTERIA = 131;
    private const SCHEMATIC_BIOFUELS = 134;
    private const SCHEMATIC_INDUSTRIAL_FIBERS = 135;
    private const SCHEMATIC_SILICON = 130;
    private const SCHEMATIC_ELECTROLYTES = 123;

    // P2 schematics (Advanced Industry Facility, cycle 3600s)
    private const SCHEMATIC_COOLANT = 66;
    private const SCHEMATIC_MECHANICAL_PARTS = 73;
    private const SCHEMATIC_ENRICHED_URANIUM = 75;
    private const SCHEMATIC_CONSUMER_ELECTRONICS = 76;

    // P3 schematics (High-Tech Production Plant, cycle 3600s)
    private const SCHEMATIC_ROBOTICS = 97;
    private const SCHEMATIC_GUIDANCE_SYSTEMS = 100;

    // P4 schematics (High-Tech Production Plant, cycle 3600s)
    private const SCHEMATIC_NANO_FACTORY = 114;

    // Colonies definition
    private const COLONIES = [
        // Character 1: srekcud alpha — 3 colonies
        [
            'charIndex' => 0,
            'planetId' => 40009081,
            'planetType' => 'temperate',
            'solarSystemId' => 30000142,
            'solarSystemName' => 'Jita',
            'upgradeLevel' => 5,
            'pins' => [
                // 2 extractors (P0)
                ['type' => 'extractor', 'product' => self::P0_BASE_METALS, 'productName' => 'Base Metals', 'cycleTime' => 1800, 'qtyPerCycle' => 3000, 'heads' => 10, 'expiryHours' => 48],
                ['type' => 'extractor', 'product' => self::P0_AQUEOUS_LIQUIDS, 'productName' => 'Aqueous Liquids', 'cycleTime' => 1800, 'qtyPerCycle' => 2800, 'heads' => 8, 'expiryHours' => 48],
                // 4 basic factories (P0→P1)
                ['type' => 'basic_factory', 'schematicId' => self::SCHEMATIC_REACTIVE_METALS],
                ['type' => 'basic_factory', 'schematicId' => self::SCHEMATIC_REACTIVE_METALS],
                ['type' => 'basic_factory', 'schematicId' => self::SCHEMATIC_WATER],
                ['type' => 'basic_factory', 'schematicId' => self::SCHEMATIC_WATER],
                // 1 advanced factory (P1→P2)
                ['type' => 'advanced_factory', 'schematicId' => self::SCHEMATIC_COOLANT],
                // 1 high-tech factory (P2→P3)
                ['type' => 'hightech_factory', 'schematicId' => self::SCHEMATIC_ROBOTICS],
                // Storage + launchpad
                ['type' => 'storage', 'contents' => [['type_id' => 2389, 'amount' => 4500], ['type_id' => 2390, 'amount' => 3200]]],
                ['type' => 'launchpad', 'contents' => [['type_id' => 9832, 'amount' => 120], ['type_id' => 2389, 'amount' => 1800]]],
                ['type' => 'command_center'],
            ],
        ],
        [
            'charIndex' => 0,
            'planetId' => 40009082,
            'planetType' => 'barren',
            'solarSystemId' => 30000142,
            'solarSystemName' => 'Jita',
            'upgradeLevel' => 4,
            'pins' => [
                ['type' => 'extractor', 'product' => self::P0_NOBLE_METALS, 'productName' => 'Noble Metals', 'cycleTime' => 1800, 'qtyPerCycle' => 2500, 'heads' => 8, 'expiryHours' => 12], // expiring soon!
                ['type' => 'extractor', 'product' => self::P0_HEAVY_METALS, 'productName' => 'Heavy Metals', 'cycleTime' => 1800, 'qtyPerCycle' => 2200, 'heads' => 6, 'expiryHours' => -2], // EXPIRED
                ['type' => 'basic_factory', 'schematicId' => self::SCHEMATIC_PRECIOUS_METALS],
                ['type' => 'basic_factory', 'schematicId' => self::SCHEMATIC_TOXIC_METALS],
                ['type' => 'advanced_factory', 'schematicId' => self::SCHEMATIC_ENRICHED_URANIUM],
                ['type' => 'launchpad', 'contents' => [['type_id' => 2401, 'amount' => 800]]],
                ['type' => 'command_center'],
            ],
        ],
        [
            'charIndex' => 0,
            'planetId' => 40009085,
            'planetType' => 'lava',
            'solarSystemId' => 30000144,
            'solarSystemName' => 'Perimeter',
            'upgradeLevel' => 3,
            'pins' => [
                ['type' => 'extractor', 'product' => self::P0_FELSIC_MAGMA, 'productName' => 'Felsic Magma', 'cycleTime' => 3600, 'qtyPerCycle' => 5000, 'heads' => 10, 'expiryHours' => 72],
                ['type' => 'basic_factory', 'schematicId' => self::SCHEMATIC_SILICON],
                ['type' => 'basic_factory', 'schematicId' => self::SCHEMATIC_SILICON],
                ['type' => 'launchpad', 'contents' => []],
                ['type' => 'command_center'],
            ],
        ],

        // Character 2: srekcud Beta — 2 colonies
        [
            'charIndex' => 2,
            'planetId' => 40009090,
            'planetType' => 'ice',
            'solarSystemId' => 30000143,
            'solarSystemName' => 'Maurasi',
            'upgradeLevel' => 5,
            'pins' => [
                ['type' => 'extractor', 'product' => self::P0_MICRO_ORGANISMS, 'productName' => 'Micro Organisms', 'cycleTime' => 1800, 'qtyPerCycle' => 3500, 'heads' => 10, 'expiryHours' => 36],
                ['type' => 'extractor', 'product' => self::P0_AQUEOUS_LIQUIDS, 'productName' => 'Aqueous Liquids', 'cycleTime' => 1800, 'qtyPerCycle' => 3000, 'heads' => 10, 'expiryHours' => 36],
                ['type' => 'basic_factory', 'schematicId' => self::SCHEMATIC_BACTERIA],
                ['type' => 'basic_factory', 'schematicId' => self::SCHEMATIC_BACTERIA],
                ['type' => 'basic_factory', 'schematicId' => self::SCHEMATIC_WATER],
                ['type' => 'advanced_factory', 'schematicId' => self::SCHEMATIC_CONSUMER_ELECTRONICS],
                ['type' => 'storage', 'contents' => [['type_id' => 2393, 'amount' => 6000], ['type_id' => 2390, 'amount' => 2000]]],
                ['type' => 'launchpad', 'contents' => [['type_id' => 9836, 'amount' => 60]]],
                ['type' => 'command_center'],
            ],
        ],
        [
            'charIndex' => 2,
            'planetId' => 40009091,
            'planetType' => 'gas',
            'solarSystemId' => 30000143,
            'solarSystemName' => 'Maurasi',
            'upgradeLevel' => 4,
            'pins' => [
                ['type' => 'extractor', 'product' => self::P0_NOBLE_GAS, 'productName' => 'Noble Gas', 'cycleTime' => 1800, 'qtyPerCycle' => 2600, 'heads' => 8, 'expiryHours' => 6], // expiring soon
                ['type' => 'extractor', 'product' => self::P0_IONIC_SOLUTIONS, 'productName' => 'Ionic Solutions', 'cycleTime' => 1800, 'qtyPerCycle' => 2400, 'heads' => 8, 'expiryHours' => 6],
                ['type' => 'basic_factory', 'schematicId' => self::SCHEMATIC_ELECTROLYTES],
                ['type' => 'basic_factory', 'schematicId' => self::SCHEMATIC_ELECTROLYTES],
                ['type' => 'launchpad', 'contents' => [['type_id' => 2398, 'amount' => 1200]]],
                ['type' => 'command_center'],
            ],
        ],

        // Character 3: srekcud delta — 1 colony (oceanic)
        [
            'charIndex' => 4,
            'planetId' => 40009095,
            'planetType' => 'oceanic',
            'solarSystemId' => 30000145,
            'solarSystemName' => 'Kisogo',
            'upgradeLevel' => 5,
            'pins' => [
                ['type' => 'extractor', 'product' => self::P0_CARBON_COMPOUNDS, 'productName' => 'Carbon Compounds', 'cycleTime' => 1800, 'qtyPerCycle' => 3200, 'heads' => 10, 'expiryHours' => 20], // expiring <24h
                ['type' => 'extractor', 'product' => self::P0_COMPLEX_ORGANISMS, 'productName' => 'Complex Organisms', 'cycleTime' => 1800, 'qtyPerCycle' => 2800, 'heads' => 8, 'expiryHours' => 20],
                ['type' => 'extractor', 'product' => self::P0_PLANKTIC_COLONIES, 'productName' => 'Planktic Colonies', 'cycleTime' => 1800, 'qtyPerCycle' => 2600, 'heads' => 8, 'expiryHours' => -8], // expired
                ['type' => 'basic_factory', 'schematicId' => self::SCHEMATIC_BIOFUELS],
                ['type' => 'basic_factory', 'schematicId' => self::SCHEMATIC_BIOFUELS],
                ['type' => 'basic_factory', 'schematicId' => self::SCHEMATIC_INDUSTRIAL_FIBERS],
                ['type' => 'advanced_factory', 'schematicId' => self::SCHEMATIC_MECHANICAL_PARTS],
                // 1 high-tech factory (P2→P3)
                ['type' => 'hightech_factory', 'schematicId' => self::SCHEMATIC_GUIDANCE_SYSTEMS],
                // 1 high-tech factory (P3→P4)
                ['type' => 'hightech_factory', 'schematicId' => self::SCHEMATIC_NANO_FACTORY],
                ['type' => 'storage', 'contents' => [['type_id' => 2396, 'amount' => 8000], ['type_id' => 2397, 'amount' => 5000], ['type_id' => 3689, 'amount' => 2400]]],
                ['type' => 'launchpad', 'contents' => [['type_id' => 9834, 'amount' => 240], ['type_id' => 2396, 'amount' => 3000]]],
                ['type' => 'command_center'],
            ],
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CharacterRepository $characterRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('clear', null, InputOption::VALUE_NONE, 'Clear existing planetary data before seeding');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $characters = $this->characterRepository->findAll();
        if (empty($characters)) {
            $io->error('No characters found. Login first.');
            return Command::FAILURE;
        }

        if ($input->getOption('clear')) {
            $conn = $this->entityManager->getConnection();
            $conn->executeStatement('DELETE FROM planetary_routes');
            $conn->executeStatement('DELETE FROM planetary_pins');
            $conn->executeStatement('DELETE FROM planetary_colonies');
            $io->info('Cleared existing planetary data.');
        }

        $now = new \DateTimeImmutable();
        $pinIdCounter = 1000000000;
        $routeIdCounter = 1;
        $colonyCount = 0;

        foreach (self::COLONIES as $colonyDef) {
            $charIndex = $colonyDef['charIndex'];
            if (!isset($characters[$charIndex])) {
                $io->warning("Character index {$charIndex} not found, skipping colony.");
                continue;
            }

            $character = $characters[$charIndex];

            $colony = new PlanetaryColony();
            $colony->setCharacter($character);
            $colony->setPlanetId($colonyDef['planetId']);
            $colony->setPlanetType($colonyDef['planetType']);
            $colony->setSolarSystemId($colonyDef['solarSystemId']);
            $colony->setSolarSystemName($colonyDef['solarSystemName']);
            $colony->setUpgradeLevel($colonyDef['upgradeLevel']);
            $colony->setNumPins(count($colonyDef['pins']));
            $colony->setLastUpdate($now->modify('-30 minutes'));
            $colony->setCachedAt($now->modify('-15 minutes'));

            $this->entityManager->persist($colony);

            $extractorPinIds = [];
            $factoryPinIds = [];
            $storagePinIds = [];

            foreach ($colonyDef['pins'] as $pinDef) {
                $pin = new PlanetaryPin();
                $pin->setPinId(++$pinIdCounter);
                $pin->setLatitude(rand(-90, 90) + rand(0, 99) / 100);
                $pin->setLongitude(rand(-180, 180) + rand(0, 99) / 100);

                switch ($pinDef['type']) {
                    case 'extractor':
                        $pin->setTypeId(self::ECU);
                        $pin->setTypeName('Extractor Control Unit');
                        $pin->setExtractorProductTypeId($pinDef['product']);
                        $pin->setExtractorCycleTime($pinDef['cycleTime']);
                        $pin->setExtractorQtyPerCycle($pinDef['qtyPerCycle']);
                        $pin->setExtractorNumHeads($pinDef['heads']);
                        $pin->setExtractorHeadRadius(0.05);
                        $pin->setInstallTime($now->modify('-3 days'));
                        $pin->setExpiryTime($now->modify(sprintf('%+d hours', $pinDef['expiryHours'])));
                        $pin->setLastCycleStart($now->modify('-15 minutes'));
                        $extractorPinIds[] = $pinIdCounter;
                        break;

                    case 'basic_factory':
                        $pin->setTypeId(self::BASIC_FACTORY);
                        $pin->setTypeName('Basic Industry Facility');
                        $pin->setSchematicId($pinDef['schematicId']);
                        $factoryPinIds[] = $pinIdCounter;
                        break;

                    case 'advanced_factory':
                        $pin->setTypeId(self::ADVANCED_FACTORY);
                        $pin->setTypeName('Advanced Industry Facility');
                        $pin->setSchematicId($pinDef['schematicId']);
                        $factoryPinIds[] = $pinIdCounter;
                        break;

                    case 'hightech_factory':
                        $pin->setTypeId(self::HIGHTECH_FACTORY);
                        $pin->setTypeName('High-Tech Production Plant');
                        $pin->setSchematicId($pinDef['schematicId']);
                        $factoryPinIds[] = $pinIdCounter;
                        break;

                    case 'storage':
                        $pin->setTypeId(self::STORAGE);
                        $pin->setTypeName('Storage Facility');
                        $pin->setContents($pinDef['contents']);
                        $storagePinIds[] = $pinIdCounter;
                        break;

                    case 'launchpad':
                        $pin->setTypeId(self::LAUNCHPAD);
                        $pin->setTypeName('Launchpad');
                        $pin->setContents($pinDef['contents']);
                        $storagePinIds[] = $pinIdCounter;
                        break;

                    case 'command_center':
                        $ccTypeId = match ($colonyDef['planetType']) {
                            'temperate' => self::COMMAND_CENTER_TEMPERATE,
                            'barren' => self::COMMAND_CENTER_BARREN,
                            'lava' => self::COMMAND_CENTER_LAVA,
                            'ice' => self::COMMAND_CENTER_ICE,
                            'gas' => self::COMMAND_CENTER_GAS,
                            'oceanic' => self::COMMAND_CENTER_OCEANIC,
                            default => self::COMMAND_CENTER_TEMPERATE,
                        };
                        $pin->setTypeId($ccTypeId);
                        $pin->setTypeName('Command Center');
                        break;
                }

                $colony->addPin($pin);
            }

            // Create routes: extractors → factories → storage
            foreach ($extractorPinIds as $i => $srcPinId) {
                if (isset($factoryPinIds[$i])) {
                    $route = new PlanetaryRoute();
                    $route->setRouteId(++$routeIdCounter);
                    $route->setSourcePinId($srcPinId);
                    $route->setDestinationPinId($factoryPinIds[$i]);
                    $route->setContentTypeId(self::P0_BASE_METALS);
                    $route->setQuantity(100);
                    $colony->addRoute($route);
                }
            }
            foreach ($factoryPinIds as $i => $srcPinId) {
                if (!empty($storagePinIds)) {
                    $route = new PlanetaryRoute();
                    $route->setRouteId(++$routeIdCounter);
                    $route->setSourcePinId($srcPinId);
                    $route->setDestinationPinId($storagePinIds[0]);
                    $route->setContentTypeId(2389); // Reactive Metals
                    $route->setQuantity(20);
                    $colony->addRoute($route);
                }
            }

            $colonyCount++;
            $io->info(sprintf(
                'Colony: %s %s (%s) — %d pins',
                $colonyDef['solarSystemName'],
                $colonyDef['planetType'],
                $character->getName(),
                count($colonyDef['pins']),
            ));
        }

        $this->entityManager->flush();
        $io->success(sprintf('Seeded %d planetary colonies.', $colonyCount));

        return Command::SUCCESS;
    }
}
