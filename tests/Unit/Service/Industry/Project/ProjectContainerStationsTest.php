<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProjectContainer;
use App\Service\Industry\Project\ProjectContainerStation;
use App\Service\Industry\Project\ProjectContainerStations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectContainerStations::class)]
#[Group('issue-1292')]
final class ProjectContainerStationsTest extends TestCase
{
    use BuildsProjectContainerStations;

    private const int CONTAINER_NAME_COLUMN_LENGTH = 255;

    public function testWorkStationsGiveOnePlanStationPerStructureWithAProposedNameAndNoContainer(): void
    {
        $project = $this->ownerWithProject('Ishtar');
        $stationA = $this->structure('A', self::STATION_A_LOCATION_ID, self::JITA_SOLAR_SYSTEM_ID);
        $stationB = $this->structure('B', self::STATION_B_LOCATION_ID, self::AMARR_SOLAR_SYSTEM_ID);
        $project->setWorkStations(['final_products' => $this->workStation($stationA), 'components' => $this->workStation($stationB)]);

        $stations = $this->stationsOf([$stationA, $stationB])->of($project);

        self::assertSame([
            ['A', self::STATION_A_LOCATION_ID, self::JITA_SOLAR_SYSTEM_ID, null, 'Ishtar A'],
            ['B', self::STATION_B_LOCATION_ID, self::AMARR_SOLAR_SYSTEM_ID, null, 'Ishtar B'],
        ], $this->summarised($stations));
        self::assertSame($stationA->getId()->toRfc4122(), $this->stationNamed($stations, 'A')->structureId);
        self::assertNull($this->stationNamed($stations, 'A')->containerId);
    }

    public function testPlannedStructuresOfBomLinesAreListedOnceEach(): void
    {
        $project = $this->ownerWithProject('Ishtar');
        $stationA = $this->structure('A', self::STATION_A_LOCATION_ID, self::JITA_SOLAR_SYSTEM_ID);
        $stationB = $this->structure('B', self::STATION_B_LOCATION_ID, self::AMARR_SOLAR_SYSTEM_ID);
        $idA = $stationA->getId()->toRfc4122();
        $idB = $stationB->getId()->toRfc4122();

        $stations = $this->stationsOf([$stationA, $stationB], [$idA, $idA, $idB, $idA])->of($project);

        self::assertSame([
            ['A', self::STATION_A_LOCATION_ID, self::JITA_SOLAR_SYSTEM_ID, null, 'Ishtar A'],
            ['B', self::STATION_B_LOCATION_ID, self::AMARR_SOLAR_SYSTEM_ID, null, 'Ishtar B'],
        ], $this->summarised($stations));
    }

    public function testStructureOfWorkStationsAndOfBomLinesIsListedOnce(): void
    {
        $project = $this->ownerWithProject('Ishtar');
        $stationA = $this->structure('A', self::STATION_A_LOCATION_ID, self::JITA_SOLAR_SYSTEM_ID);
        $project->setWorkStations(['final_products' => $this->workStation($stationA)]);

        $stations = $this->stationsOf([$stationA], [$stationA->getId()->toRfc4122()])->of($project);

        self::assertCount(1, $stations);
    }

    public function testTwoStructuresAtTheSameLocationGiveOneStation(): void
    {
        $project = $this->ownerWithProject('Ishtar');
        $stationA = $this->structure('A', self::STATION_A_LOCATION_ID, self::JITA_SOLAR_SYSTEM_ID);
        $stationAnnex = $this->structure("A'", self::STATION_A_LOCATION_ID, self::JITA_SOLAR_SYSTEM_ID);
        $project->setWorkStations(['final_products' => $this->workStation($stationA), 'components' => $this->workStation($stationAnnex)]);

        $stations = $this->stationsOf([$stationA, $stationAnnex])->of($project);

        self::assertCount(1, $stations);
        self::assertSame(self::STATION_A_LOCATION_ID, $stations[0]->locationId);
    }

    public function testStructureWithoutLocationIsAnUnknownStationWithNoProposedName(): void
    {
        $project = $this->ownerWithProject('Ishtar');
        $stationC = $this->structure('C', null, self::AMARR_SOLAR_SYSTEM_ID);
        $project->setWorkStations(['components' => $this->workStation($stationC)]);

        $stations = $this->stationsOf([$stationC])->of($project);

        self::assertSame([['C', null, self::AMARR_SOLAR_SYSTEM_ID, null, null]], $this->summarised($stations));
    }

    public function testStructureUnknownToTheOwnerAndInvalidUuidsAreIgnored(): void
    {
        $project = $this->ownerWithProject('Ishtar');
        $stationA = $this->structure('A', self::STATION_A_LOCATION_ID, self::JITA_SOLAR_SYSTEM_ID);
        $foreignStructureId = Uuid::v7()->toRfc4122();
        $project->setWorkStations([
            'final_products' => $this->workStation($stationA),
            'components' => ['structureId' => $foreignStructureId, 'recommendedStructureId' => null],
            'reactions' => ['structureId' => 'abc', 'recommendedStructureId' => null],
        ]);

        $stations = $this->stationsOf([$stationA], [$foreignStructureId, 'abc'])->of($project);

        self::assertSame([['A', self::STATION_A_LOCATION_ID, self::JITA_SOLAR_SYSTEM_ID, null, 'Ishtar A']], $this->summarised($stations));
    }

