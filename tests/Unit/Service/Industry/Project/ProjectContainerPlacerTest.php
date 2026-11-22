<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectContainer;
use App\Entity\IndustryStructureConfig;
use App\Entity\PersonalStockContainer;
use App\Entity\User;
use App\Exception\ContainerNameTakenException;
use App\Exception\LocationNotInPlanException;
use App\Exception\StationAlreadyHasContainerException;
use App\Repository\PersonalStockContainerRepository;
use App\Service\Industry\Project\ProjectContainerPlacer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectContainerPlacer::class)]
#[Group('issue-1292')]
final class ProjectContainerPlacerTest extends TestCase
{
    use BuildsProjectContainerStations;

    private const int UNKNOWN_LOCATION_ID = 999;

    private IndustryProject $project;
    private array $personalStockContainers = [];
    private IndustryStructureConfig $stationA;
    private IndustryStructureConfig $stationB;

    protected function setUp(): void
    {
        $this->project = $this->ownerWithProject('Ishtar');
        $this->stationA = $this->structure('A', self::STATION_A_LOCATION_ID, self::JITA_SOLAR_SYSTEM_ID);
        $this->stationB = $this->structure('B', self::STATION_B_LOCATION_ID, self::AMARR_SOLAR_SYSTEM_ID);
        $this->project->setWorkStations(['final_products' => $this->workStation($this->stationA)]);
    }

    public function testCreatesTheContainerNamedAfterTheProposalWhenNoNameIsGiven(): void
    {
        $station = $this->placerWithStations([$this->stationA])->place($this->project, self::STATION_A_LOCATION_ID, null);

        self::assertSame([['Ishtar A', self::STATION_A_LOCATION_ID]], $this->containersOfProject());
        self::assertSame('Ishtar A', $station->containerName);
        self::assertSame($this->project->getContainers()->first()->getId()->toRfc4122(), $station->containerId);
        self::assertNull($station->proposedName);
    }

    public function testCreatesTheContainerNamedAfterTheProposalWhenTheNameIsBlank(): void
    {
        $this->placerWithStations([$this->stationA])->place($this->project, self::STATION_A_LOCATION_ID, '   ');

        self::assertSame([['Ishtar A', self::STATION_A_LOCATION_ID]], $this->containersOfProject());
    }

    public function testCreatesTheContainerWithTheGivenName(): void
    {
        $station = $this->placerWithStations([$this->stationA])->place($this->project, self::STATION_A_LOCATION_ID, 'Mats');

        self::assertSame([['Mats', self::STATION_A_LOCATION_ID]], $this->containersOfProject());
        self::assertSame('Mats', $station->containerName);
        self::assertSame('A', $station->structureName);
        self::assertSame(self::JITA_SOLAR_SYSTEM_ID, $station->solarSystemId);
    }

    public function testLocatesTheExistingContainerOfTheSameNameWithoutLocationInsteadOfDuplicatingIt(): void
    {
        $this->project->setContainerNames(['Mats']);
        $existingContainer = $this->project->getContainers()->first();

        $station = $this->placerWithStations([$this->stationA])->place($this->project, self::STATION_A_LOCATION_ID, 'mats');

        self::assertCount(1, $this->project->getContainers());
        self::assertSame($existingContainer, $this->project->getContainers()->first());
        self::assertSame([['Mats', self::STATION_A_LOCATION_ID]], $this->containersOfProject());
        self::assertSame($existingContainer->getId()->toRfc4122(), $station->containerId);
    }

    public function testRefusesAStationThatAlreadyHasAContainer(): void
    {
        $this->project->getContainers()->add((new IndustryProjectContainer($this->project, 'X'))->locateAt(self::STATION_A_LOCATION_ID));

        $this->expectException(StationAlreadyHasContainerException::class);

        try {
            $this->placerWithStations([$this->stationA])->place($this->project, self::STATION_A_LOCATION_ID, null);
        } finally {
            self::assertSame([['X', self::STATION_A_LOCATION_ID]], $this->containersOfProject());
        }
    }

