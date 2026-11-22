<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Service\Industry\Project\ProjectContainerStation;
use App\Service\Industry\Project\ProjectContainerStations;

trait BuildsProjectContainerStations
{
    private const int JITA_SOLAR_SYSTEM_ID = 30_000_142;
    private const int AMARR_SOLAR_SYSTEM_ID = 30_002_187;
    private const int STATION_A_LOCATION_ID = 100;
    private const int STATION_B_LOCATION_ID = 200;

    private function ownerWithProject(?string $projectName): IndustryProject
    {
        return (new IndustryProject())->setOwner(new User())->setName($projectName);
    }

    private function structure(string $name, ?int $locationId, ?int $solarSystemId): IndustryStructureConfig
    {
        return (new IndustryStructureConfig())->setName($name)->setLocationId($locationId)->setSolarSystemId($solarSystemId);
    }

    private function stationsOf(array $ownerStructures, array $plannedStructureIds = []): ProjectContainerStations
    {
        $structureRepository = $this->createStub(IndustryStructureConfigRepository::class);
        $structureRepository->method('findByUser')->willReturn($ownerStructures);

        $bomLineRepository = new class($plannedStructureIds) extends IndustryProjectBomLineRepository {
            public function __construct(private array $plannedStructureIds)
            {
            }

            public function findPlannedStructureIdsByProject(IndustryProject $project): array
            {
                return $this->plannedStructureIds;
            }
        };

        return new ProjectContainerStations($structureRepository, $bomLineRepository);
    }

    private function workStation(IndustryStructureConfig $structure): array
    {
        return ['structureId' => $structure->getId()->toRfc4122(), 'recommendedStructureId' => null];
    }

    private function summarised(array $stations): array
    {
        usort($stations, static fn (ProjectContainerStation $left, ProjectContainerStation $right): int => $left->structureName <=> $right->structureName);

        return array_map(
            static fn (ProjectContainerStation $station): array => [$station->structureName, $station->locationId, $station->solarSystemId, $station->containerName, $station->proposedName],
            $stations,
        );
    }
}
