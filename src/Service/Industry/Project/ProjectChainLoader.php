<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectSaleRepository;
use App\Repository\IndustryStructureConfigRepository;

class ProjectChainLoader
{
    public function __construct(
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
        private readonly IndustryProjectContributionRepository $contributionRepository,
        private readonly IndustryProjectSaleRepository $saleRepository,
        private readonly ProjectChainBuilder $chainBuilder,
        private readonly ProjectJobMaterialNeedsLoader $jobMaterialNeedsLoader,
        private readonly ProjectContainerFulfillment $containerFulfillment,
    ) {
    }

    /**
     * @param list<IndustryProjectBomLine> $bomLines
     */
    public function load(IndustryProject $project, array $bomLines): ProjectChain
    {
        $structureNamesById = [];
        foreach ($this->structureConfigRepository->findByUser($project->getOwner()) as $structureConfig) {
            $structureNamesById[$structureConfig->getId()->toRfc4122()] = $structureConfig->getName();
        }

        $jobMaterialNeeds = $this->jobMaterialNeedsLoader->load($project, $bomLines);

        return $this->chainBuilder->build(
            $project,
            $bomLines,
            $this->contributionRepository->findApprovedByProject($project),
            $this->saleRepository->findByProjectForListing($project),
            $structureNamesById,
            $jobMaterialNeeds,
            $this->containerFulfillment->ofWithJobMaterialNeeds($project, $bomLines, $jobMaterialNeeds),
        );
    }
}
