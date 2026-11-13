<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Sde;

use App\Entity\Sde\MapMoon;
use App\Entity\Sde\MapPlanet;
use App\Entity\Sde\MapSolarSystem;
use App\Service\Sde\ResolvedMoon;
use App\Service\Sde\SdeCelestialNameResolver;
use App\Service\Sde\SdeMapImporter;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

#[CoversClass(SdeMapImporter::class)]
#[Group('issue-849')]
final class SdeMapImporterCelestialsTest extends IntegrationTestCase
{
    private const int REGION_ID = 10_849_000;
    private const int CONSTELLATION_ID = 20_849_000;
    private const int ABHAN_ID = 30_849_001;
    private const int WORMHOLE_ID = 31_849_001;
    private const int UNKNOWN_SYSTEM_ID = 30_849_999;

    private string $sdeDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sdeDirectory = sys_get_temp_dir() . '/sde-849-' . bin2hex(random_bytes(4));
        mkdir($this->sdeDirectory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sdeDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->sdeDirectory);
        parent::tearDown();
    }

    public function testImportingTheSolarSystemsAlsoLoadsThePlanetsAndMoonsOfKnownSystems(): void
    {
        $this->writeSde(
            planets: [
                [40_849_001, self::ABHAN_ID, 5, 11],
                [40_849_002, self::WORMHOLE_ID, 8, 13],
                [40_849_003, self::UNKNOWN_SYSTEM_ID, 1, 12],
            ],
            moons: [
                [40_849_011, self::ABHAN_ID, 40_849_001, 5, 8],
                [40_849_012, self::WORMHOLE_ID, 40_849_002, 8, 14],
                [40_849_013, self::UNKNOWN_SYSTEM_ID, 40_849_003, 1, 1],
            ],
        );

        $this->importMap();

        self::assertEquals(
            [
                ['planet_id' => 40_849_001, 'solar_system_id' => self::ABHAN_ID, 'celestial_index' => 5, 'type_id' => 11],
                ['planet_id' => 40_849_002, 'solar_system_id' => self::WORMHOLE_ID, 'celestial_index' => 8, 'type_id' => 13],
            ],
            $this->rows('SELECT planet_id, solar_system_id, celestial_index, type_id FROM sde_map_planets WHERE planet_id BETWEEN 40849000 AND 40849999 ORDER BY planet_id'),
        );
        self::assertEquals(
            [
                ['moon_id' => 40_849_011, 'solar_system_id' => self::ABHAN_ID, 'orbit_id' => 40_849_001, 'celestial_index' => 5, 'orbit_index' => 8],
                ['moon_id' => 40_849_012, 'solar_system_id' => self::WORMHOLE_ID, 'orbit_id' => 40_849_002, 'celestial_index' => 8, 'orbit_index' => 14],
            ],
            $this->rows('SELECT moon_id, solar_system_id, orbit_id, celestial_index, orbit_index FROM sde_map_moons WHERE moon_id BETWEEN 40849000 AND 40849999 ORDER BY moon_id'),
        );
    }

    public function testAReimportReplacesThePreviousPlanetsAndMoonsInsteadOfAddingToThem(): void
    {
        $this->writeSde(planets: [[40_849_001, self::ABHAN_ID, 5, 11]], moons: [[40_849_011, self::ABHAN_ID, 40_849_001, 5, 8]]);
        $this->importMap();
        $this->writeSde(planets: [[40_849_002, self::WORMHOLE_ID, 8, 13]], moons: [[40_849_012, self::WORMHOLE_ID, 40_849_002, 8, 14]]);

        $this->importMap();

        self::assertSame([40_849_002], array_map('intval', array_column($this->rows('SELECT planet_id FROM sde_map_planets'), 'planet_id')));
        self::assertSame([40_849_012], array_map('intval', array_column($this->rows('SELECT moon_id FROM sde_map_moons'), 'moon_id')));
    }

    public function testTheImportedRowsAreNamedByTheResolverWithTheEsiNamingRule(): void
    {
        $this->writeSde(planets: [[40_849_001, self::ABHAN_ID, 5, 11]], moons: [[40_849_011, self::ABHAN_ID, 40_849_001, 5, 8]]);
        $this->importMap();
        $this->em->clear();

        $resolver = new SdeCelestialNameResolver(
            $this->em->getRepository(MapPlanet::class),
            $this->em->getRepository(MapMoon::class),
            $this->em->getRepository(MapSolarSystem::class),
        );

        self::assertSame('Abhan V', $resolver->planetName(40_849_001));
        self::assertEquals(new ResolvedMoon('Abhan V - Moon 8', self::ABHAN_ID), $resolver->moon(40_849_011));
        self::assertNull($resolver->moon(40_849_099));
    }

    private function importMap(): void
    {
        $importer = new SdeMapImporter($this->em, new NullLogger());
        $importer->setTempDir($this->sdeDirectory);
        $importer->importRegions();
        $importer->importConstellations();
        $importer->importSolarSystems();
        $this->em->clear();
    }

    private function rows(string $sql): array
    {
        return $this->em->getConnection()->fetchAllAssociative($sql);
    }

    private function writeSde(array $planets, array $moons): void
    {
        $this->writeJsonl('mapRegions.jsonl', [['_key' => self::REGION_ID, 'name' => ['en' => 'Region 849']]]);
        $this->writeJsonl('mapConstellations.jsonl', [['_key' => self::CONSTELLATION_ID, 'regionID' => self::REGION_ID, 'name' => ['en' => 'Constellation 849']]]);
        $this->writeJsonl('mapSolarSystems.jsonl', [
            ['_key' => self::ABHAN_ID, 'constellationID' => self::CONSTELLATION_ID, 'regionID' => self::REGION_ID, 'name' => ['en' => 'Abhan'], 'securityStatus' => 0.5],
            ['_key' => self::WORMHOLE_ID, 'constellationID' => self::CONSTELLATION_ID, 'regionID' => self::REGION_ID, 'name' => ['en' => 'J849001'], 'securityStatus' => -0.99],
        ]);
        $this->writeJsonl('mapPlanets.jsonl', array_map(
            static fn (array $planet): array => ['_key' => $planet[0], 'solarSystemID' => $planet[1], 'celestialIndex' => $planet[2], 'typeID' => $planet[3]],
            $planets,
        ));
        $this->writeJsonl('mapMoons.jsonl', array_map(
            static fn (array $moon): array => ['_key' => $moon[0], 'solarSystemID' => $moon[1], 'orbitID' => $moon[2], 'celestialIndex' => $moon[3], 'orbitIndex' => $moon[4]],
            $moons,
        ));
    }

    private function writeJsonl(string $file, array $rows): void
    {
        file_put_contents($this->sdeDirectory . '/' . $file, implode("\n", array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows)) . "\n");
    }
}
