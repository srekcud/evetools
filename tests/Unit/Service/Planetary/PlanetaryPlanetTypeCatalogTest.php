<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Exception\PlanetaryCommandCenterMissingFromSdeException;
use App\Exception\PlanetaryInstallationAttributeMissingException;
use App\Exception\PlanetaryInstallationNameUnrecognizedException;
use App\Service\Planetary\PlanetaryPlanetTypeCatalog;
use App\Tests\Unit\Support\StubsPlanetaryTypes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryPlanetTypeCatalog::class)]
#[Group('issue-1403')]
final class PlanetaryPlanetTypeCatalogTest extends TestCase
{
    use StubsPlanetaryTypes;

    public function testPlanetsAreReadOnTheLaunchpadsAndSortedByNameWithTheLaunchpadLimits(): void
    {
        $planetTypes = $this->catalogOver(self::planetaryTypeTable())->build()['planetTypes'];

        $this->assertSame(['Barren', 'Gas', 'Ice'], array_column($planetTypes, 'name'));
        $this->assertSame([
            ['cpu' => 3_600, 'pg' => 700, 'capacity' => 10_000.0],
            ['cpu' => 3_400, 'pg' => 650, 'capacity' => 8_000.0],
            ['cpu' => 3_200, 'pg' => 600, 'capacity' => 12_000.0],
        ], array_column($planetTypes, 'launchpad'));
    }

    public function testTheCommandCenterOfAPlanetIsTheOneWithTheHighestCpuOutput(): void
    {
        $planetTypes = $this->catalogOver(self::planetaryTypeTable())->build()['planetTypes'];

        $this->assertSame([
            ['cpu' => 25_415, 'pg' => 19_000],
            ['cpu' => 22_750, 'pg' => 17_250],
            ['cpu' => 20_500, 'pg' => 15_000],
        ], array_column($planetTypes, 'commandCenter'));
    }

    public function testFacilitiesAreMatchedBySuffixAndAMissingOneStaysNull(): void
    {
        $planetTypes = $this->catalogOver(self::planetaryTypeTable())->build()['planetTypes'];

        $this->assertSame([
            ['basic' => ['cpu' => 200, 'pg' => 800], 'advanced' => ['cpu' => 500, 'pg' => 700], 'highTech' => ['cpu' => 1_100, 'pg' => 400]],
            ['basic' => ['cpu' => 250, 'pg' => 750], 'advanced' => ['cpu' => 550, 'pg' => 650], 'highTech' => null],
            ['basic' => null, 'advanced' => ['cpu' => 450, 'pg' => 600], 'highTech' => null],
        ], array_column($planetTypes, 'facilities'));
    }

    public function testEachRawResourceListsTheSortedPlanetTypesOfItsExtractorsWithoutDuplicateAndAResourceWithoutExtractorIsAbsent(): void
    {
        $extractionPlanetTypes = $this->catalogOver(self::planetaryTypeTable())->build()['extractionPlanetTypes'];

        $this->assertSame([
            ['typeId' => self::AQUEOUS_LIQUIDS_P0, 'planetTypes' => ['Barren', 'Gas']],
            ['typeId' => self::BASE_METALS_P0, 'planetTypes' => ['Barren', 'Ice']],
        ], $extractionPlanetTypes);
    }

    public function testWithoutAnyLaunchpadBothListsAreEmptyInsteadOfFailing(): void
    {
        $table = self::planetaryTypeTable();
        $table['types'] = array_filter($table['types'], static fn (array $definition): bool => $definition['group'] !== self::SPACEPORTS_GROUP_ID);

        $this->assertSame(['planetTypes' => [], 'extractionPlanetTypes' => []], $this->catalogOver($table)->build());
    }

    public function testALaunchpadPlanetWithoutCommandCenterRaisesAnErrorNamingThePlanet(): void
    {
        $table = self::planetaryTypeTable();
        foreach ([91_522, 91_523] as $iceCommandCenter) {
            unset($table['types'][$iceCommandCenter], $table['attributes'][$iceCommandCenter]);
        }

        $this->expectException(PlanetaryCommandCenterMissingFromSdeException::class);
        $this->expectExceptionMessage('Ice');

        $this->catalogOver($table)->build();
    }

