<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;

class ProjectContainerFulfillment
{
    public function __construct(
        private readonly ProjectContainerService $containerService,
    ) {
    }

    public function of(IndustryProject $project): ProjectLineFulfillments
    {
        $stock = $this->containerService->containerStockOf($project);

        if (null === $stock->unknownReason) {
            return new ProjectLineFulfillments(ContainerStockState::Known, $stock->quantitiesByTypeId);
        }

        if (ProjectContainerService::REASON_NO_CONTAINER_NAME === $stock->unknownReason) {
            return ProjectLineFulfillments::none();
        }

        return new ProjectLineFulfillments(ContainerStockState::Unknown);
    }
}
