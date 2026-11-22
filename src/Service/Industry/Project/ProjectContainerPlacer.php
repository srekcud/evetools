<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectContainer;
use App\Entity\PersonalStockContainer;
use App\Exception\ContainerNameTakenException;
use App\Exception\LocationNotInPlanException;
use App\Exception\StationAlreadyHasContainerException;
use App\Repository\PersonalStockContainerRepository;

final readonly class ProjectContainerPlacer
{
    public function __construct(
        private ProjectContainerStations $stations,
        private PersonalStockContainerRepository $personalContainers,
    ) {
    }

    public function place(IndustryProject $project, int $locationId, ?string $name): ProjectContainerStation
    {
        $station = $this->stationAt($project, $locationId);
        if (null !== $station->containerId) {
            throw new StationAlreadyHasContainerException('The station already has a container');
        }

        $containerName = trim($name ?? '');
        if ('' === $containerName) {
            $containerName = $station->proposedName ?? throw new \LogicException('A station without container has no proposed name.');
        }

        if ($this->isNamedLikeAPersonalStockContainer($project, $containerName)) {
            throw new ContainerNameTakenException('A personal stock container has this name');
        }

        $unlocatedSameNameContainer = $this->unlocatedContainerNamed($project, $containerName);
        if (null === $unlocatedSameNameContainer) {
            $project->addContainerAt($containerName, $locationId);
        } else {
            $unlocatedSameNameContainer->locateAt($locationId);
        }

        return $this->stationAt($project, $locationId);
    }

    private function stationAt(IndustryProject $project, int $locationId): ProjectContainerStation
    {
        foreach ($this->stations->of($project) as $station) {
            if ($station->locationId === $locationId) {
                return $station;
            }
        }

        throw new LocationNotInPlanException('Location is not a station of the plan');
    }

    private function isNamedLikeAPersonalStockContainer(IndustryProject $project, string $containerName): bool
    {
        $key = IndustryProjectContainer::keyOf($containerName);

        return array_any(
            $this->personalContainers->findByUser($project->getOwner()),
            static fn (PersonalStockContainer $container): bool => IndustryProjectContainer::keyOf($container->getName()) === $key,
        );
    }

    private function unlocatedContainerNamed(IndustryProject $project, string $containerName): ?IndustryProjectContainer
    {
        $key = IndustryProjectContainer::keyOf($containerName);
        foreach ($project->getContainers() as $container) {
            if (null === $container->getLocationId() && IndustryProjectContainer::keyOf($container->getName()) === $key) {
                return $container;
            }
        }

        return null;
    }
}
