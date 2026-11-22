<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectContainer;
use App\Entity\IndustryStructureConfig;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryStructureConfigRepository;

final readonly class ProjectContainerStations
{
    private const int CONTAINER_NAME_MAXIMUM_LENGTH = 255;
    private const int FIRST_NAME_SUFFIX = 2;

    public function __construct(
        private IndustryStructureConfigRepository $structureConfigRepository,
        private IndustryProjectBomLineRepository $bomLineRepository,
    ) {
    }

    /**
     * @return list<ProjectContainerStation>
     */
    public function of(IndustryProject $project): array
    {
        $ownerStructuresById = [];
        foreach ($this->structureConfigRepository->findByUser($project->getOwner()) as $structure) {
            $ownerStructuresById[$structure->getId()->toRfc4122()] = $structure;
        }

        $containersByLocationId = [];
        $takenNameKeys = [];
        foreach ($project->getContainers() as $container) {
            $takenNameKeys[IndustryProjectContainer::keyOf($container->getName())] = true;
            if (null !== $container->getLocationId()) {
                $containersByLocationId[$container->getLocationId()] = $container;
            }
        }

        $stations = [];
        foreach ($this->planStructuresOf($project, $ownerStructuresById) as $structure) {
            $stationKey = $structure->getLocationId() ?? $structure->getId()->toRfc4122();
            if (isset($stations[$stationKey])) {
                continue;
            }

            $locationId = $structure->getLocationId();
            $container = null === $locationId ? null : ($containersByLocationId[$locationId] ?? null);
            $stations[$stationKey] = new ProjectContainerStation(
                $structure->getId()->toRfc4122(),
                $structure->getName(),
                $locationId,
                $structure->getSolarSystemId(),
                $container?->getId()->toRfc4122(),
                $container?->getName(),
                null === $locationId || null !== $container ? null : $this->proposedNameOf($project, $structure, $takenNameKeys),
            );
        }

        return array_values($stations);
    }

    /**
     * @param array<string, IndustryStructureConfig> $ownerStructuresById
     *
     * @return list<IndustryStructureConfig>
     */
    private function planStructuresOf(IndustryProject $project, array $ownerStructuresById): array
    {
        $structureIds = array_column($project->getWorkStations(), 'structureId');
        foreach ($this->bomLineRepository->findPlannedStructureIdsByProject($project) as $plannedStructureId) {
            $structureIds[] = $plannedStructureId;
        }

        $structures = [];
        foreach ($structureIds as $structureId) {
            if (isset($ownerStructuresById[$structureId])) {
                $structures[] = $ownerStructuresById[$structureId];
            }
        }

        return $structures;
    }

    /**
     * @param array<string, true> $takenNameKeys
     */
    private function proposedNameOf(IndustryProject $project, IndustryStructureConfig $structure, array $takenNameKeys): string
    {
        $projectName = trim($project->getName() ?? '');
        $baseName = '' === $projectName ? $structure->getName() : $projectName.' '.$structure->getName();

        $proposedName = mb_substr($baseName, 0, self::CONTAINER_NAME_MAXIMUM_LENGTH);
        for ($suffix = self::FIRST_NAME_SUFFIX; isset($takenNameKeys[IndustryProjectContainer::keyOf($proposedName)]); ++$suffix) {
            $suffixText = ' '.$suffix;
            $proposedName = mb_substr($baseName, 0, self::CONTAINER_NAME_MAXIMUM_LENGTH - mb_strlen($suffixText)).$suffixText;
        }

        return $proposedName;
    }
}
