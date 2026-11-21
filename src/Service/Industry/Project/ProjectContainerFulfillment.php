<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;

class ProjectContainerFulfillment
{
    private const array JOB_GROUPS_CONSUMING_MATERIALS = ['component', 'final'];

    public function __construct(
        private readonly ProjectContainerService $containerService,
        private readonly StartedJobConsumptionReader $consumptionReader,
    ) {
    }

    /**
     * @param array<IndustryProjectBomLine> $bomLines
     * @param array<string, array<int, int>>|null $jobMaterialNeeds
     */
    public function ofWithJobMaterialNeeds(IndustryProject $project, array $bomLines, ?array $jobMaterialNeeds): ProjectLineFulfillments
    {
        $jobLines = array_filter(
            $bomLines,
            static fn (IndustryProjectBomLine $line): bool => $line->isJob() && \in_array($line->getJobGroup(), self::JOB_GROUPS_CONSUMING_MATERIALS, true),
        );

        return $this->fulfillmentsOf($project, static fn (): StartedJobConsumption => StartedJobConsumption::of($jobLines, $jobMaterialNeeds));
    }

    public function of(IndustryProject $project): ProjectLineFulfillments
    {
        return $this->fulfillmentsOf($project, fn (): StartedJobConsumption => $this->consumptionReader->of($project));
    }

    /**
     * @param \Closure(): StartedJobConsumption $consumptionOfStartedJobs
     */
    private function fulfillmentsOf(IndustryProject $project, \Closure $consumptionOfStartedJobs): ProjectLineFulfillments
    {
        $stock = $this->containerService->containerStockOf($project);

        if (null !== $stock->unknownReason) {
            return ProjectContainerService::REASON_NO_CONTAINER_NAME === $stock->unknownReason
                ? ProjectLineFulfillments::none()
                : new ProjectLineFulfillments(ContainerStockState::Unknown);
        }

        $consumption = $consumptionOfStartedJobs();
        if ($consumption->isUnknown()) {
            return new ProjectLineFulfillments(ContainerStockState::Unknown);
        }

        return new ProjectLineFulfillments(ContainerStockState::Known, $stock->quantitiesByTypeId, $consumption->quantitiesByTypeId());
    }
}