    public static function missingAttributes(): iterable
    {
        yield 'command center cpu output absent' => [self::ELITE_BARREN_COMMAND_CENTER, 'cpuOutput', self::CPU_OUTPUT, false];
        yield 'command center power output absent' => [self::ELITE_BARREN_COMMAND_CENTER, 'powerOutput', self::POWER_OUTPUT, false];
        yield 'launchpad cpu load absent' => [self::BARREN_LAUNCHPAD, 'cpuLoad', self::CPU_LOAD, false];
        yield 'launchpad power load absent' => [self::BARREN_LAUNCHPAD, 'powerLoad', self::POWER_LOAD, false];
        yield 'installation cpu load absent' => [self::BARREN_ADVANCED_FACILITY, 'cpuLoad', self::CPU_LOAD, false];
        yield 'installation power load absent' => [self::BARREN_ADVANCED_FACILITY, 'powerLoad', self::POWER_LOAD, false];
        yield 'extractor harvester type absent' => [self::BARREN_AQUEOUS_EXTRACTOR, 'harvesterType', self::HARVESTER_TYPE, false];
        yield 'command center cpu output null' => [self::ELITE_BARREN_COMMAND_CENTER, 'cpuOutput', self::CPU_OUTPUT, true];
        yield 'extractor harvester type null' => [self::BARREN_AQUEOUS_EXTRACTOR, 'harvesterType', self::HARVESTER_TYPE, true];
    }

    #[DataProvider('missingAttributes')]
    public function testAnAbsentOrNullAttributeRaisesAnErrorNamingTheTypeAndTheAttribute(int $typeId, string $attributeName, int $attributeId, bool $isNull): void
    {
        $table = self::planetaryTypeTable(withLowerCommandCenters: false);
        if ($isNull) {
            $table['attributes'][$typeId][$attributeId] = null;
        } else {
            unset($table['attributes'][$typeId][$attributeId]);
        }

        $this->expectException(PlanetaryInstallationAttributeMissingException::class);
        $this->expectExceptionMessage(sprintf('Attribute %s of planetary installation type %d', $attributeName, $typeId));

        $this->catalogOver($table)->build();
    }

    public function testALaunchpadWithoutCapacityRaisesAnErrorNamingTheTypeAndTheCapacity(): void
    {
        $table = self::planetaryTypeTable(withLowerCommandCenters: false);
        $table['types'][self::BARREN_LAUNCHPAD]['capacity'] = null;

        $this->expectException(PlanetaryInstallationAttributeMissingException::class);
        $this->expectExceptionMessage(sprintf('Attribute capacity of planetary installation type %d', self::BARREN_LAUNCHPAD));

        $this->catalogOver($table)->build();
    }

    public function testASpaceportThatIsNotNamedLikeALaunchpadRaisesAnErrorNamingIt(): void
    {
        $table = self::planetaryTypeTable();
        $table['types'][91_599] = ['name' => 'Barren Spaceport', 'group' => self::SPACEPORTS_GROUP_ID, 'capacity' => 10_000.0];
        $table['attributes'][91_599] = [self::CPU_LOAD => 3_600, self::POWER_LOAD => 700];

        $this->expectException(PlanetaryInstallationNameUnrecognizedException::class);
        $this->expectExceptionMessage('Barren Spaceport');

        $this->catalogOver($table)->build();
    }

    public function testAnExtractorWhosePlanetIsNotOneOfTheLaunchpadsRaisesAnErrorNamingIt(): void
    {
        $table = self::planetaryTypeTable();
        $table['types'][91_598] = ['name' => 'Zeta Heavy Metals Extractor', 'group' => self::EXTRACTORS_GROUP_ID, 'capacity' => null];
        $table['attributes'][91_598] = [self::HARVESTER_TYPE => 91_003];

        $this->expectException(PlanetaryInstallationNameUnrecognizedException::class);
        $this->expectExceptionMessage('Zeta Heavy Metals Extractor');

        $this->catalogOver($table)->build();
    }

    private function catalogOver(array $table): PlanetaryPlanetTypeCatalog
    {
        [$invTypeRepository, $attributeRepository] = $this->planetaryTypeRepositories($table);

        return new PlanetaryPlanetTypeCatalog($invTypeRepository, $attributeRepository);
    }
}
