<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Repository\IndustryStructureConfigRepository;

final readonly class ProjectStationQuantities
{
    public function __construct(
        private ProjectJobMaterialNeedsLoader $needsLoader,
        private ProjectContainerReadings $readings,
        private ProjectContainerStations $stations,
        private IndustryStructureConfigRepository $structureConfigRepository,
        private StationQuantityCalculator $calculator,
    ) {
    }

    /**
     * @return list<TypeStationQuantities>
     */
    public function of(IndustryProject $project): array
    {
        $bomLines = array_values($project->getBomItems()->toArray());

        $locationIdByStructureId = [];
        foreach ($this->structureConfigRepository->findByUser($project->getOwner()) as $structure) {
            $locationIdByStructureId[$structure->getId()->toRfc4122()] = $structure->getLocationId();
        }

        $stationNameByLocationId = [];
        foreach ($this->stations->of($project) as $station) {
            if (null !== $station->locationId) {
                $stationNameByLocationId[$station->locationId] = $station->structureName;
            }
        }

        return $this->calculator->compute(
            $bomLines,
            $this->needsLoader->load($project, array_values(array_filter($bomLines, static fn (IndustryProjectBomLine $line): bool => $line->isJob()))),
            $this->readings->of($project),
            $locationIdByStructureId,
            $stationNameByLocationId,
        );
    }
}
