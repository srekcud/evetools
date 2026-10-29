<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Repository\IndustryProjectRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use Doctrine\ORM\EntityManagerInterface;

class MultiUnitProjectStepsRegeneration
{
    public function __construct(
        private readonly IndustryProjectRepository $projectRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly IndustryProjectFactory $projectFactory,
        private readonly IndustryCalculationService $calculationService,
    ) {
    }

    /**
     * @return list<ProjectCorrectionOutcome>
     */
    public function run(bool $dryRun): array
    {
        return [];
    }
}
