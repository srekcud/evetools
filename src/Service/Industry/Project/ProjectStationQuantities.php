<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Repository\IndustryStructureConfigRepository;
use App\Service\Industry\Project\Transfer\InTransitByStation;
use App\Service\Industry\Project\Transfer\ProjectStationTransfers;
use App\Service\Industry\Project\Transfer\StationTransferReading;
use App\Service\Industry\Project\Transfer\TransferLegState;

final readonly class ProjectStationQuantities
{
    public function __construct(
        private ProjectJobMaterialNeedsLoader $needsLoader,
        private ProjectContainerReadings $readings,
        private ProjectContainerStations $stations,
        private IndustryStructureConfigRepository $structureConfigRepository,
        private StationQuantityCalculator $calculator,
        private ProjectStationTransfers $transfers,
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

        $readings = $this->readings->of($project);

        return $this->calculator->compute(
            $bomLines,
            $this->needsLoader->load($project, array_values(array_filter($bomLines, static fn (IndustryProjectBomLine $line): bool => $line->isJob()))),
            $readings,
            $locationIdByStructureId,
            $stationNameByLocationId,
            InTransitByStation::of(array_map(
                static fn (StationTransferReading $reading): TransferLegState => $reading->state,
                $this->transfers->of($project, $readings),
            )),
        );
    }
}