    public function testProjectWithoutAnyPlanStructureHasNoStation(): void
    {
        $stationA = $this->structure('A', self::STATION_A_LOCATION_ID, self::JITA_SOLAR_SYSTEM_ID);

        self::assertSame([], $this->stationsOf([$stationA])->of($this->ownerWithProject('Ishtar')));
    }

    public function testStationExposesTheProjectContainerAtItsLocationAndProposesNoName(): void
    {
        $project = $this->ownerWithProject('Ishtar');
        $stationA = $this->structure('A', self::STATION_A_LOCATION_ID, self::JITA_SOLAR_SYSTEM_ID);
        $project->setWorkStations(['final_products' => $this->workStation($stationA)]);
        $container = (new IndustryProjectContainer($project, 'Ishtar A'))->locateAt(self::STATION_A_LOCATION_ID);
        $project->getContainers()->add($container);

        $stations = $this->stationsOf([$stationA])->of($project);

        self::assertSame([['A', self::STATION_A_LOCATION_ID, self::JITA_SOLAR_SYSTEM_ID, 'Ishtar A', null]], $this->summarised($stations));
        self::assertCount(1, $stations);
        self::assertSame($container->getId()->toRfc4122(), $stations[0]->containerId);
    }

    public function testProposedNameIsSuffixedWhenAContainerWithoutLocationAlreadyHasIt(): void
    {
        $project = $this->ownerWithProject('Ishtar');
        $stationA = $this->structure('A', self::STATION_A_LOCATION_ID, self::JITA_SOLAR_SYSTEM_ID);
        $project->setWorkStations(['final_products' => $this->workStation($stationA)]);
        $project->setContainerNames(['Ishtar A']);

        $stations = $this->stationsOf([$stationA])->of($project);

        self::assertSame([['A', self::STATION_A_LOCATION_ID, self::JITA_SOLAR_SYSTEM_ID, null, 'Ishtar A 2']], $this->summarised($stations));
    }

    public function testProposedNameMayReuseTheNameOfAContainerLocatedAtAnotherStation(): void
    {
        $project = $this->ownerWithProject('Ishtar');
        $stationA = $this->structure('A', self::STATION_A_LOCATION_ID, self::JITA_SOLAR_SYSTEM_ID);
        $stationB = $this->structure('B', self::STATION_B_LOCATION_ID, self::AMARR_SOLAR_SYSTEM_ID);
        $project->setWorkStations(['final_products' => $this->workStation($stationA), 'components' => $this->workStation($stationB)]);
        $project->getContainers()->add((new IndustryProjectContainer($project, 'Ishtar B'))->locateAt(self::STATION_A_LOCATION_ID));

        $stations = $this->stationsOf([$stationA, $stationB])->of($project);

        self::assertSame([['A', self::STATION_A_LOCATION_ID, self::JITA_SOLAR_SYSTEM_ID, 'Ishtar B', null], ['B', self::STATION_B_LOCATION_ID, self::AMARR_SOLAR_SYSTEM_ID, null, 'Ishtar B']], $this->summarised($stations));
    }

    public function testProposedNameSkipsEverySuffixAlreadyTakenIgnoringCase(): void
    {
        $project = $this->ownerWithProject('Ishtar');
        $stationA = $this->structure('A', self::STATION_A_LOCATION_ID, self::JITA_SOLAR_SYSTEM_ID);
        $project->setWorkStations(['final_products' => $this->workStation($stationA)]);
        $project->setContainerNames(['ishtar a', 'Ishtar A 2']);

        $stations = $this->stationsOf([$stationA])->of($project);

        self::assertCount(1, $stations);
        self::assertSame('Ishtar A 3', $stations[0]->proposedName);
    }

    public function testProposedNameIsTheStructureNameAloneForAProjectWithoutName(): void
    {
        $project = $this->ownerWithProject(null);
        $stationB = $this->structure('B', self::STATION_B_LOCATION_ID, self::AMARR_SOLAR_SYSTEM_ID);
        $project->setWorkStations(['components' => $this->workStation($stationB)]);

        $stations = $this->stationsOf([$stationB])->of($project);

        self::assertCount(1, $stations);
        self::assertSame('B', $stations[0]->proposedName);
    }

    public function testProposedNameIsTruncatedToTheContainerNameColumnLength(): void
    {
        $project = $this->ownerWithProject('Ishtar');
        $longStructureName = str_repeat('S', 300);
        $stationLong = $this->structure($longStructureName, self::STATION_A_LOCATION_ID, self::JITA_SOLAR_SYSTEM_ID);
        $project->setWorkStations(['final_products' => $this->workStation($stationLong)]);

        $stations = $this->stationsOf([$stationLong])->of($project);

        self::assertCount(1, $stations);
        self::assertSame(mb_substr('Ishtar '.$longStructureName, 0, self::CONTAINER_NAME_COLUMN_LENGTH), $stations[0]->proposedName);
        self::assertSame(self::CONTAINER_NAME_COLUMN_LENGTH, mb_strlen((string) $stations[0]->proposedName));
    }

    private function stationNamed(array $stations, string $structureName): ProjectContainerStation
    {
        foreach ($stations as $station) {
            if ($station->structureName === $structureName) {
                return $station;
            }
        }

        self::fail('No station named '.$structureName);
    }
}
