<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Transfer;

use App\Entity\IndustryProject;
use App\Industry\Application\ItemVolumes;
use App\Repository\IndustryProjectStationTransferRepository;
use App\Service\Industry\Project\ProjectContainerReading;

final readonly class ProjectStationTransfers
{
    public function __construct(
        private IndustryProjectStationTransferRepository $transferRepository,
        private TransferLegStateCalculator $calculator,
        private ItemVolumes $itemVolumes,
    ) {
    }

    /**
     * @param list<ProjectContainerReading> $readings
     *
     * @return list<StationTransferReading>
     */
    public function of(IndustryProject $project, array $readings): array
    {
        $legs = $this->transferRepository->findByProject($project);
        if ([] === $legs) {
            return [];
        }

        $readingsByLocationId = [];
        foreach ($readings as $reading) {
            if (null !== $reading->locationId) {
                $readingsByLocationId[$reading->locationId] = $reading;
            }
        }

        $typeNameByTypeId = [];
        foreach ($project->getBomItems() as $bomLine) {
            $typeNameByTypeId[$bomLine->getTypeId()] = $bomLine->getTypeName();
        }

        $volumeByTypeId = [];
        $legCountByRouteId = [];
        foreach ($legs as $leg) {
            $legCountByRouteId[$leg->getRouteId()->toRfc4122()] = ($legCountByRouteId[$leg->getRouteId()->toRfc4122()] ?? 0) + 1;
            foreach ($leg->getLines() as $line) {
                $volumeByTypeId[$line->typeId] ??= $this->itemVolumes->volumeOf($line->typeId);
            }
        }

        $stateByRouteIdAndLegIndex = [];
        $readingsOfLegs = [];
        foreach ($legs as $leg) {
            $routeId = $leg->getRouteId()->toRfc4122();
            $state = $this->calculator->compute(
                $leg,
                $readingsByLocationId,
                $stateByRouteIdAndLegIndex[$routeId][$leg->getLegIndex() - 1] ?? null,
                $volumeByTypeId,
                $typeNameByTypeId,
            );
            $stateByRouteIdAndLegIndex[$routeId][$leg->getLegIndex()] = $state;
            $readingsOfLegs[] = new StationTransferReading($leg, $legCountByRouteId[$routeId], $state);
        }

        return $readingsOfLegs;
    }
}
