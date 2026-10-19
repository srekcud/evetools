<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;

final class SplitGroupMaterialNeeds
{
    public function __construct(
        private readonly IndustryCalculationService $calculationService,
        private readonly IndustryActivityMaterialRepository $materialRepository,
        private readonly IndustryActivityProductRepository $productRepository,
    ) {
    }

    /**
     * @param list<int> $jobRunsBefore
     * @param list<int> $jobRunsAfter
     * @return list<IndustryProjectStep>
     */
    public function applyChangeOfJobs(IndustryProject $project, IndustryProjectStep $consumer, array $jobRunsBefore, array $jobRunsAfter): array
    {
        return [];
    }
}