    public function testCreatesASecondContainerWhenTheNameIsAlreadyUsedAtAnotherStation(): void
    {
        $this->project->setWorkStations([
            'final_products' => $this->workStation($this->stationA),
            'components' => $this->workStation($this->stationB),
        ]);
        $this->project->getContainers()->add((new IndustryProjectContainer($this->project, 'Mats'))->locateAt(self::STATION_B_LOCATION_ID));

        $station = $this->placerWithStations([$this->stationA, $this->stationB])->place($this->project, self::STATION_A_LOCATION_ID, 'mats');

        self::assertSame([['Mats', self::STATION_B_LOCATION_ID], ['mats', self::STATION_A_LOCATION_ID]], $this->containersOfProject());
        self::assertSame('mats', $station->containerName);
        self::assertSame($this->project->getContainers()->last()->getId()->toRfc4122(), $station->containerId);
    }

    public function testStillRefusesAStationThatHasAContainerNamedLikeTheGivenName(): void
    {
        $this->project->getContainers()->add((new IndustryProjectContainer($this->project, 'Mats'))->locateAt(self::STATION_A_LOCATION_ID));

        $this->expectException(StationAlreadyHasContainerException::class);

        try {
            $this->placerWithStations([$this->stationA])->place($this->project, self::STATION_A_LOCATION_ID, 'Mats');
        } finally {
            self::assertSame([['Mats', self::STATION_A_LOCATION_ID]], $this->containersOfProject());
        }
    }

    public function testRefusesANameAlreadyTakenByAPersonalStockContainerOfTheOwner(): void
    {
        $this->personalStockContainers = [new PersonalStockContainer($this->project->getOwner(), 1_132_400_000_001, 'Mats', 'Division 2', self::STATION_A_LOCATION_ID, 'A')];

        $this->expectException(ContainerNameTakenException::class);

        try {
            $this->placerWithStations([$this->stationA])->place($this->project, self::STATION_A_LOCATION_ID, ' MATS ');
        } finally {
            self::assertSame([], $this->containersOfProject());
        }
    }

    public function testRefusesTheProposedNameWhenAPersonalStockContainerOfTheOwnerHasIt(): void
    {
        $this->personalStockContainers = [new PersonalStockContainer($this->project->getOwner(), 1_132_400_000_002, 'Ishtar A', 'Division 2', self::STATION_A_LOCATION_ID, 'A')];

        $this->expectException(ContainerNameTakenException::class);

        try {
            $this->placerWithStations([$this->stationA])->place($this->project, self::STATION_A_LOCATION_ID, null);
        } finally {
            self::assertSame([], $this->containersOfProject());
        }
    }

    public function testCreatesTheContainerWhenThePersonalStockContainersOfTheOwnerHaveOtherNames(): void
    {
        $this->personalStockContainers = [new PersonalStockContainer($this->project->getOwner(), 1_132_400_000_003, 'Perso A', 'Division 2', self::STATION_A_LOCATION_ID, 'A')];

        $this->placerWithStations([$this->stationA])->place($this->project, self::STATION_A_LOCATION_ID, 'Mats');

        self::assertSame([['Mats', self::STATION_A_LOCATION_ID]], $this->containersOfProject());
    }

    public function testRefusesALocationThatIsNotAStationOfThePlan(): void
    {
        $this->expectException(LocationNotInPlanException::class);

        try {
            $this->placerWithStations([$this->stationA])->place($this->project, self::UNKNOWN_LOCATION_ID, null);
        } finally {
            self::assertSame([], $this->containersOfProject());
        }
    }

    public function testRefusesALocationOfAStationOutsideThePlan(): void
    {
        $this->expectException(LocationNotInPlanException::class);

        $this->placerWithStations([$this->stationA, $this->stationB])->place($this->project, self::STATION_B_LOCATION_ID, null);
    }

    private function placerWithStations(array $ownerStructures): ProjectContainerPlacer
    {
        $personalContainers = $this->createStub(PersonalStockContainerRepository::class);
        $personalContainers->method('findByUser')->willReturnCallback(
            fn (User $user): array => $user === $this->project->getOwner() ? $this->personalStockContainers : [],
        );

        return new ProjectContainerPlacer($this->stationsOf($ownerStructures), $personalContainers);
    }

    private function containersOfProject(): array
    {
        return array_map(
            static fn (IndustryProjectContainer $container): array => [$container->getName(), $container->getLocationId()],
            $this->project->getContainers()->toArray(),
        );
    }
}
