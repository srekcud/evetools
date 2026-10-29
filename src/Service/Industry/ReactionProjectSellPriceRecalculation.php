<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Repository\IndustryProjectRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use Doctrine\ORM\EntityManagerInterface;

class ReactionProjectSellPriceRecalculation
{
    public function __construct(
        private readonly IndustryProjectRepository $projectRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly EstimatedSellPriceEstimator $sellPriceEstimator,
        private readonly IndustryCalculationService $calculationService,
    ) {
    }

    /**
     * @return list<ProjectCorrectionOutcome>
     */
    public function run(\DateTimeImmutable $createdBefore, bool $dryRun): array
    {
        return [];
    }
}
